<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «PDF-инструменты» (модуль NJ-634) — серверный слой-оркестратор.
 *
 * Набор легковесных утилит на чистом PHP (без внешних бинарников):
 *  * info  — версия, страницы, метаданные, шифрование;
 *  * text  — извлечение текста (текстовые PDF, не сканы);
 *  * txt2pdf — генерация PDF из текста (кириллица, встраиваемый шрифт);
 *  * split — вырезать диапазон страниц;
 *  * merge — перекомпоновать страницы одного файла.
 *
 * БЕЗОПАСНОСТЬ:
 *  * никакого exec()/shell — только встроенные расширения (zlib/mbstring);
 *  * входной файл строго проверяется: магия %PDF-, лимит размера, не шифрованные;
 *  * rate limit по IP в БД (таблица nj_634_runs);
 *  * извлечённый текст хранится в БД с потолком NJ634_MAX_TEXT_CHARS.
 */
class Service_Nj634_Pdf
{
    const RATE_PER_MINUTE = 5;
    const RATE_PER_HOUR   = 40;

    /** Конфиг модуля. */
    public static function cfg($key, $default = NULL)
    {
        $cfg = Kohana::$config->load('nj634_pdf');

        return $cfg ? $cfg->get($key, $default) : $default;
    }

    public static function client_ip()
    {
        if (class_exists('Service_Nj100_Ratelimit'))
        {
            return Service_Nj100_Ratelimit::client_ip();
        }

        // фолбэк (nj100 не подключён — не должно случиться)
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $xff    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

        if ($xff !== '' AND ($remote === '' OR filter_var($remote, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE))
        {
            return trim((string) strtok($xff, ','));
        }

        return $remote;
    }

    protected static function _db()
    {
        return Database::instance();
    }

    /** Максимальный размер загружаемого файла, байт. */
    public static function max_bytes(): int
    {
        return (int) self::cfg('max_upload_mb', 15) * 1024 * 1024;
    }

    public static function rate_ok($ip)
    {
        self::ensure_tables();

        // Пороги — у владельца; счёт окон и решение — общий слой nj100_platform.
        $counts = Service_Nj100_Ratelimit::window_counts(
            'nj_634_runs', (string) $ip, 'client_ip');

        return Service_Nj100_Ratelimit::allowed($counts,
            (int) self::cfg('rate.per_minute', self::RATE_PER_MINUTE),
            (int) self::cfg('rate.per_hour', self::RATE_PER_HOUR));
    }

    public static function rate_limit_text()
    {
        return Service_Nj100_Ratelimit::limit_text(
            (int) self::cfg('rate.per_minute', self::RATE_PER_MINUTE),
            (int) self::cfg('rate.per_hour', self::RATE_PER_HOUR),
            'операций');
    }

    // --------------------------------------------------------- приём файла

    /**
     * Прочитать и проверить загруженный файл из $_FILES['file'].
     * Возвращает ['ok'=>true,'name'=>..,'bytes'=>..] или ['ok'=>false,'error'=>..].
     */
    public static function read_upload(): array
    {
        if (empty($_FILES['file']))
        {
            return ['ok' => FALSE, 'error' => 'Не получен файл. Отправьте PDF в поле file.'];
        }

        $f = $_FILES['file'];

        if (is_array($f['error']))
        {
            return ['ok' => FALSE, 'error' => 'Ожидается один файл.'];
        }

        if ((int) $f['error'] !== UPLOAD_ERR_OK)
        {
            return ['ok' => FALSE, 'error' => 'Ошибка загрузки файла (код '.$f['error'].').'];
        }

        $tmp = $f['tmp_name'];

        if ( ! is_uploaded_file($tmp))
        {
            return ['ok' => FALSE, 'error' => 'Некорректная загрузка файла.'];
        }

        if ((int) $f['size'] > self::max_bytes())
        {
            return ['ok' => FALSE, 'error' => 'Файл больше лимита '.self::cfg('max_upload_mb', 15).' МБ.'];
        }

        $bytes = @file_get_contents($tmp);

        if ($bytes === FALSE)
        {
            return ['ok' => FALSE, 'error' => 'Не удалось прочитать файл.'];
        }

        if (substr($bytes, 0, 5) !== '%PDF-')
        {
            return ['ok' => FALSE, 'error' => 'Файл не является PDF (нет магии %PDF-).'];
        }

        $name = isset($f['name']) ? basename((string) $f['name']) : 'file.pdf';
        $name = mb_substr($name, 0, 180);

        return ['ok' => TRUE, 'name' => $name, 'bytes' => $bytes, 'size' => strlen($bytes)];
    }

    /** Несколько файлов из $_FILES['files'] (для объединения). */
    public static function read_uploads(): array
    {
        if (empty($_FILES['files']))
        {
            return ['ok' => FALSE, 'error' => 'Не получены файлы. Отправьте PDF в поле files[].'];
        }

        $fin = $_FILES['files'];

        if ( ! isset($fin['name']) OR ! is_array($fin['name']))
        {
            return ['ok' => FALSE, 'error' => 'Ожидается массив файлов (files[]).'];
        }

        $count = count($fin['name']);

        if ($count < 1 OR $count > 12)
        {
            return ['ok' => FALSE, 'error' => 'Нужно от 1 до 12 файлов.'];
        }

        $out = [];

        for ($i = 0; $i < $count; $i++)
        {
            if ((int) $fin['error'][$i] !== UPLOAD_ERR_OK)
            {
                return ['ok' => FALSE, 'error' => 'Ошибка загрузки файла '.($i + 1).'.'];
            }

            $tmp = $fin['tmp_name'][$i];

            if ( ! is_uploaded_file($tmp))
            {
                return ['ok' => FALSE, 'error' => 'Некорректная загрузка файла '.($i + 1).'.'];
            }

            $size = $fin['size'][$i];

            if ((int) $size > self::max_bytes())
            {
                return ['ok' => FALSE, 'error' => 'Файл '.($i + 1).' больше лимита '.self::cfg('max_upload_mb', 15).' МБ.'];
            }

            $bytes = @file_get_contents($tmp);

            if ($bytes === FALSE OR substr($bytes, 0, 5) !== '%PDF-')
            {
                return ['ok' => FALSE, 'error' => 'Файл '.($i + 1).' не является PDF.'];
            }

            $name = isset($fin['name'][$i]) ? basename((string) $fin['name'][$i]) : 'file.pdf';
            $out[] = [
                'name'  => mb_substr($name, 0, 180),
                'bytes' => $bytes,
                'size'  => strlen($bytes),
            ];
        }

        return ['ok' => TRUE, 'files' => $out];
    }

    // ------------------------------------------------------------ операции

    public static function info(string $bytes): array
    {
        return Service_Nj634_Parser::meta($bytes);
    }

    public static function text(string $bytes): array
    {
        return Service_Nj634_Parser::text($bytes);
    }

    public static function txt2pdf(string $text, ?string $title): array
    {
        return Service_Nj634_Txt2pdf::build($text, $title);
    }

    public static function split(string $bytes, int $first, int $last): array
    {
        return Service_Nj634_SplitMerge::extract($bytes, $first, $last);
    }

    public static function merge(string $bytes, array $pages): array
    {
        return Service_Nj634_SplitMerge::merge_select($bytes, $pages);
    }

    public static function merge_files(array $files): array
    {
        return Service_Nj634_SplitMerge::merge_files($files);
    }

    // ------------------------------------------------------------ история

    /** Записать прогон в БД. */
    public static function record_run(string $kind, string $name, array $result, string $status = 'ok', ?string $error = NULL): bool
    {
        self::ensure_tables();

        $text = is_string($result['text'] ?? NULL) ? mb_substr($result['text'], 0, (int) self::cfg('max_text_chars', 200000)) : NULL;

        $summary = [
            'pages'   => isset($result['pages']) ? (int) $result['pages'] : NULL,
            'size'    => isset($result['size']) ? (int) $result['size'] : NULL,
            'chars'   => isset($result['chars']) ? (int) $result['chars'] : NULL,
        ];

        if ($kind === 'info' AND isset($result['version']))
        {
            $summary['version'] = $result['version'];
            $summary['encrypted'] = $result['encrypted'] ?? FALSE;
        }

        $db = self::_db();

        $sql = 'INSERT INTO nj_634_runs (client_ip, visitor_uid, kind, name, pages, chars, size, status, result, text, error)
                VALUES ('.$db->quote(self::client_ip()).', '.$db->quote(Visitor::uid()).',
                        '.$db->quote($kind).', '.$db->quote(mb_substr($name, 0, 255)).',
                        '.self::_int($summary['pages']).', '.self::_int($summary['chars']).', '.self::_int($summary['size']).',
                        '.$db->quote($status).', '.self::_jsonb($summary).', '.self::_q($text).', '.self::_q($error).')';

        $db->query(Database::INSERT, $sql, FALSE);

        return TRUE;
    }

    /** Последние прогоны посетителя. */
    public static function recent_runs(int $limit = 8): array
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, kind, name, pages, chars, size, status, error, created_at
             FROM nj_634_runs WHERE visitor_uid = '.self::_db()->quote(Visitor::uid()).'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            $out[] = [
                'id'         => (int) $r['id'],
                'kind'       => (string) $r['kind'],
                'name'       => (string) $r['name'],
                'pages'      => $r['pages'] === NULL ? NULL : (int) $r['pages'],
                'chars'      => $r['chars'] === NULL ? NULL : (int) $r['chars'],
                'size'       => $r['size'] === NULL ? NULL : (int) $r['size'],
                'status'     => (string) $r['status'],
                'error'      => (string) ($r['error'] ?? ''),
                'created_at' => (string) $r['created_at'],
            ];
        }

        return $out;
    }

    /** Извлечённый текст прогона по id (для «копировать/скачать»). */
    public static function run_text(int $run_id): ?array
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT id, kind, name, text, status, created_at
             FROM nj_634_runs WHERE id = '.(int) $run_id, FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        return [
            'id'         => (int) $row['id'],
            'kind'       => (string) $row['kind'],
            'name'       => (string) $row['name'],
            'text'       => (string) ($row['text'] ?? ''),
            'status'     => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    // ------------------------------------------------------------ схема

    public static function ensure_tables()
    {
        if (trim((string) getenv('NJ634_SCHEMA_AUTOCREATE')) !== '1')
        {
            return;
        }

        DB::query(NULL, <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_634_runs" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "client_ip"   VARCHAR(64)  NOT NULL DEFAULT '',
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "kind"        VARCHAR(20)  NOT NULL DEFAULT 'info',
    "name"        VARCHAR(255) NOT NULL DEFAULT '',
    "pages"       INTEGER      NULL,
    "chars"       INTEGER      NULL,
    "size"        INTEGER      NULL,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'ok',
    "result"      JSONB        NULL,
    "text"        TEXT         NULL,
    "error"       TEXT         NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_634_runs_ip_idx"   ON "nj_634_runs" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_634_runs_uid_idx"  ON "nj_634_runs" ("visitor_uid", "id" DESC);
SQL
        )->execute();
    }

    protected static function _int($v): string
    {
        return $v === NULL ? 'NULL' : (string) (int) $v;
    }

    protected static function _q($v): string
    {
        if ($v === NULL OR $v === '')
        {
            return 'NULL';
        }

        return self::_db()->quote((string) $v);
    }

    protected static function _jsonb($v): string
    {
        return $v === NULL ? 'NULL' : self::_db()->quote(json_encode($v, JSON_UNESCAPED_UNICODE)).'::jsonb';
    }
}
