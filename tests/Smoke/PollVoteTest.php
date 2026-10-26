<?php

use PHPUnit\Framework\TestCase;

/**
 * Дым на живой БД: голосование в быстрых опросах (nj520_poll).
 *
 * Запуск: php tests/mini_runner.php PollVote
 *
 * Главное, что здесь проверяется, — цена ошибки видна сразу: один голос
 * считается с одного БРАУЗЕРА, а не с одного IP (решение владельца
 * 04.10.2026). Раньше уникальный индекс стоял по паре «опрос + IP», и целый
 * офис за одним NAT голосовал один раз на всех. Проверять это надо на
 * настоящей базе: защита держится уникальным индексом, а не кодом на PHP.
 */
class PollVoteTest extends TestCase
{
    /** @var array коды созданных опросов — для уборки */
    private $codes = [];

    protected function tearDown(): void
    {
        $db = Database::instance();

        foreach ($this->codes as $code)
        {
            // Варианты и голоса уходят каскадом (FK ON DELETE CASCADE).
            $db->query(Database::DELETE,
                'DELETE FROM nj_520_polls WHERE code = '.$db->quote($code), FALSE);
        }

        $this->codes = [];
    }

    /** Опрос, созданный обычным путём страницы /poll. */
    private function make_poll($title, array $options)
    {
        $code = Service_Nj520_Poll::create($title, $options);

        $this->assertNotNull($code, 'Опрос должен создаться');
        $this->codes[] = $code;

        return $code;
    }

    private function poll_id($code)
    {
        $poll = Service_Nj520_Poll::by_code($code);

        $this->assertNotNull($poll, 'Опрос ищется по коду');

        return (int) $poll['id'];
    }

    public function test_poll_is_created_with_its_options_in_order()
    {
        $code = $this->make_poll('Куда идём в пятницу?', ['Бар', 'Кино', 'Дома']);

        $this->assertSame(6, strlen($code), 'Код — шесть знаков');

        $results = Service_Nj520_Poll::results($code);

        $this->assertSame('Куда идём в пятницу?', $results['poll']['title']);
        $this->assertSame(['Бар', 'Кино', 'Дома'], array_column($results['options'], 'label'),
            'Варианты показываются в том порядке, в каком их ввели');
        $this->assertSame(0, $results['total']);
        $this->assertSame(0, $results['leader'], 'Без голосов лидера нет');
        $this->assertNull(Service_Nj520_Poll::results('нет-такого-кода'));
    }

    public function test_two_browsers_behind_one_ip_both_get_a_vote()
    {
        $code = $this->make_poll('Кофе или чай?', ['Кофе', 'Чай']);
        $id = $this->poll_id($code);
        $options = Service_Nj520_Poll::results($code)['options'];

        // Два разных браузера (один офис, один внешний адрес): ключ у каждого
        // свой — голосуют оба. Ровно это и не работало до 004-й миграции.
        $browser_a = 'g:'.str_repeat('a', 32);
        $browser_b = 'g:'.str_repeat('b', 32);

        $this->assertSame('ok', Service_Nj520_Poll::vote($id, (int) $options[0]['id'], $browser_a));
        $this->assertSame('ok', Service_Nj520_Poll::vote($id, (int) $options[1]['id'], $browser_b));

        // А вот второй голос из того же браузера не проходит — даже за другой вариант.
        $this->assertSame('already', Service_Nj520_Poll::vote($id, (int) $options[1]['id'], $browser_a),
            'Один браузер — один голос');

        $results = Service_Nj520_Poll::results($code);

        $this->assertSame(2, $results['total']);
        $this->assertSame(50.0, $results['options'][0]['percent']);
        $this->assertSame(0, $results['leader'], 'При равенстве никто не «лидирует»');

        // Голос виден своему браузеру и не виден чужому.
        $this->assertSame((int) $options[0]['id'], Service_Nj520_Poll::my_vote($id, $browser_a));
        $this->assertTrue(Service_Nj520_Poll::has_voted($id, $browser_b));
        $this->assertFalse(Service_Nj520_Poll::has_voted($id, 'g:'.str_repeat('c', 32)));
    }

    public function test_leader_appears_when_someone_is_ahead()
    {
        $code = $this->make_poll('Кофе или чай?', ['Кофе', 'Чай']);
        $id = $this->poll_id($code);
        $options = Service_Nj520_Poll::results($code)['options'];

        foreach (['x1', 'x2', 'x3'] as $i => $key)
        {
            $this->assertSame('ok', Service_Nj520_Poll::vote($id, (int) $options[0]['id'], 'g:'.$key));
        }

        Service_Nj520_Poll::vote($id, (int) $options[1]['id'], 'g:y1');

        $results = Service_Nj520_Poll::results($code);

        $this->assertSame(4, $results['total']);
        $this->assertSame((int) $options[0]['id'], $results['leader']);
        $this->assertSame(75.0, $results['options'][0]['percent']);
        $this->assertSame(25.0, $results['options'][1]['percent']);
    }

    public function test_vote_for_a_stranger_option_is_refused()
    {
        $code = $this->make_poll('Кофе или чай?', ['Кофе', 'Чай']);
        $other = $this->make_poll('Море или горы?', ['Море', 'Горы']);

        $id = $this->poll_id($code);
        $alien = (int) Service_Nj520_Poll::results($other)['options'][0]['id'];

        $this->assertSame('bad_option', Service_Nj520_Poll::vote($id, $alien, 'g:z1'),
            'Вариант чужого опроса в этот опрос не засчитывается');
        $this->assertSame('bad_option', Service_Nj520_Poll::vote($id, 0, 'g:z1'));
        $this->assertSame(0, Service_Nj520_Poll::results($code)['total']);
    }

    public function test_closed_poll_keeps_results_but_takes_no_votes()
    {
        $code = $this->make_poll('Кофе или чай?', ['Кофе', 'Чай']);
        $id = $this->poll_id($code);
        $options = Service_Nj520_Poll::results($code)['options'];

        Service_Nj520_Poll::vote($id, (int) $options[0]['id'], 'g:before');
        Service_Nj520_Poll::admin_set_closed($id, TRUE);

        $this->assertSame('closed', Service_Nj520_Poll::vote($id, (int) $options[1]['id'], 'g:after'));

        $results = Service_Nj520_Poll::results($code);

        $this->assertSame(1, $results['total'], 'Итоги закрытого опроса остаются на месте');
        $this->assertNotEmpty($results['poll']['closed_at']);

        // Закрытый опрос пропадает из списка «другие активные».
        $active = array_column(Service_Nj520_Poll::recent_active(50), 'code');

        $this->assertNotContains($code, $active);

        // И открывается обратно, если закрыли по ошибке.
        Service_Nj520_Poll::admin_set_closed($id, FALSE);

        $this->assertSame('ok', Service_Nj520_Poll::vote($id, (int) $options[1]['id'], 'g:after'));
    }

    public function test_results_open_only_after_your_own_vote()
    {
        $code = $this->make_poll('Кофе или чай?', ['Кофе', 'Чай']);
        $id = $this->poll_id($code);
        $options = Service_Nj520_Poll::results($code)['options'];

        Service_Nj520_Poll::vote($id, (int) $options[0]['id'], 'g:voted-already');

        $poll = Service_Nj520_Poll::by_code($code);

        // Зашедший впервые видит, что голоса идут, но не куда именно.
        $this->assertFalse(Service_Nj520_Poll::results_visible($poll,
            Service_Nj520_Poll::has_voted($id, 'g:newcomer')));

        // Тот, кто проголосовал, видит всё.
        $this->assertTrue(Service_Nj520_Poll::results_visible($poll,
            Service_Nj520_Poll::has_voted($id, 'g:voted-already')));

        // Закрыли — итоги открыты всем, даже тем, кто не голосовал.
        Service_Nj520_Poll::admin_set_closed($id, TRUE);

        $this->assertTrue(Service_Nj520_Poll::results_visible(Service_Nj520_Poll::by_code($code),
            Service_Nj520_Poll::has_voted($id, 'g:newcomer')));
    }

    public function test_recent_active_skips_the_poll_you_are_looking_at()
    {
        $code = $this->make_poll('Кофе или чай?', ['Кофе', 'Чай']);
        $neighbour = $this->make_poll('Море или горы?', ['Море', 'Горы']);

        $codes = array_column(Service_Nj520_Poll::recent_active(50, $code), 'code');

        $this->assertNotContains($code, $codes, 'Текущий опрос не предлагают «другим»');
        $this->assertContains($neighbour, $codes);
    }

    public function test_admin_sees_who_voted_without_network_addresses()
    {
        $code = $this->make_poll('Кофе или чай?', ['Кофе', 'Чай']);
        $id = $this->poll_id($code);
        $options = Service_Nj520_Poll::results($code)['options'];

        Service_Nj520_Poll::vote($id, (int) $options[0]['id'], 'g:'.str_repeat('f', 32));
        Service_Nj520_Poll::vote($id, (int) $options[1]['id'], 'u:7');

        $detail = Service_Nj520_Poll::admin_poll($code);

        $this->assertSame(2, $detail['total']);
        $this->assertCount(2, $detail['voters']);

        $who = array_column($detail['voters'], 'who');

        $this->assertContains('пользователь #7', $who);
        $this->assertContains('браузер ffffff', $who);

        // Адрес сети новым голосам не пишется вовсе.
        foreach ($detail['voters'] as $voter)
        {
            $this->assertSame('', (string) $voter['voter_ip'], 'IP новых голосов не хранится');
        }

        // Удаление уносит голоса и варианты (каскад).
        Service_Nj520_Poll::admin_delete($id);

        $this->assertNull(Service_Nj520_Poll::by_code($code));

        $left = Database::instance()->query(Database::SELECT,
            'SELECT COUNT(*) AS c FROM nj_520_votes WHERE poll_id = '.$id, FALSE)->current();

        $this->assertSame(0, (int) $left['c']);
    }
}
