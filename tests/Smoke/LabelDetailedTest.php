<?php

use PHPUnit\Framework\TestCase;

/**
 * Дым на живой БД: развёрнутый ответ и постановка изучения (nj646_label).
 *
 * Запуск: php tests/mini_runner.php LabelDetailed
 *
 * Два повода из разговора с владельцем 03.10.2026:
 *
 * 1. «/nj646/admin при нажатии "Изучить" перекидывает на
 *    ?flash=Очередь+недоступна%3A+ хотя воркер отрабатывает». Постановка
 *    отдавала NULL на всё сразу — лимит, отсутствие модели, недоступная
 *    очередь — и админка показывала одну фразу без причины. Теперь причина
 *    названа кодом: queued | already | no_llm | limit | unknown | queue_error.
 *
 * 2. «Предусмотри в конфигурации режим, при котором пользователь всё-таки
 *    получит развёрнутый ответ» — разбор доводится до конца: незнакомые
 *    вещества уходят на изучение сразу, состояние видно странице, ответ
 *    пересчитывается по пополненной базе.
 *
 * 3. «Сделай кнопку "изучить всё"» — вся очередь незнакомых уходит на изучение
 *    одной пачкой (Store::study_push_all), без дублей задач и с честным
 *    упором в дневной лимит.
 */
class LabelDetailedTest extends TestCase
{
    /** @var array созданные строки — для уборки */
    private $runs = [];
    private $jobs = [];
    private $analyses = [];
    private $unknown_names = [];

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

        // Незнакомцы создаются по name_raw с уникальным ключом — убираем за собой,
        // иначе следующий прогон увидит «уже изучается» вместо новой задачи.
        foreach ($this->unknown_names as $name)
        {
            $db->query(Database::DELETE, 'DELETE FROM nj_646_unknowns WHERE name_raw = '
                .$db->quote((string) $name), FALSE);
        }

        $this->runs = [];
        $this->jobs = [];
        $this->analyses = [];
        $this->unknown_names = [];
    }

    /**
     * Изучение невозможно без модели — это честный пропуск, а не «тест сломался».
     *
     * Ключ модели в песочнице пишется вручную (см. docs/SANDBOX-SETUP.md);
     * на сервере он есть всегда. Если модели нет — проверяем не поток, а
     * честный отказ (test_study_push_says_no_llm_when_model_is_off).
     */
    private function require_llm()
    {
        if ( ! Service_Nj646_Store::llm_ready())
        {
            $this->markTestSkipped('Модель не подключена (TOOLFORGE_API_KEY пуст) — поток изучения не проверить.');
        }
    }

    /** Уникальное имя вещества, которого точно нет в базе seed. */
    private function uniq_name($suffix = '')
    {
        return 'тест-вещество-'.bin2hex(random_bytes(4)).$suffix;
    }

    /**
     * Вызвать действие контроллера как HTTP-запрос (тело — JSON).
     *
     * Свой посетитель нужен, чтобы проверить и «свои» прогоны, и то, что
     * страница не видит чужие: у Controller_Nj646_Api посетитель берётся из
     * Visitor::uid(), а в CLI-тесте куки нет — подменяем в подклассе.
     */
    private function api_call($action, array $body, $visitor = '')
    {
        $request = Request::factory('label/api/'.$action);
        $request->method(Request::POST);
        $request->body(json_encode($body, JSON_UNESCAPED_UNICODE));

        $controller = new class ($request, Response::factory(), $visitor) extends Controller_Nj646_Api {
            protected $_test_visitor;

            public function __construct($request, $response, $visitor)
            {
                parent::__construct($request, $response);
                $this->_test_visitor = (string) $visitor;
            }

            protected function _visitor()
            {
                return $this->_test_visitor !== '' ? $this->_test_visitor : parent::_visitor();
            }
        };

        $controller->before();

        $method   = 'action_'.$action;
        $response = $controller->$method();
        $data     = json_decode((string) $response->body(), TRUE);

        return ['status' => (int) $response->status(), 'data' => is_array($data) ? $data : []];
    }

    /** Задача очереди по прогону изучения (последняя). */
    private function job_of_run($run_id)
    {
        return Database::instance()->query(Database::SELECT,
            'SELECT id, channel, status, payload, attempts, max_attempts FROM nj_120_jobs
              WHERE (payload->>\'run_id\')::bigint = '.(int) $run_id.' ORDER BY id DESC LIMIT 1', FALSE)->current();
    }

    public function test_detailed_analysis_queues_study_and_returns_its_state()
    {
        $this->require_llm();

        $name = $this->uniq_name();
        $this->unknown_names[] = $name;

        $answer = $this->api_call('analyze', [
            'text'     => 'Состав: вода, '.$name.', сахар, соль.',
            'kind'     => 'food',
            'detailed' => 1,
            'studies'  => 1,
        ], 'g:11111111111111111111111111111111');

        $this->assertTrue($answer['data']['ok'] === TRUE, 'Разбор не ответил ok: '.json_encode($answer['data'], JSON_UNESCAPED_UNICODE));
        $this->assertTrue( ! empty($answer['data']['detailed']), 'Развёрнутый режим должен быть включён');
        $this->assertNotEmpty($answer['data']['studies'], 'Незнакомое вещество должно уйти на изучение');
        $this->assertContains($name, $answer['data']['unknown'], 'Вещество должно быть в списке незнакомых');

        $study = $answer['data']['studies'][0];
        $this->runs[] = (int) $study['run_id'];
        $this->assertGreaterThan(0, (int) $study['run_id']);

        $job = $this->job_of_run((int) $study['run_id']);
        $this->assertNotEmpty($job, 'Задача изучения должна появиться в очереди');
        $this->jobs[] = (int) $job['id'];
        $this->assertSame('nj646.study', (string) $job['channel']);

        // Прогон изучения виден странице (опрос развёрнутого режима).
        $state = Service_Nj646_Store::study_state([(int) $study['run_id']], 'g:11111111111111111111111111111111');
        $this->assertCount(1, $state);
        $this->assertSame($name, (string) $state[0]['name']);
        $this->assertFalse((bool) $state[0]['done'], 'Пока воркер не ответил — изучение не готово');

        if ( ! empty($answer['data']['analysis']))
        {
            $this->analyses[] = (int) $answer['data']['analysis'];
        }
    }

    public function test_recount_does_not_queue_new_studies()
    {
        $this->require_llm();

        $name = $this->uniq_name();
        $this->unknown_names[] = $name;

        $first = $this->api_call('analyze', [
            'text' => 'Состав: вода, '.$name.'.', 'kind' => 'food', 'detailed' => 1, 'studies' => 1,
        ], 'g:22222222222222222222222222222222');

        if ( ! empty($first['data']['analysis']))
        {
            $this->analyses[] = (int) $first['data']['analysis'];
        }

        $this->assertNotEmpty($first['data']['studies'], 'Первый разбор ставит изучение');

        foreach ($first['data']['studies'] as $study)
        {
            $this->runs[] = (int) $study['run_id'];
            $job = $this->job_of_run((int) $study['run_id']);

            if ($job)
            {
                $this->jobs[] = (int) $job['id'];
            }
        }

        // Пересчёт после изучения (studies: 0) новых задач не ставит — иначе
        // каждый пересчёт забивал бы очередь заново.
        $second = $this->api_call('analyze', [
            'text' => 'Состав: вода, '.$name.'.', 'kind' => 'food', 'detailed' => 1, 'studies' => 0,
        ], 'g:22222222222222222222222222222222');

        if ( ! empty($second['data']['analysis']))
        {
            $this->analyses[] = (int) $second['data']['analysis'];
        }

        $this->assertSame([], $second['data']['studies'], 'Пересчёт не должен ставить новые задачи');
    }

    public function test_basic_mode_does_not_queue_studies()
    {
        $name = $this->uniq_name();
        $this->unknown_names[] = $name;

        $answer = $this->api_call('analyze', [
            'text' => 'Состав: вода, '.$name.'.', 'kind' => 'food', 'detailed' => 0,
        ], 'g:33333333333333333333333333333333');

        if ( ! empty($answer['data']['analysis']))
        {
            $this->analyses[] = (int) $answer['data']['analysis'];
        }

        $this->assertSame([], $answer['data']['studies'], 'Без развёрнутого режима задач не ставим');
        $this->assertFalse((bool) $answer['data']['detailed']);
    }

    public function test_study_push_reports_limit_instead_of_queue_error()
    {
        $name = $this->uniq_name();
        $this->unknown_names[] = $name;

        $ip  = Service_Nj100_Ratelimit::client_ip();
        $per = Service_Nj646_Store::limit('study_per_day', 10);
        $db  = Database::instance();

        // Занимаем дневной лимит изучений этого IP.
        for ($i = 0; $i < $per; $i++)
        {
            $row = $db->query(Database::SELECT,
                'INSERT INTO nj_646_runs (visitor, mode, client_ip, status)
                 VALUES (\'test-limit\', \'study\', '.$db->quote($ip).', \'done\') RETURNING id', FALSE)->current();
            $this->runs[] = (int) $row['id'];
        }

        $touch = Service_Nj646_Store::unknown_touch($name, 'food', 1);
        $this->assertNotEmpty($touch, 'Незнакомец должен записаться');
        $this->unknown_names[] = $name;

        $push = Service_Nj646_Store::study_push((int) $touch['id'], $name, 'food', [
            'manual' => TRUE, 'visitor' => 'admin',
        ]);

        if ( ! Service_Nj646_Store::llm_ready())
        {
            // Модели нет — раньше, чем про лимит, честно говорим про модель.
            $this->assertSame('no_llm', $push['code']);
            return;
        }

        // Главное: причина НАЗВАНА. Раньше здесь был NULL, а админка показывала
        // «Очередь недоступна: » — и владелец шёл проверять живую очередь.
        $this->assertSame('limit', $push['code']);
        $this->assertFalse((bool) $push['ok']);
        $this->assertStringContainsString('NJ646_STUDY_PER_DAY', (string) $push['message']);
        $this->assertSame(0, (int) $push['run_id'], 'Прогон при лимите не создаётся');
    }

    public function test_study_push_queues_once_and_reports_already_queued()
    {
        $this->require_llm();

        $name = $this->uniq_name();
        $this->unknown_names[] = $name;

        $touch = Service_Nj646_Store::unknown_touch($name, 'food', 1);
        $this->unknown_names[] = $name;

        $push = Service_Nj646_Store::study_push((int) $touch['id'], $name, 'food', [
            'manual' => TRUE, 'visitor' => 'admin',
        ]);

        $this->assertSame('queued', $push['code'], 'Первая постановка должна ставить задачу');
        $this->assertTrue((bool) $push['ok']);
        $this->runs[] = (int) $push['run_id'];

        $job = $this->job_of_run((int) $push['run_id']);

        if ($job)
        {
            $this->jobs[] = (int) $job['id'];
        }

        // Второе нажатие «Изучить» не кладёт в очередь дубль.
        $second = Service_Nj646_Store::study_push((int) $touch['id'], $name, 'food', [
            'manual' => TRUE, 'visitor' => 'admin',
        ]);

        $this->assertSame('already', $second['code']);
        $this->assertSame((int) $push['run_id'], (int) $second['run_id'],
            'Повторная постановка должна вернуть ТУ ЖЕ задачу, а не создать новую');
    }

    public function test_study_state_hides_runs_of_other_visitors()
    {
        $db = Database::instance();

        $mine = Service_Nj646_Store::run_create([
            'user_id' => NULL, 'visitor' => 'g:44444444444444444444444444444444',
            'mode' => 'study', 'client_ip' => '127.0.0.1',
        ]);
        $this->runs[] = (int) $mine;

        $theirs = Service_Nj646_Store::run_create([
            'user_id' => NULL, 'visitor' => 'g:55555555555555555555555555555555',
            'mode' => 'study', 'client_ip' => '127.0.0.1',
        ]);
        $this->runs[] = (int) $theirs;

        $state = Service_Nj646_Store::study_state([$mine, $theirs], 'g:44444444444444444444444444444444');

        $this->assertCount(1, $state, 'Чужой прогон не должен попадать в ответ странице');
        $this->assertSame((int) $mine, (int) $state[0]['run_id']);

        // Админ видит всё — ему и разбираться.
        $this->assertCount(2, Service_Nj646_Store::study_state([$mine, $theirs], 'admin'));
    }

    public function test_study_queue_state_shows_jobs_and_unclaimed_hint()
    {
        $this->require_llm();

        $name = $this->uniq_name();
        $this->unknown_names[] = $name;

        $touch = Service_Nj646_Store::unknown_touch($name, 'food', 1);
        $this->unknown_names[] = $name;

        $push = Service_Nj646_Store::study_push((int) $touch['id'], $name, 'food', [
            'manual' => TRUE, 'visitor' => 'admin',
        ]);
        $this->runs[] = (int) $push['run_id'];

        $job = $this->job_of_run((int) $push['run_id']);

        if ($job)
        {
            $this->jobs[] = (int) $job['id'];
        }

        $state = Service_Nj646_Store::study_queue_state(6);

        $this->assertNotEmpty($state['jobs'], 'Свежая задача изучения должна быть видна админке');
        $this->assertGreaterThanOrEqual(1, (int) $state['pending']);
        $this->assertSame(0, (int) $state['unclaimed'], 'Только что поставленная задача «зависшей» не считается');
    }

    /**
     * Поднять вещество в начало очереди.
     *
     * study_push_all берёт `ORDER BY seen_count DESC, id ASC` — без этого тест
     * зависел бы от того, сколько чужих незнакомцев накопилось в базе: при
     * лимите пачки они могли занять все места, и «наше» вещество не попало бы
     * в проверку. Счётчик 999 гарантирует первые строки.
     */
    private function bump_unknown($id, $seen = 999)
    {
        Database::instance()->query(Database::UPDATE,
            'UPDATE nj_646_unknowns SET seen_count = '.(int) $seen.', status = \'new\', run_id = NULL
              WHERE id = '.(int) $id, FALSE);
    }

    public function test_study_push_all_queues_batch_without_duplicates()
    {
        $this->require_llm();

        // Два свежих вещества: одно доказывало бы «кнопка берёт из очереди»,
        // вдвоём доказывают, что пачка — именно пачка, а не одна постановка.
        $names = [$this->uniq_name('-a'), $this->uniq_name('-b')];
        $ids   = [];

        foreach ($names as $i => $name)
        {
            $this->unknown_names[] = $name;

            $touch = Service_Nj646_Store::unknown_touch($name, 'food', 1);
            $this->assertNotEmpty($touch, 'Незнакомец должен записаться');

            $ids[] = (int) $touch['id'];
            $this->bump_unknown((int) $touch['id'], 999 - $i);
        }

        // Пачка — на 3 строки: тест не должен разгребать чужую очередь целиком
        // (в живой базе там десятки веществ, и каждое уехало бы в изучение).
        $all = Service_Nj646_Store::study_push_all(['limit' => 3, 'visitor' => 'admin']);

        if ($all['code'] === 'limit' AND ! $all['queued'])
        {
            // Дневной лимит уже занят другими прогонами — важно, что он назван,
            // а не выдан за «очередь недоступна».
            $this->assertStringContainsString('NJ646_STUDY_PER_DAY', (string) $all['message']);
            return;
        }

        $mine = [];

        foreach ($all['queued'] as $item)
        {
            $mine[(int) $item['unknown_id']] = $item;
            $this->runs[] = (int) $item['run_id'];

            $job = $this->job_of_run((int) $item['run_id']);

            if ($job)
            {
                $this->jobs[] = (int) $job['id'];
            }
        }

        // Оба наших вещества обязаны быть в пачке — иначе это не «изучить всё».
        foreach ($ids as $unknown_id)
        {
            $this->assertArrayHasKey($unknown_id, $mine,
                'Кнопка «изучить всё» обязана взять незнакомца из очереди');
        }

        $this->assertGreaterThanOrEqual(2, count($mine), 'Пачка должна ставить несколько задач за раз');
        $this->assertStringContainsString('Поставил', (string) $all['message']);

        // У каждой поставленной задачи — живой прогон, привязанный к веществу.
        foreach ($mine as $item)
        {
            $row = Service_Nj646_Store::unknown_by_id((int) $item['unknown_id']);

            $this->assertSame('queued', (string) $row['status']);
            $this->assertSame((int) $item['run_id'], (int) $row['run_id']);

            $job = $this->job_of_run((int) $item['run_id']);
            $this->assertNotEmpty($job, 'На каждый прогон пачки должна быть задача nj120');
        }

        // Второе нажатие не плодит задач: наши вещества уже изучаются (already),
        // и прогоны у них остались ТЕМИ ЖЕ.
        $again = Service_Nj646_Store::study_push_all(['limit' => 3, 'visitor' => 'admin']);

        foreach ($again['queued'] as $item)
        {
            $this->runs[] = (int) $item['run_id'];
        }

        foreach ($ids as $unknown_id)
        {
            $this->assertNotContains($unknown_id, array_column($again['queued'], 'unknown_id'),
                'Повторное «изучить всё» не должно ставить задачу на уже изучаемое вещество');

            $row = Service_Nj646_Store::unknown_by_id($unknown_id);

            $this->assertSame((int) $mine[$unknown_id]['run_id'], (int) $row['run_id'],
                'Прогон вещества не должен подменяться при повторном нажатии');
        }
    }

    public function test_study_push_all_says_no_llm_when_model_is_off()
    {
        if (Service_Nj646_Store::llm_ready())
        {
            $this->markTestSkipped('Модель подключена — ветку «нет модели» проверит test_study_push_says_no_llm_when_model_is_off.');
        }

        $name = $this->uniq_name();
        $this->unknown_names[] = $name;

        Service_Nj646_Store::unknown_touch($name, 'food', 1);
        $this->unknown_names[] = $name;

        $all = Service_Nj646_Store::study_push_all(['limit' => 5, 'visitor' => 'admin']);

        $this->assertSame('no_llm', $all['code']);
        $this->assertFalse((bool) $all['ok']);
        $this->assertSame([], $all['queued']);
    }

    public function test_study_push_says_no_llm_when_model_is_off()
    {
        // Если модель не подключена — это первый и честный ответ. Раньше он
        // выглядел как «Очередь недоступна», и владелец шёл чинить живую очередь.
        if (Service_Nj646_Store::llm_ready())
        {
            $this->markTestSkipped('Модель подключена — ветка «нет модели» в этой среде не воспроизводится.');
        }

        $name = $this->uniq_name();
        $this->unknown_names[] = $name;

        $touch = Service_Nj646_Store::unknown_touch($name, 'food', 1);
        $this->unknown_names[] = $name;

        $push = Service_Nj646_Store::study_push((int) $touch['id'], $name, 'food', [
            'manual' => TRUE, 'visitor' => 'admin',
        ]);

        $this->assertSame('no_llm', $push['code']);
        $this->assertSame(0, (int) $push['run_id']);
        $this->assertStringContainsString('языковая модель', (string) $push['message']);
    }
}
