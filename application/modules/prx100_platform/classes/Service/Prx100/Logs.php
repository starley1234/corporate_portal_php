<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100: чтение логов сайта для /prx100/logs — только чтение, ничего не пишет.
 *
 * Два источника:
 *   * плоский журнал сайта (Log_Plain, по умолчанию application/logs/prx-site.log,
 *     ротация .1 … .N): одна строка = одно событие, трассировка — строки с отступом;
 *   * php-лог Kohana (application/logs/ГГГГ/ММ/ДД.php): «дата --- УРОВЕНЬ: текст»,
 *     трассировка — следующие строки до следующей записи.
 *
 * Большие файлы читаем с хвоста (MAX_BYTES), чтобы страница не съела память.
 * Уровни — как в Kohana Log: EMERGENCY(0) … DEBUG(7); «WARNING и выше» = level ≤ WARNING.
 */
class Service_Prx100_Logs
{
    const MAX_BYTES = 8 * 1024 * 1024;
    const MAX_MSG = 1200;
    const MAX_TRACE = 10;

    /** Уровни от тяжёлых к лёгким; значение — число Kohana Log. */
    public const LEVELS = [
        'EMERGENCY' => 0, 'ALERT' => 1, 'CRITICAL' => 2, 'ERROR' => 3,
        'WARNING' => 4, 'NOTICE' => 5, 'INFO' => 6, 'DEBUG' => 7,
    ];

    /** Пороги фильтра «уровень и выше»: ключ запроса → минимальный уровень. */
    public const THRESHOLDS = ['' => 7, 'notice' => 5, 'warning' => 4, 'error' => 3];

    /** Файл плоского журнала (та же настройка, что у Log_Plain). */
    public static function site_file(): string
    {
        $file = (string) getenv('PRX_LOG_FILE');

        return $file !== '' ? $file : APPPATH.'logs'.DIRECTORY_SEPARATOR.'prx-site.log';
    }

    /** Файл php-лога Kohana за дату YYYY-MM-DD или NULL (дата вне шаблона). */
    public static function kohana_file(string $day): ?string
    {
        if ( ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $m))
        {
            return NULL;
        }

        return APPPATH.'logs'.DIRECTORY_SEPARATOR.$m[1].DIRECTORY_SEPARATOR.$m[2].DIRECTORY_SEPARATOR.$m[3].'.php';
    }

    /** Дни с php-логом, свежие сверху (не старше $days). */
    public static function kohana_days(int $days = 14): array
    {
        $out = [];

        for ($i = 0; $i < $days; $i++)
        {
            $ts = time() - $i * 86400;
            $file = APPPATH.'logs/'.date('Y/m/d', $ts).'.php';

            if (is_file($file) AND is_readable($file))
            {
                $out[] = [
                    'day'   => date('Y-m-d', $ts),
                    'label' => date('d.m.Y', $ts).($i === 0 ? ' · сегодня' : ($i === 1 ? ' · вчера' : '')),
                    'size'  => (int) filesize($file),
                ];
            }
        }

        return $out;
    }

    /**
     * Плоский журнал сайта: хвост файла, отфильтрованный и перевёрнутый (свежие сверху).
     *
     * @return array entries, shown, matched, scanned, counts
     */
    public static function read_site(string $threshold, string $q, int $limit): array
    {
        $file = self::site_file();
        $lines = self::_tail($file);
        $entries = self::_parse_site($lines);

        return self::_finish($entries, $threshold, $q, $limit) + [
            'file'     => $file,
            'exists'   => is_file($file),
            'size'     => is_file($file) ? (int) filesize($file) : 0,
            'modified' => is_file($file) ? (int) filemtime($file) : NULL,
            'backups'  => self::_backups($file),
        ];
    }

    /** Php-лог Kohana за день. */
    public static function read_kohana(string $day, string $threshold, string $q, int $limit): array
    {
        $file = self::kohana_file($day);
        $lines = ($file !== NULL AND is_file($file) AND is_readable($file) AND filesize($file) <= self::MAX_BYTES)
            ? preg_split('/\r?\n/', (string) file_get_contents($file)) : [];
        $entries = self::_parse_kohana(array_values(array_filter($lines, 'is_string')));

        return self::_finish($entries, $threshold, $q, $limit) + [
            'file'     => (string) $file,
            'exists'   => $file !== NULL AND is_file($file),
            'size'     => ($file !== NULL AND is_file($file)) ? (int) filesize($file) : 0,
            'modified' => ($file !== NULL AND is_file($file)) ? (int) filemtime($file) : NULL,
            'backups'  => [],
        ];
    }

    /** Где ещё живут логи и статусы платформы — для панели «Источники». */
    public static function sources(): array
    {
        $workers = dirname(APPPATH).'/workers/logs/prx-worker.log';
        $site = self::site_file();
        $today = self::kohana_file(date('Y-m-d'));
        $row = function (string $path): array {
            if ( ! is_file($path))
            {
                return ['exists' => FALSE, 'size' => 0, 'modified' => NULL];
            }

            return ['exists' => TRUE, 'size' => (int) filesize($path), 'modified' => (int) filemtime($path)];
        };

        return [
            ['key' => 'site', 'title' => 'Журнал сайта', 'path' => 'application/logs/'.basename($site), 'icon' => 'fas fa-file-lines',
             'note' => 'Log_Plain: одна строка = событие, ротация по 5 МБ (PRX_LOG_*). Присылают при разборе инцидента.'] + $row($site),
            ['key' => 'kohana', 'title' => 'Php-лог Kohana', 'path' => 'application/logs/ГГГГ/ММ/ДД.php', 'icon' => 'fas fa-code',
             'note' => 'Log_File: всё, что добавлено через Kohana::$log->add(), включая трассировки.'] + $row((string) $today),
            ['key' => 'workers', 'title' => 'Воркеры', 'path' => 'workers/logs/prx-worker.log', 'icon' => 'fas fa-gears',
             'note' => 'Python-воркер пишет на своём сервере (systemd → stderr + файл); здесь — только если он на этой машине.',
             'href' => '/prx120/workers'] + $row($workers),
            ['key' => 'queue', 'title' => 'Очередь задач', 'path' => 'таблица prx_120_jobs', 'icon' => 'fas fa-list-check',
             'note' => 'Статусы, попытки и текст ошибки каждой задачи.', 'href' => '/prx120/monitor', 'exists' => TRUE],
            ['key' => 'errors', 'title' => 'Ошибки модулей', 'path' => 'таблицы по health манифестов', 'icon' => 'fas fa-heart-crack',
             'note' => 'Свежие ошибочные строки модулей + WARNING/ERROR из лога за два дня.', 'href' => '/prx100/errors', 'exists' => TRUE],
            ['key' => 'events', 'title' => 'События', 'path' => 'prx_208_events', 'icon' => 'fas fa-bolt',
             'note' => 'Журнал доменных событий: кто что опубликовал.', 'href' => '/prx208_events', 'exists' => TRUE],
        ];
    }

    // ---------------------------------------------------------------------

    /** Последние MAX_BYTES файла строками (без первой, возможно оборванной). */
    protected static function _tail(string $file): array
    {
        if ( ! is_file($file) OR ! is_readable($file))
        {
            return [];
        }

        $size = (int) filesize($file);
        $offset = max(0, $size - self::MAX_BYTES);
        $lines = [];

        try
        {
            $fh = fopen($file, 'rb');

            if ($fh === FALSE)
            {
                return [];
            }

            if ($offset > 0)
            {
                fseek($fh, $offset);
                fgets($fh); // обрывок строки
            }

            while (($line = fgets($fh)) !== FALSE)
            {
                $lines[] = rtrim($line, "\r\n");
            }

            fclose($fh);
        }
        catch (Throwable $e)
        {
            return $lines;
        }

        return $lines;
    }

    /** Строки «дата | УРОВЕНЬ | текст», продолжения — с отступа. */
    protected static function _parse_site(array $lines): array
    {
        $entries = [];

        foreach ($lines as $line)
        {
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \| ([A-Z]+)\s*\| (.*)$/', $line, $m))
            {
                $entries[] = [
                    'level' => strtoupper($m[2]),
                    'time'  => $m[1],
                    'msg'   => mb_substr($m[3], 0, self::MAX_MSG),
                    'trace' => [],
                ];
            }
            elseif ($entries AND $line !== '' AND $line[0] === ' ')
            {
                $last = count($entries) - 1;

                if (count($entries[$last]['trace']) < self::MAX_TRACE)
                {
                    $entries[$last]['trace'][] = mb_substr(trim($line), 0, 300);
                }
            }
        }

        return $entries;
    }

    /** Строки «дата --- УРОВЕНЬ: текст», продолжения — до следующей записи. */
    protected static function _parse_kohana(array $lines): array
    {
        $text = preg_replace('~^<\?php.*?\?>~s', '', implode("\n", $lines), 1);
        $entries = [];
        $cur = NULL;

        foreach (preg_split('/\r?\n/', (string) $text) as $line)
        {
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) --- ([A-Z]+): (.*)$/', $line, $m))
            {
                if ($cur !== NULL)
                {
                    $entries[] = $cur;
                }

                $cur = ['level' => strtoupper($m[2]), 'time' => $m[1], 'msg' => mb_substr($m[3], 0, self::MAX_MSG), 'trace' => []];
            }
            elseif ($cur !== NULL AND $line !== '')
            {
                if (count($cur['trace']) < self::MAX_TRACE)
                {
                    $cur['trace'][] = mb_substr($line, 0, 300);
                }
            }
        }

        if ($cur !== NULL)
        {
            $entries[] = $cur;
        }

        return $entries;
    }

    /** Фильтр по уровню и подстроке, счётчики, переворот «свежие сверху», лимит. */
    protected static function _finish(array $entries, string $threshold, string $q, int $limit): array
    {
        $min = self::THRESHOLDS[$threshold] ?? 7;
        $q = mb_strtolower(trim($q));
        $counts = array_fill_keys(array_keys(self::LEVELS), 0);
        $matched = [];

        foreach ($entries as $e)
        {
            $level_no = self::LEVELS[$e['level']] ?? 7;
            $counts[$e['level']] = ($counts[$e['level']] ?? 0) + 1;

            if ($level_no > $min)
            {
                continue;
            }

            if ($q !== '' AND mb_strpos(mb_strtolower($e['msg'].' '.implode(' ', $e['trace'])), $q) === FALSE)
            {
                continue;
            }

            $matched[] = $e;
        }

        $matched = array_reverse($matched);

        return [
            'entries'  => array_slice($matched, 0, max(1, min(1000, $limit))),
            'shown'    => min(count($matched), max(1, min(1000, $limit))),
            'matched'  => count($matched),
            'scanned'  => count($entries),
            'counts'   => $counts,
        ];
    }

    /** Ротированные копии: .1 … .N — размер каждой. */
    protected static function _backups(string $file): array
    {
        $out = [];

        for ($i = 1; $i <= 9; $i++)
        {
            if (is_file($file.'.'.$i))
            {
                $out[] = ['name' => basename($file).'.'.$i, 'size' => (int) filesize($file.'.'.$i), 'modified' => (int) filemtime($file.'.'.$i)];
            }
        }

        return $out;
    }
}
