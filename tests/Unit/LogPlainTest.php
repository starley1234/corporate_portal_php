<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Log_Plain — плоского ротируемого лога сайта.
 *
 * Запуск: php tests/mini_runner.php LogPlain
 *
 * Повод (03.10.2026): прогон «фото этикетки» падал на приёмке результат с 500,
 * а на сайте не оставалось ни одной записи о том, что приёмка вообще была.
 * Разбираться пришлось перепиской и догадками. Лог должен лежать в одном
 * предсказуемом файле (application/logs/prl-site.log), читаться глазами и
 * ротироваться, чтобы не вырасти бесконечно.
 */
/** Считающий писатель: проверяет, что событие доходит и до общего лога сайта. */
class LogPlainCountingWriter extends Log_Writer {

    public $seen = [];

    public function write(array $messages)
    {
        foreach ($messages as $message)
        {
            $this->seen[] = $message['body'];
        }
    }
}

class LogPlainTest extends TestCase {

    /** @var string каталог для временных логов */
    protected $_dir;

    protected function setUp(): void
    {
        $this->_dir = sys_get_temp_dir().'/prl-log-'.bin2hex(random_bytes(6));

        if ( ! is_dir($this->_dir))
        {
            mkdir($this->_dir, 0777, TRUE);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->_dir.'/*') ?: [] as $file)
        {
            @unlink($file);
        }

        @rmdir($this->_dir);
    }

    /** Собрать писатель и записать в него одно сообщение. */
    protected function _log($file, $message, $level = Log::INFO, array $context = [], $writer = NULL)
    {
        $writer = $writer ?: new Log_Plain($file, 1048576, 3, Log::DEBUG);

        $writer->write([[
            'time'       => 1780000000,
            'level'      => $level,
            'body'       => $message,
            'file'       => '/srv/prl/application/classes/Controller.php',
            'line'       => 42,
            'additional' => $context,
        ]]);

        return $writer;
    }

    public function test_default_file_is_the_site_log_next_to_php_log()
    {
        $writer = new Log_Plain;

        $this->assertSame(APPPATH.'logs'.DIRECTORY_SEPARATOR.'prl-site.log', $writer->file());
    }

    public function test_one_line_per_event_flat_and_greppable()
    {
        $file = $this->_dir.'/prl-site.log';

        $this->_log($file, 'label: результат прогона 51 принят', Log::INFO, ['run' => 51]);

        $text = file_get_contents($file);

        $this->assertStringContainsString('INFO', $text);
        $this->assertStringContainsString('label: результат прогона 51 принят', $text);
        $this->assertStringContainsString('"run":51', $text);
        $this->assertStringContainsString('Controller.php:42', $text);

        // Событие — ровно одна строка: файл читается глазами и режется grep'ом.
        $this->assertSame(1, substr_count($text, "\n"), 'строка лога должна быть одна');
    }

    public function test_rotation_keeps_old_file_and_drops_oldest()
    {
        $file = $this->_dir.'/prl-site.log';

        // Малый лимит: 1 КБ на файл, 2 архива — ротация наступает быстро
        $writer = new Log_Plain($file, 1024, 2, Log::DEBUG);

        for ($i = 0; $i < 60; $i++)
        {
            $this->_log($file, 'запись номер '.$i.' '.str_repeat('x', 60), Log::INFO, [], $writer);
        }

        $this->assertFileExists($file);
        $this->assertFileExists($file.'.1', 'старый файл сохраняется как .1');
        $this->assertFileExists($file.'.2', 'второй архив сохраняется как .2');
        $this->assertFileDoesNotExist($file.'.3', 'третий архив не хранится');
    }

    public function test_level_filter_skips_below_threshold()
    {
        $file = $this->_dir.'/prl-site.log';

        $writer = new Log_Plain($file, 1048576, 3, Log::ERROR);

        $this->_log($file, 'это INFO и его быть не должно', Log::INFO, [], $writer);
        $this->_log($file, 'а это ERROR и он нужен', Log::ERROR, [], $writer);

        $text = file_get_contents($file);

        $this->assertStringNotContainsString('INFO и его', $text);
        $this->assertStringContainsString('ERROR и он нужен', $text);
    }

    public function test_exception_trace_is_written_line_by_line()
    {
        $file = $this->_dir.'/prl-site.log';

        $context = ['run' => 51, 'exception' => new RuntimeException('jsonb дважды закавычен')];

        $this->_log($file, 'label: приёмка прогона 51 УПАЛА', Log::ERROR, $context);

        $text = file_get_contents($file);

        $this->assertStringContainsString('RuntimeException', $text);
        $this->assertStringContainsString('jsonb дважды закавычен', $text);
        $this->assertStringContainsString('    #0', $text, 'кадры трассировки — отдельными строками с отступом');
    }

    public function test_event_writes_immediately_without_framework_flush()
    {
        // Главная ловушка: Kohana сбрасывает сообщения на диск только при
        // исключении (Log::$write_on_add = FALSE). Значит Log_Plain::event()
        // обязан писать сам — иначе события обычного запроса исчезают, и
        // разбор инцидента снова идёт вслепую.
        $file = $this->_dir.'/prl-site.log';
        $writer = new Log_Plain($file, 1048576, 3, Log::DEBUG);

        Kohana::$log->attach($writer);

        try
        {
            Log_Plain::event(Log::ERROR, 'label: приёмка прогона :run УПАЛА: :e',
                [':run' => 51, ':e' => 'Database_Exception: invalid input syntax for type json']);
        }
        finally
        {
            Kohana::$log->detach($writer);
        }

        $text = file_get_contents($file);

        $this->assertStringContainsString('приёмка прогона 51 УПАЛА', $text);
        $this->assertStringContainsString('invalid input syntax for type json', $text);
    }

    public function test_event_reaches_all_attached_writers()
    {
        // Событие обязано доехать и до общих писателей сайта (php-лог), а не
        // только до нашего файла: Log_Plain::event() зовёт Log::write(), и та
        // раздаёт сообщение всем, кто подписан.
        $counter = new LogPlainCountingWriter;
        Kohana::$log->attach($counter);

        try
        {
            Log_Plain::event(Log::INFO, 'проверка живучести :n', [':n' => 1]);
        }
        finally
        {
            Kohana::$log->detach($counter);
        }

        $this->assertContains('проверка живучести 1', $counter->seen);
    }

    public function test_applications_logs_directory_is_created_on_demand()
    {
        // Каталога application/logs/ в свежем дереве может не быть (он в
        // .gitignore, его создаёт bin/setup.sh) — писатель обязан создать его
        // сам, иначе на новом сервере лога не будет.
        $file = $this->_dir.'/nested/deeper/prl-site.log';

        $this->_log($file, 'первая запись на чистом сервере');

        $this->assertFileExists($file);
        $this->assertStringContainsString('первая запись на чистом сервере', file_get_contents($file));
    }

    public function test_writer_never_throws_when_file_is_unwritable()
    {
        // «Каталог» — это файл: писать некуда. Лог не имеет права уронить сайт.
        $blocker = $this->_dir.'/not-a-dir';
        file_put_contents($blocker, 'я файл, а не каталог');

        $writer = new Log_Plain($blocker.'/prl-site.log', 1048576, 3, Log::DEBUG);
        $writer->write([['time' => time(), 'level' => Log::ERROR, 'body' => 'ошибка без места']]);

        $this->assertTrue(TRUE, 'исключение не выброшено');
    }

    public function test_empty_and_broken_messages_are_ignored()
    {
        $file = $this->_dir.'/prl-site.log';

        $writer = new Log_Plain($file, 1048576, 3, Log::DEBUG);
        $writer->write([]);
        $writer->write([['time' => time(), 'body' => 'без уровня'], 'строка вместо массива']);
        $writer->write([['time' => time(), 'level' => Log::DEBUG, 'body' => 'нормальная запись']]);

        $this->assertSame(1, substr_count(file_get_contents($file), "\n"));
    }
}
