<?php

use PHPUnit\Framework\TestCase;

/**
 * Чистая логика быстрых опросов (nj520_poll) — без базы.
 *
 * Запуск: php tests/mini_runner.php PollService
 *
 * Модуль почти целиком состоял из SQL, и проверять в нём было нечего: вся
 * «арифметика» (склонения, проценты, разбор введённых вариантов) жила прямо
 * в вёрстке тремя разными копиями. Эти функции вынесены в сервис — и здесь
 * закреплено их поведение.
 */
class PollServiceTest extends TestCase
{
    /* ---------------------- Разбор того, что ввёл человек ---------------------- */

    public function test_normalize_keeps_question_and_options()
    {
        $norm = Service_Nj520_Poll::normalize('  Куда идём в пятницу?  ', ['Бар', ' Кино ', 'Дома']);

        $this->assertSame('Куда идём в пятницу?', $norm['title'], 'Пробелы по краям срезаются');
        $this->assertSame(['Бар', 'Кино', 'Дома'], $norm['options']);
    }

    public function test_normalize_drops_blank_lines_and_repeats()
    {
        // Человек вставляет список из мессенджера: пустые строки и «Кино»
        // дважды в разном регистре — это один вариант, а не три.
        $norm = Service_Nj520_Poll::normalize('Куда?', ['Бар', '', '  ', 'Кино', 'КИНО', 'кино ']);

        $this->assertSame(['Бар', 'Кино'], $norm['options']);
    }

    public function test_normalize_refuses_when_there_is_nothing_to_choose()
    {
        $this->assertNull(Service_Nj520_Poll::normalize('Куда?', ['Бар']),
            'Один вариант — это не опрос');
        $this->assertNull(Service_Nj520_Poll::normalize('Куда?', ['Бар', 'бар']),
            'Повтор не делает из одного варианта два');
        $this->assertNull(Service_Nj520_Poll::normalize('', ['Бар', 'Кино']),
            'Без вопроса опрос не создаётся');
        $this->assertNull(Service_Nj520_Poll::normalize('   ', ['Бар', 'Кино']),
            'Пробелы вопросом не считаются');
    }

    public function test_normalize_holds_the_limits()
    {
        $eleven = [];

        for ($i = 1; $i <= 11; $i++)
        {
            $eleven[] = 'Вариант '.$i;
        }

        $this->assertNull(Service_Nj520_Poll::normalize('Куда?', $eleven),
            'Больше десяти вариантов не принимаем');
        $this->assertNotNull(Service_Nj520_Poll::normalize('Куда?', array_slice($eleven, 0, 10)));

        $this->assertNull(Service_Nj520_Poll::normalize(str_repeat('а', 301), ['Бар', 'Кино']),
            'Вопрос длиннее колонки в базе');

        // Слишком длинный вариант не отменяет опрос — он обрезается до колонки.
        $norm = Service_Nj520_Poll::normalize('Куда?', [str_repeat('б', 250), 'Кино']);

        $this->assertSame(200, mb_strlen($norm['options'][0]));
    }

    /* ------------------------------ Склонения ------------------------------ */

    public function test_votes_are_counted_in_russian()
    {
        $cases = [0 => 'голосов', 1 => 'голос', 2 => 'голоса', 4 => 'голоса', 5 => 'голосов',
                  11 => 'голосов', 14 => 'голосов', 21 => 'голос', 102 => 'голоса', 111 => 'голосов'];

        foreach ($cases as $n => $word)
        {
            $this->assertSame($word, Service_Nj520_Poll::votes_word($n), 'Число '.$n);
        }

        $this->assertSame('вариант', Service_Nj520_Poll::options_word(1));
        $this->assertSame('варианта', Service_Nj520_Poll::options_word(3));
        $this->assertSame('вариантов', Service_Nj520_Poll::options_word(12));
    }

    /* ------------------------------- Проценты ------------------------------- */

    public function test_percent_is_a_share_of_the_votes_given()
    {
        $this->assertSame(50.0, Service_Nj520_Poll::percent(1, 2));
        $this->assertSame(33.3, Service_Nj520_Poll::percent(1, 3), 'Одна десятая, как на странице');
        $this->assertSame(100.0, Service_Nj520_Poll::percent(7, 7));
    }

    public function test_percent_without_votes_is_zero_not_a_division()
    {
        // Полоска «ещё никто не голосовал» обязана быть пустой, а не ломать
        // страницу делением на ноль.
        $this->assertSame(0.0, Service_Nj520_Poll::percent(0, 0));
        $this->assertSame(0.0, Service_Nj520_Poll::percent(5, 0));
    }

    /* --------------------------- Кто голосовал --------------------------- */

    public function test_voter_label_tells_who_voted_without_exposing_them()
    {
        $this->assertSame('пользователь #12', Service_Nj520_Poll::voter_label('u:12'));
        $this->assertSame('IP 10.0.0.1', Service_Nj520_Poll::voter_label('ip:10.0.0.1'),
            'Старые голоса (до перехода на браузер) остаются подписаны адресом');
        $this->assertSame('—', Service_Nj520_Poll::voter_label(''));

        // Из подписи анонима нельзя восстановить его идентификатор целиком:
        // в списке видно только начало.
        $ubid  = str_repeat('a', 32);
        $label = Service_Nj520_Poll::voter_label('g:'.$ubid);

        $this->assertSame('браузер aaaaaa', $label);
        $this->assertStringNotContainsString($ubid, $label);
    }

    /* --------------------- Когда показывать результаты --------------------- */

    public function test_results_are_hidden_until_the_person_has_voted()
    {
        $open = ['closed_at' => NULL];

        $this->assertFalse(Service_Nj520_Poll::results_visible($open, FALSE),
            'Пока человек не проголосовал, чужой выбор ему не показываем');
        $this->assertTrue(Service_Nj520_Poll::results_visible($open, TRUE),
            'Проголосовал — сразу видит результаты');
    }

    public function test_closed_poll_shows_results_to_everyone()
    {
        // Ссылку на завершённый опрос пересылают именно ради итогов, а
        // голосовать там уже нечем — прятать нечего и не от кого.
        $closed = ['closed_at' => '2026-10-04 12:00:00+00'];

        $this->assertTrue(Service_Nj520_Poll::results_visible($closed, FALSE));
        $this->assertTrue(Service_Nj520_Poll::results_visible($closed, TRUE));

        // Пустая строка в closed_at — это «не закрыт» (а не «закрыт пустым»).
        $this->assertFalse(Service_Nj520_Poll::results_visible(['closed_at' => ''], FALSE));
    }

    /* -------------------------------- Код -------------------------------- */

    public function test_poll_code_alphabet_has_no_lookalikes()
    {
        // Код диктуют голосом и переписывают с экрана. Из каждой пары
        // похожих знаков остаётся ОДИН: 0 и O выброшены оба (ноль от «о» не
        // отличить ни на слух, ни в большинстве шрифтов), а из 1/l/I оставлена
        // только единица — её ни с чем не спутать, когда соседей нет.
        foreach (['0', 'O', 'l', 'I'] as $char)
        {
            $this->assertStringNotContainsString($char, Service_Nj520_Poll::CODE_ALPHABET,
                'Похожий символ «'.$char.'» в алфавите кода');
        }

        $this->assertStringContainsString('1', Service_Nj520_Poll::CODE_ALPHABET);
        $this->assertSame(6, Service_Nj520_Poll::CODE_LEN);

        // Алфавит без повторов: иначе одни коды выпадали бы чаще других.
        $alphabet = Service_Nj520_Poll::CODE_ALPHABET;

        $this->assertSame(strlen($alphabet), count(array_unique(str_split($alphabet))));
    }
}
