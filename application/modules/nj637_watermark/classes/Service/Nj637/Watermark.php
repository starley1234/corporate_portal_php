<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Водяные знаки на PDF» (nj637_watermark) — серверный слой.
 *
 * Наслаивает текст на страницы существующего PDF поверх общего движка
 * nj634 (Service_Nj634_Annotate::watermark/stamp): чистый PHP, без exec,
 * шрифт с кириллицей встраивается, исходные ресурсы страниц сохраняются.
 *
 * Модель данных:
 *  nj_637_runs — история наложений (имя файла, страницы, статус, режим,
 *  текст наложения обрезается лимитом). Счётчик rate limit — по этой же
 *  таблице (client_ip) через общий слой nj100_platform.
 */
class Service_Nj637_Watermark
{
    const RATE_PER_MINUTE = 5;
    const RATE_PER_HOUR   = 40;

    /** Конфиг модуля. */
    public static function cfg($key, $default = NULL)
    {
        $cfg = Kohana::$config->load('nj637_watermark');

        return $cfg ? $cfg->get($key, $default) : $default;
    }

    public static function client_ip()
    {
        if (class_exists('Service_Nj100_Ratelimit'))
        {
            return Service_Nj100_Ratelimit::client_ip();
        }

        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    protected static function _db()
    {
        return Database::instance();
    }

    /** Максимальный размер принимаемого PDF, байт. */
    public static function max_bytes(): int
    {
        return (int) self::cfg('max_upload_mb', 15) * 1024 * 1024;
    }

    public static function rate_ok($ip)
    {
        self::ensure_tables();

        $counts = Service_Nj100_Ratelimit::window_counts(
            'nj_637_runs', (string) $ip, 'client_ip');

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

        if ( ! is_uploaded_file($f['tmp_name']))
        {
            return ['ok' => FALSE, 'error' => 'Некорректная загрузка файла.'];
        }

        if ((int) $f['size'] > self::max_bytes())
        {
            return ['ok' => FALSE, 'error' => 'Файл больше лимита '.self::cfg('max_upload_mb', 15).' МБ.'];
        }

        $bytes = @file_get_contents($f['tmp_name']);

        if ($bytes === FALSE)
        {
            return ['ok' => FALSE, 'error' => 'Не удалось прочитать файл.'];
        }

        if (substr($bytes, 0, 5) !== '%PDF-')
        {
            return ['ok' => FALSE, 'error' => 'Файл не является PDF (нет магии %PDF-).'];
        }

        if (preg_match('~/Encrypt\b~', $bytes))
        {
            return ['ok' => FALSE, 'error' => 'PDF зашифрован паролем — наложение недоступно.'];
        }

        $name = isset($f['name']) ? basename((string) $f['name']) : 'file.pdf';
        $name = mb_substr($name, 0, 180);

        return ['ok' => TRUE, 'name' => $name, 'bytes' => $bytes, 'size' => strlen($bytes)];
    }

    // ------------------------------------------------------------ операция

    public static function apply(string $bytes, string $text, array $opts = []): array
    {
        $text  = trim((string) $text);
        $limit = (int) self::cfg('max_text_chars', 400);

        if ($text === '')
        {
            return ['ok' => FALSE, 'error' => 'Введите текст знака.'];
        }

        if (mb_strlen($text) > $limit)
        {
            return ['ok' => FALSE, 'error' => 'Текст знака длиннее '.$limit.' символов.'];
        }

        if ( ! class_exists('Service_Nj634_Annotate'))
        {
            return ['ok' => FALSE, 'error' => 'Движок наложения (nj634) не подключён.'];
        }

        $mode = (string) ($opts['mode'] ?? 'diagonal');

        if ($mode === 'stamp')
        {
            $anno = [
                'diagonal'  => FALSE,
                'behind'    => FALSE,
                'pos'       => (string) ($opts['pos'] ?? 'br'),
                'size'      => (float) ($opts['size'] ?? 11),
                'opacity'   => (float) ($opts['opacity'] ?? 0.9),
                'underline' => ! empty($opts['underline']),
                'pages'     => $opts['pages'] ?? 'all',
            ];

            if ((float) $anno['size'] <= 0)
            {
                unset($anno['size']);
            }

            if ((float) $anno['opacity'] <= 0)
            {
                unset($anno['opacity']);
            }

            $result = Service_Nj634_Annotate::stamp($bytes, $text, $anno);

            $result['mode'] = 'stamp';
        }
        else
        {
            $anno = [
                'diagonal' => TRUE,
                'behind'   => TRUE,
                'size'     => (float) ($opts['size'] ?? 56),
                'opacity'  => (float) ($opts['opacity'] ?? 0.12),
                'pages'    => $opts['pages'] ?? 'all',
            ];

            if ((float) $anno['size'] <= 0)
            {
                unset($anno['size']);
            }

            if ((float) $anno['opacity'] <= 0)
            {
                unset($anno['opacity']);
            }

            $result = Service_Nj634_Annotate::watermark($bytes, $text, $anno);

            $result['mode'] = 'diagonal';
        }

        return $result;
    }

    // ------------------------------------------------------------ история

    public static function record_run(string $name, array $result, string $status = 'ok', ?string $error = NULL): bool
    {
        self::ensure_tables();

        $db = self::_db();

        $pages = isset($result['pages']) ? (int) $result['pages'] : NULL;

        $db->query(Database::INSERT,
            'INSERT INTO nj_637_runs (client_ip, visitor_uid, name, mode, pages, status, result, error)
             VALUES ('.$db->quote(self::client_ip()).', '.$db->quote(Visitor::uid()).',
                     '.$db->quote(mb_substr($name, 0, 255)).',
                     '.$db->quote((string) ($result['mode'] ?? 'diagonal')).',
                     '.($pages === NULL ? 'NULL' : $pages).',
                     '.$db->quote($status).', '.self::_jsonb($result).', '.self::_q($error).')', FALSE);

        return TRUE;
    }

    public static function recent_runs(int $limit = 6): array
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, name, mode, pages, status, error, created_at
             FROM nj_637_runs WHERE visitor_uid = '.self::_db()->quote(Visitor::uid()).'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            $out[] = [
                'id'         => (int) $r['id'],
                'name'       => (string) $r['name'],
                'mode'       => (string) $r['mode'],
                'pages'      => $r['pages'] === NULL ? NULL : (int) $r['pages'],
                'status'     => (string) $r['status'],
                'error'      => (string) ($r['error'] ?? ''),
                'created_at' => (string) $r['created_at'],
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------ схема

    public static function ensure_tables()
    {
        if (trim((string) getenv('NJ637_SCHEMA_AUTOCREATE')) !== '1')
        {
            return;
        }

        DB::query(NULL, <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_637_runs" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "client_ip"   VARCHAR(64)  NOT NULL DEFAULT '',
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "name"        VARCHAR(255) NOT NULL DEFAULT '',
    "mode"        VARCHAR(16)  NOT NULL DEFAULT 'diagonal',
    "pages"       INTEGER      NULL,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'ok',
    "result"      JSONB        NULL,
    "error"       TEXT         NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_637_runs_ip_idx"  ON "nj_637_runs" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_637_runs_uid_idx" ON "nj_637_runs" ("visitor_uid", "id" DESC);
SQL
        )->execute();
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
