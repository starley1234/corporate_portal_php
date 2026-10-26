<?php

use PHPUnit\Framework\TestCase;

/**
 * Прогоны «Разбора состава» (nj646_label) на живой БД: приёмка результата
 * воркера и дожим из очереди.
 *
 * Зачем отдельный дым (а не юнит): 03.10.2026 выяснилось, что вся цепочка
 * «фото → воркер → LLM» работала, а результат на сайт не попадал НИКОГДА —
 * `Service_Nj646_Store::run_apply()` клал в jsonb-колонку уже закавыченный
 * литерал, а `DB::update()->set()` экранировал его второй раз. PostgreSQL
 * отвечал «invalid input syntax for type json», страница получала 500, и
 * прогон навсегда оставался `queued` (владелец видел вечное «Распознаю
 * этикетку…», хотя модель давно ответила и результат лежал в nj_120_jobs).
 * Юнит-тесты логики такое не ловят — нужен настоящий jsonb.
 *
 * Строки создаются и удаляются самим тестом (bin/test гоняется и на рабочей
 * БД), поэтому visitor у них служебный — 'g:' . 32 нуля.
 */
class LabelRunTest extends TestCase
{
    const VISITOR = 'g:00000000000000000000000000000000';

    /** @var array созданные строки — для уборки */
    private $runs = [];
    private $jobs = [];

    protected function tearDown(): void
    {
        $db = Database::instance();

        foreach ($this->runs as $id)
        {
            $db->query(Database::DELETE,
                'DELETE FROM nj_646_runs WHERE id = '.(int) $id, FALSE);
        }

        foreach ($this->jobs as $id)
        {
            $db->query(Database::DELETE,
                'DELETE FROM nj_120_job_events WHERE job_id = '.(int) $id, FALSE);
            $db->query(Database::DELETE,
                'DELETE FROM nj_120_jobs WHERE id = '.(int) $id, FALSE);
        }

        $this->runs = [];
        $this->jobs = [];
    }

    /**
     * Создать прогон для теста. Не `run()`: этот метод объявлен final в
     * PHPUnit\Framework\TestCase, и переопределение валит файл фатально
     * («Cannot override final method») — CI это ловил, а дым не запускался.
     */
    private function make_run($mode = 'photo')
    {
        $id = Service_Nj646_Store::run_create([
            'user_id'   => NULL,
            'visitor'   => self::VISITOR,
            'mode'      => $mode,
            'client_ip' => '127.0.0.1',
        ]);

        $this->assertGreaterThan(0, (int) $id, 'Прогон не создался');

        $this->runs[] = (int) $id;

        return (int) $id;
    }

    /** Задача очереди с готовым результатом (условия дожима). */
    private function job($run_id, $status, array $result = NULL, $error = '', $attempts = 1, $max_attempts = 2)
    {
        $db = Database::instance();

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_120_jobs (channel, payload, status, result, error, attempts, max_attempts, finished_at)
             VALUES ('.$db->quote('nj646.label').', '.$db->quote(json_encode(['run_id' => $run_id])).'::jsonb,
                     '.$db->quote($status).', '
                     .($result === NULL ? 'NULL' : $db->quote(json_encode($result)).'::jsonb').', '
                     .$db->quote($error).', '.(int) $attempts.', '.(int) $max_attempts.', now())
             RETURNING id', FALSE)->current();

        $job_id = (int) $row['id'];
        $this->jobs[] = $job_id;

        return $job_id;
    }

    /**
     * Регрессия: результат воркера ОБЯЗАН доехать до jsonb-колонки.
     *
     * До починки здесь был Database_Exception «invalid input syntax for type
     * json» (двойное экранирование), то есть 500 на /label/api/worker_result.
     */
    public function test_worker_result_is_stored_as_jsonb()
    {
        $id = $this->make_run();

        $applied = Service_Nj646_Store::run_apply_worker($id, [
            'run_id' => $id, 'mode' => 'photo', 'status' => 'done',
            'text'   => 'Состав: вода, сахар, соль',
            'kind'   => 'food', 'model' => 'test-vlm', 'elapsed_ms' => 123, 'error' => '',
        ]);

        $this->assertSame('done', $applied['status']);

        $run = Service_Nj646_Store::run_get($id);

        $this->assertSame('done', (string) $run['status']);
        $this->assertSame('Состав: вода, сахар, соль', (string) $run['result']['text']);
        $this->assertSame(123, (int) $run['elapsed_ms']);
    }

    /**
     * Пометка воркера к составу обязана доехать до страницы.
     *
     * Зачем: ответ модели обрывается по max_tokens (длинный список экстрактов),
     * воркер вынимает состав из оборванного ответа и прикладывает note
     * «проверьте конец списка». Приёмка раньше знала только text/kind/error,
     * и note молча терялся — обрезанный состав выглядел на странице полным.
     */
    public function test_worker_note_reaches_the_page()
    {
        $id = $this->make_run();

        Service_Nj646_Store::run_apply_worker($id, [
            'run_id' => $id, 'mode' => 'photo', 'status' => 'done',
            'text'   => 'Вода, Экстракт жожоба',
            'kind'   => 'cosmetic',
            'note'   => 'ответ модели оборвался — проверьте конец списка ингредиентов',
            'model'  => 'test-vlm', 'error' => '',
        ]);

        $watch = Service_Nj646_Store::run_watch($id);

        $this->assertSame('done', (string) $watch['run']['status']);
        $this->assertStringContainsString(
            'оборвал', (string) $watch['run']['result']['note']);
    }

    /**
     * Переписанные строки этикетки (transcript) обязаны доехать до страницы.
     *
     * Владелец сверяет их с фото именно потому, что состав собирается ИЗ НИХ:
     * модель на мелком шрифте угадывает, и по строкам это видно сразу. Раньше
     * приёмка сохраняла только text/kind/note — строки терялись.
     */
    public function test_transcript_reaches_the_page()
    {
        $id = $this->make_run();

        Service_Nj646_Store::run_apply_worker($id, [
            'run_id' => $id, 'mode' => 'photo', 'status' => 'done',
            'text'   => 'Вода, Экстракт эхинацеи',
            'kind'   => 'cosmetic',
            'transcript' => "Состав: Вода, Экстракт эхинацеи\nОбъём: 170мл",
            'model'  => 'test-vlm', 'error' => '',
        ]);

        $watch = Service_Nj646_Store::run_watch($id);

        $this->assertSame('done', (string) $watch['run']['status']);
        $this->assertStringContainsString('Объём: 170мл',
            (string) $watch['run']['result']['transcript']);
    }

    /** Дожим: результат лежит в задаче, прогон остался queued — подхватываем. */
    public function test_run_watch_picks_result_from_queue()
    {
        $id  = $this->make_run();
        $job = $this->job($id, 'done', [
            'run_id' => $id, 'mode' => 'photo', 'status' => 'done',
            'text' => 'Состав: вода, E211', 'kind' => 'food', 'model' => 'test-vlm',
        ]);

        Service_Nj646_Store::run_set_job($id, $job);

        $watch = Service_Nj646_Store::run_watch($id);

        $this->assertSame('done', (string) $watch['run']['status']);
        $this->assertSame('Состав: вода, E211', (string) $watch['run']['result']['text']);
    }

    /** Отказанная задача (попытки исчерпаны) — прогон обязан показать ошибку. */
    public function test_run_watch_reports_failed_job()
    {
        $id  = $this->make_run();
        $job = $this->job($id, 'failed', NULL, 'Превышен таймаут задачи (360s)', 2, 2);

        Service_Nj646_Store::run_set_job($id, $job);

        $watch = Service_Nj646_Store::run_watch($id);

        $this->assertSame('error', (string) $watch['run']['status']);
        $this->assertStringContainsString('Превышен таймаут', (string) $watch['run']['error']);
    }

    /** Задачу никто не взял — страница обязана сказать об этом, а не молчать. */
    public function test_run_watch_hints_unclaimed_job()
    {
        $id = $this->make_run();

        // Своя строка задачи «из прошлого»: pending, никем не взята.
        $db = Database::instance();
        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_120_jobs (channel, payload, status, created_at)
             VALUES ('.$db->quote('nj646.label').', '.$db->quote(json_encode(['run_id' => $id])).'::jsonb,
                     '.$db->quote('pending').', now() - interval \'10 minutes\')
             RETURNING id', FALSE)->current();

        $job = (int) $row['id'];
        $this->jobs[] = $job;
        Service_Nj646_Store::run_set_job($id, $job);

        $watch = Service_Nj646_Store::run_watch($id);

        $this->assertSame('queued', (string) $watch['run']['status']);
        $this->assertStringContainsString('никто не взял', (string) $watch['hint']);
    }
}
