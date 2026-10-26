<?php

use PHPUnit\Framework\TestCase;

/**
 * Дым: приёмка результата воркера пишет исход в плоский лог сайта.
 *
 * Запуск: php tests/mini_runner.php WorkerResultLog
 *
 * Повод (03.10.2026). Фото этикетки уходило в очередь, модель отвечала, воркер
 * отправлял результат — и получал 500. На сайте не оставалось НИ ОДНОЙ записи
 * о том, что приёмка вообще была: разбор шёл перепиской и догадками. Владелец
 * попросил «писать логи и на стороне воркеров, и на стороне сервера, чтобы не
 * гадать, что произошло».
 *
 * Тест дёргает настоящее действие контроллера (не Store) и проверяет, что в
 * файл лога попадают отказы авторизации (403), «прогон не найден» (404) и
 * успешная приёмка — с id прогона, по которому владелец сможет искать.
 */
class WorkerResultLogTest extends TestCase
{
    /** @var string файл лога этого теста */
    private $log_file;

    /** @var Log_Plain писатель, подключённый только на время теста */
    private $writer;

    /** @var array созданные строки — для уборки */
    private $runs = [];

    /** @var string|false значение WORKER_API_TOKEN до теста */
    private $token_before;

    protected function setUp(): void
    {
        $this->log_file = sys_get_temp_dir().'/n2j-site-'.bin2hex(random_bytes(6)).'.log';
        $this->writer = new Log_Plain($this->log_file, 1048576, 2, Log::DEBUG);

        Kohana::$log->attach($this->writer);

        // Токен воркера задаёт ТЕСТ, а не окружение: в CI `.env` копируется из
        // `.env.example`, где `WORKER_API_TOKEN` пуст (bin/setup.sh генерирует
        // секреты только когда создаёт .env сам). При пустом токене приёмка
        // честно отвечает 403 «Неверный токен воркера» — и тест, который брал
        // `getenv('WORKER_API_TOKEN')`, проверял бы отказ вместо приёмки.
        // Ставим свой токен и возвращаем окружение как было (образец —
        // tests/Unit/NotifyBaseUrlTest.php).
        $this->token_before = getenv('WORKER_API_TOKEN');
        putenv('WORKER_API_TOKEN=тест-токен-воркера');
    }

    protected function tearDown(): void
    {
        Kohana::$log->detach($this->writer);

        if ($this->token_before === FALSE)
        {
            putenv('WORKER_API_TOKEN');
        }
        else
        {
            putenv('WORKER_API_TOKEN='.$this->token_before);
        }

        foreach ($this->runs as $id)
        {
            Database::instance()->query(Database::DELETE,
                'DELETE FROM nj_646_runs WHERE id = '.(int) $id, FALSE);
        }

        $this->runs = [];

        if (is_file($this->log_file))
        {
            @unlink($this->log_file);
        }
    }

    /** Вызвать POST /label/api/worker_result с заданным телом и токеном. */
    private function worker_result(array $body, $token)
    {
        $request = Request::factory('label/api/worker_result');
        $request->method(Request::POST);
        $request->body(json_encode($body, JSON_UNESCAPED_UNICODE));

        $controller = new Controller_Nj646_Api($request, Response::factory());
        $controller->before();

        $_SERVER['HTTP_X_WORKER_TOKEN'] = (string) $token;

        try
        {
            return $controller->action_worker_result();
        }
        finally
        {
            unset($_SERVER['HTTP_X_WORKER_TOKEN']);
        }
    }

    /** Текст лога после действия: файл читаем СРАЗУ — без Kohana::$log->write(). */
    private function log_text()
    {
        return is_file($this->log_file) ? (string) file_get_contents($this->log_file) : '';
    }

    public function test_wrong_worker_token_is_written_with_client_ip()
    {
        $response = $this->worker_result(['run_id' => 1, 'status' => 'done'], 'явно-неверный-токен');

        $this->assertSame(403, (int) $response->status());

        $text = $this->log_text();

        $this->assertStringContainsString('неверный токен воркера', $text);
        $this->assertStringContainsString('WARNING', $text);
    }

    public function test_missing_run_is_written_with_run_id()
    {
        $response = $this->worker_result(['run_id' => 999999999, 'status' => 'done'],
            'тест-токен-воркера');

        $this->assertSame(404, (int) $response->status());

        $text = $this->log_text();

        $this->assertStringContainsString('прогон 999999999 не найден', $text);
    }

    public function test_successful_acceptance_is_written_with_status_and_size()
    {
        $run_id = (int) Service_Nj646_Store::run_create([
            'user_id'   => NULL,
            'visitor'   => 'g:00000000000000000000000000000000',
            'mode'      => 'photo',
            'client_ip' => '127.0.0.1',
        ]);
        $this->assertGreaterThan(0, $run_id, 'Прогон не создался');
        $this->runs[] = $run_id;

        $text = 'вода, сахар, E211';

        $response = $this->worker_result([
            'run_id' => $run_id,
            'mode'   => 'photo',
            'status' => 'done',
            'kind'   => 'food',
            'text'   => $text,
            'note'   => '',
        ], 'тест-токен-воркера');

        $this->assertSame(200, (int) $response->status(), 'Приёмка должна пройти: '.$response->body());

        $log = $this->log_text();

        $this->assertStringContainsString('результат прогона '.$run_id.' принят', $log);
        $this->assertStringContainsString('done', $log);
        $this->assertStringContainsString(mb_strlen($text).' знаков', $log,
            'в записи — длина принятого текста');
    }
}
