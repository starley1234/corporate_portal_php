<?php

use PHPUnit\Framework\TestCase;

/**
 * Дым на живой БД: массовые операции админки и история разборов (nj646_label).
 *
 * Запуск: php tests/mini_runner.php LabelAdmin
 *
 * Повод — владелец 03.10.2026: «сделай удобнее массовые операции для админа
 * (изучить всё, замодерировать всё и т.п.) — может через селекты» и «добавь
 * страницу для админа с перечнем что анализировали (последние n записей), да и
 * для пользователя — последние его запросы».
 *
 * Проверяем не разметку, а опасную часть: массовые действия трогают МНОГО строк
 * сразу (одним UPDATE), поэтому здесь — что они меняют ровно выбранное, не
 * задевают чужое и переживают мусорный ввод (строки вместо id).
 */
class LabelAdminTest extends TestCase
{
    /** @var array созданные строки — для уборки */
    private $ingredients = [];
    private $unknown_names = [];
    private $analyses = [];
    private $runs = [];
    private $jobs = [];

    protected function tearDown(): void
    {
        $db = Database::instance();

        foreach ($this->runs as $id)
        {
            $db->query(Database::DELETE, 'DELETE FROM nj_646_runs WHERE id = '.(int) $id, FALSE);
        }

        foreach ($this->jobs as $id)
        {
            $db->query(Database::DELETE, 'DELETE FROM nj_120_job_events WHERE job_id = '.(int) $id, FALSE);
            $db->query(Database::DELETE, 'DELETE FROM nj_120_jobs WHERE id = '.(int) $id, FALSE);
        }

        foreach ($this->analyses as $id)
        {
            $db->query(Database::DELETE, 'DELETE FROM nj_646_analyses WHERE id = '.(int) $id, FALSE);
        }

        foreach ($this->ingredients as $id)
        {
            $db->query(Database::DELETE, 'DELETE FROM nj_646_ingredients WHERE id = '.(int) $id, FALSE);
        }

        foreach ($this->unknown_names as $name)
        {
            $db->query(Database::DELETE, 'DELETE FROM nj_646_unknowns WHERE name_raw = '
                .$db->quote((string) $name), FALSE);
        }

        $this->ingredients = [];
        $this->unknown_names = [];
        $this->analyses = [];
        $this->runs = [];
        $this->jobs = [];
    }

    /** Уникальный хвост, чтобы тесты не задевали живые данные. */
    private function uniq($prefix = 'тест')
    {
        return $prefix.'-'.bin2hex(random_bytes(4));
    }

    /** Черновик карточки прямо в базе — как его кладёт изучение ИИ. */
    private function make_draft()
    {
        $row = Database::instance()->query(Database::SELECT,
            'INSERT INTO nj_646_ingredients (slug, name_ru, kind, risk, verdict, source, status, confidence)
             VALUES ('.$this->quote($this->uniq('slug')).', '.$this->quote($this->uniq('вещество'))
                .', \'food\', 2, \'caution\', \'llm\', \'draft\', 60) RETURNING id', FALSE)->current();

        $this->ingredients[] = (int) $row['id'];

        return (int) $row['id'];
    }

    private function quote($value)
    {
        return Database::instance()->quote((string) $value);
    }

    /** Задача очереди по прогону изучения. */
    private function job_of_run($run_id)
    {
        return Database::instance()->query(Database::SELECT,
            'SELECT id, channel, status FROM nj_120_jobs
              WHERE (payload->>\'run_id\')::bigint = '.(int) $run_id.' ORDER BY id DESC LIMIT 1', FALSE)->current();
    }

    public function test_moderate_many_publishes_only_selected_drafts()
    {
        $first  = $this->make_draft();
        $second = $this->make_draft();
        $third  = $this->make_draft();

        $changed = Service_Nj646_Store::ingredient_status_many([$first, $second], 'published');

        $this->assertSame(2, $changed, 'Массовая модерация должна менять ровно выбранные карточки');
        $this->assertSame('published', (string) Service_Nj646_Store::ingredient_by_id($first)['status']);
        $this->assertSame('published', (string) Service_Nj646_Store::ingredient_by_id($second)['status']);
        $this->assertSame('draft', (string) Service_Nj646_Store::ingredient_by_id($third)['status'],
            'Невыбранный черновик трогать нельзя');

        // И «замодерировать всё»: второй проход добирает остаток.
        $this->assertSame(1, Service_Nj646_Store::ingredient_status_many([$third], 'published'));

        // Скрытие пачкой — тем же путём.
        $this->assertSame(2, Service_Nj646_Store::ingredient_status_many([$first, $third], 'hidden'));
        $this->assertSame('hidden', (string) Service_Nj646_Store::ingredient_by_id($third)['status']);
    }

    public function test_moderate_many_survives_garbage_ids()
    {
        // Форму может прислать кто угодно: нечисловое в базе — только пропускаем.
        $this->assertSame(0, Service_Nj646_Store::ingredient_status_many(['abc', '0', '-5', ''], 'published'));
        $this->assertSame(0, Service_Nj646_Store::ingredient_status_many([], 'published'));
        $this->assertSame(0, Service_Nj646_Store::unknowns_ignore_many(['abc', 0]));
    }

    public function test_ignore_many_takes_unknowns_out_of_the_queue()
    {
        $first  = $this->uniq('мусор');
        $second = $this->uniq('мусор');
        $third  = $this->uniq('нужное');

        $a = Service_Nj646_Store::unknown_touch($first, 'food', 1);
        $b = Service_Nj646_Store::unknown_touch($second, 'food', 1);
        $c = Service_Nj646_Store::unknown_touch($third, 'food', 1);

        foreach ([$first, $second, $third] as $name)
        {
            $this->unknown_names[] = $name;
        }

        $changed = Service_Nj646_Store::unknowns_ignore_many([(int) $a['id'], (int) $b['id']]);

        $this->assertSame(2, $changed);
        $this->assertSame('ignored', (string) Service_Nj646_Store::unknown_by_id((int) $a['id'])['status']);
        $this->assertSame('ignored', (string) Service_Nj646_Store::unknown_by_id((int) $b['id'])['status']);
        $this->assertSame('new', (string) Service_Nj646_Store::unknown_by_id((int) $c['id'])['status'],
            'Соседняя строка очереди не должна попасть под массовый игнор');
    }

    public function test_study_ids_queues_only_selected()
    {
        if ( ! Service_Nj646_Store::llm_ready())
        {
            $this->markTestSkipped('Модель не подключена (TOOLFORGE_API_KEY пуст) — поток изучения не проверить.');
        }

        $picked = $this->uniq('выбранное');
        $other  = $this->uniq('невыбранное');

        $a = Service_Nj646_Store::unknown_touch($picked, 'food', 1);
        $b = Service_Nj646_Store::unknown_touch($other, 'food', 1);

        $this->unknown_names[] = $picked;
        $this->unknown_names[] = $other;

        $all = Service_Nj646_Store::study_push_ids([(int) $a['id']], ['visitor' => 'admin', 'total' => 2]);

        if ($all['code'] === 'no_llm')
        {
            // Модель подключена в конфиге, но проверка внутри study_push её не
            // увидела — тогда поток изучения в этой среде не проверить.
            $this->markTestSkipped('Модель не готова внутри study_push.');
        }

        if ($all['code'] === 'limit' AND ! $all['queued'])
        {
            // Дневной лимит занят другими прогонами: важно, что он назван.
            $this->assertStringContainsString('NJ646_STUDY_PER_DAY', (string) $all['message']);
            return;
        }

        $this->assertSame('queued', $all['code'], 'Выбранное вещество должно уйти на изучение');
        $this->assertSame(1, count($all['queued']));
        $this->assertSame((int) $a['id'], (int) $all['queued'][0]['unknown_id']);

        $this->runs[] = (int) $all['queued'][0]['run_id'];

        $job = $this->job_of_run((int) $all['queued'][0]['run_id']);

        if ($job)
        {
            $this->jobs[] = (int) $job['id'];
        }

        $this->assertNotEmpty($job, 'На прогон должна встать задача nj120');

        // Второе вещество осталось в очереди и НЕ получило прогон.
        $untouched = Service_Nj646_Store::unknown_by_id((int) $b['id']);

        $this->assertSame('new', (string) $untouched['status']);
        $this->assertEmpty($untouched['run_id'], 'Невыбранное вещество не должно уехать в изучение');

        // Повторная постановка того же вещества — те же прогоны, без дублей.
        $again = Service_Nj646_Store::study_push_ids([(int) $a['id']], ['visitor' => 'admin']);

        $this->assertSame([], (array) $again['queued'], 'Повторный push не должен плодить прогоны');
        $this->assertContains((string) $picked, (array) $again['already']);
    }

    public function test_history_shows_newest_first_and_paginates()
    {
        $visitor = $this->uniq('посетитель');
        $marker  = $this->uniq('состав');

        $first = Service_Nj646_Store::analysis_save([
            'user_id' => NULL, 'visitor' => $visitor, 'kind' => 'food', 'source' => 'text',
            'title' => 'первый', 'raw_text' => 'Вода, сахар, соль ('.$marker.')',
            'score' => 70, 'verdict' => 'neutral', 'counts' => [], 'items' => [], 'unknown' => ['сомнительное-'.$marker],
        ]);

        $second = Service_Nj646_Store::analysis_save([
            'user_id' => NULL, 'visitor' => $visitor, 'kind' => 'cosmetic', 'source' => 'photo',
            'title' => 'второй', 'raw_text' => 'Aqua, Glycerin ('.$marker.')',
            'score' => 88, 'verdict' => 'useful', 'counts' => [], 'items' => [], 'unknown' => [],
        ]);

        $this->analyses[] = (int) $first;
        $this->analyses[] = (int) $second;

        $this->assertGreaterThan(0, (int) $first);
        $this->assertGreaterThan(0, (int) $second);

        // Админская страница: свежие сверху, с сырым текстом (по нему и узнаём запись).
        $page = Service_Nj646_Store::analyses_page(20, 0);
        $ids  = array_map(function ($row) { return (int) $row['id']; }, $page);

        $this->assertContains((int) $second, $ids);
        $this->assertLessThan(array_search((int) $first, $ids, TRUE), array_search((int) $second, $ids, TRUE),
            'Свежая запись должна быть выше старой');

        $mine = Service_Nj646_Store::analyses_mine($visitor, 10);
        $this->assertCount(2, $mine, 'Мои разборы — только этого посетителя');
        $this->assertSame((int) $second, (int) $mine[0]['id'], 'И тоже свежие сверху');
        $this->assertStringContainsString($marker, (string) $mine[1]['raw_text']);
        $this->assertSame(2, Service_Nj646_Store::analyses_mine_count($visitor));

        // Постранично: вторая страница не пересекается с первой.
        $second_page = Service_Nj646_Store::analyses_mine($visitor, 1, 1);
        $this->assertCount(1, $second_page);
        $this->assertSame((int) $first, (int) $second_page[0]['id']);

        // Данные анализа пишутся целиком: незнакомые вещества видны админу в истории.
        $row = Service_Nj646_Store::analyses_page(200, 0);
        $found = NULL;

        foreach ($row as $candidate)
        {
            if ((int) $candidate['id'] === (int) $first)
            {
                $found = $candidate;
                break;
            }
        }

        $this->assertNotNull($found, 'Запись должна быть в истории админа');
        $this->assertStringContainsString('сомнительное-'.$marker, (string) $found['unknown']);
    }
}
