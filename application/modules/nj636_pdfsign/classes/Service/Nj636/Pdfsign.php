<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Подписи и реквизиты PDF» (nj636_pdfsign) — серверный слой.
 *
 * Операции:
 *  * stamp  — список строчек реквизитов («Должность», «ФИО», «Дата», …)
 *             поверх страницы (обычно последней) как блок подписей/печатей;
 *  * count  — сколько визуальных подписей уже на документе (по эвристике
 *             словаря /Annots + маркеров текстового слоя);
 *  * strip  — убрать визуальные подписи (вырезать /Annots), не трогая тело.
 *
 * Все операции — чистый PHP поверх nj634 (Annotate/Парсер), без exec/shell,
 * без внешних бинарников. Строка подписи накладывается движком
 * Service_Nj634_Annotate::stamp, несколько строк идут блоками на одной странице.
 *
 * История — nj_636_runs (client_ip → rate limit через nj100_platform).
 */
class Service_Nj636_Pdfsign
{
    const RATE_PER_MINUTE = 5;
    const RATE_PER_HOUR   = 40;

    /** Конфиг модуля. */
    public static function cfg($key, $default = NULL)
    {
        $cfg = Kohana::$config->load('nj636_pdfsign');

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

    public static function max_bytes(): int
    {
        return (int) self::cfg('max_upload_mb', 15) * 1024 * 1024;
    }

    public static function rate_ok($ip)
    {
        self::ensure_tables();

        $counts = Service_Nj100_Ratelimit::window_counts(
            'nj_636_runs', (string) $ip, 'client_ip');

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
            return ['ok' => FALSE, 'error' => 'PDF зашифрован паролем — редактирование подписей недоступно.'];
        }

        $name = isset($f['name']) ? basename((string) $f['name']) : 'file.pdf';
        $name = mb_substr($name, 0, 180);

        return ['ok' => TRUE, 'name' => $name, 'bytes' => $bytes, 'size' => strlen($bytes)];
    }

    // ------------------------------------------------------------ операции

    /**
     * Наложить блок реквизитов на последнюю (или указанную) страницу.
     *
     * $lines — список ['Должность', 'ФИО', 'Дата: ...']; $run — позиции:
     *   position: 'bl' | 'br' | 'tl' | 'tr'; layout: 'stack' | 'two';
     *   size / opacity опциональны.
     */
    public static function stamp(string $bytes, array $lines, array $run = []): array
    {
        $lines = array_values(array_filter(array_map('trim', $lines), function ($l) {
            return $l !== '';
        }));

        if ( ! $lines)
        {
            return ['ok' => FALSE, 'error' => 'Нет строк реквизитов для наложения.'];
        }

        $max_lines = (int) self::cfg('max_sign_lines', 48);

        if (count($lines) > $max_lines)
        {
            return ['ok' => FALSE, 'error' => 'Слишком много строк (максимум '.$max_lines.').'];
        }

        $text = implode("\n", $lines);

        if (mb_strlen($text) > 900)
        {
            return ['ok' => FALSE, 'error' => 'Реквизиты слишком длинные (максимум 900 символов).'];
        }

        if ( ! class_exists('Service_Nj634_Annotate'))
        {
            return ['ok' => FALSE, 'error' => 'Движок наложения (nj634) не подключён.'];
        }

        $pages = $run['pages'] ?? 'last';
        $size  = (float) ($run['size'] ?? 0);
        $opacity = (float) ($run['opacity'] ?? 0);

        $result = Service_Nj634_Annotate::stamp($bytes, implode("\n", $lines), [
            'pos'       => (string) ($run['pos'] ?? 'bl'),
            'behind'    => FALSE,
            'pages'     => $pages,
            'size'      => $size <= 0 ? 11 : $size,
            'opacity'   => $opacity <= 0 ? 0.95 : $opacity,
            'underline' => ! empty($run['underline']),
        ]);

        if ( ! isset($result['ok']) OR ! $result['ok'])
        {
            return $result;
        }

        $result['lines'] = count($lines);

        return $result;
    }

    /** Эвристика числа визуальных подписей в документе. */
    public static function count(string $bytes): array
    {
        if (substr($bytes, 0, 5) !== '%PDF-')
        {
            return ['ok' => FALSE, 'error' => 'Это не PDF.'];
        }

        $annots = preg_match_all('~/Annot\b~', $bytes);

        // Поиск похожих на подпись маркеров в текстовом слое (без строгих связок)
        $text = '';

        if (class_exists('Service_Nj634_Parser'))
        {
            $t = Service_Nj634_Parser::text($bytes);

            if (isset($t['ok']) && $t['ok'])
            {
                $text = (string) $t['text'];
            }
        }

        $marker_hits = 0;
        $markers = ['подпись', 'Подпись', 'ПОДПИСЬ', 'signature', 'Signature', 'ФИО', 'подписи'];

        foreach ($markers as $m)
        {
            if ($text !== '' AND mb_strpos($text, $m) !== FALSE)
            {
                $marker_hits++;
            }
        }

        return [
            'ok'             => TRUE,
            'pages'          => class_exists('Service_Nj634_Parser')
                ? self::_page_count($bytes)
                : NULL,
            'annotations'    => (int) $annots,
            'text_markers'   => $marker_hits,
            'likely_signed'  => ($annots > 0 OR $marker_hits >= 2),
        ];
    }

    /** Убрать визуальные подписи-аннотации (не трогая текст/ресурсы). */
    public static function strip(string $bytes): array
    {
        if (substr($bytes, 0, 5) !== '%PDF-')
        {
            return ['ok' => FALSE, 'error' => 'Это не PDF.'];
        }

        if (preg_match('~/Encrypt\b~', $bytes))
        {
            return ['ok' => FALSE, 'error' => 'PDF зашифрован — очистка недоступна.'];
        }

        if ( ! class_exists('Service_Nj634_SplitMerge'))
        {
            return ['ok' => FALSE, 'error' => 'Ядро nj634 не подключено.'];
        }

        $parsed = Service_Nj634_SplitMerge::pages($bytes);

        if ( ! $parsed['ok'])
        {
            return $parsed;
        }

        $removed = 0;

        foreach ($parsed['objects'] as $num => $obj)
        {
            $dict = (string) $obj['dict'];

            if (preg_match_all('~/Annots?\s*(?:\[\s*(?:(?:\d+\s+\d+\s+R\s*)*)\])~', $dict, $sets))
            {
                foreach ($sets[0] as $k => $set)
                {
                    $dict = str_replace($set, '', $dict);
                    $removed += substr_count($set, ' 0 R');
                }
            }

            $parsed['objects'][$num]['dict'] = $dict;
        }

        $result = Service_Nj634_SplitMerge::emit($parsed['objects'], $parsed['pages'], count($parsed['pages']));

        if ( ! isset($result['ok']) OR ! $result['ok'])
        {
            return $result;
        }

        $result['removed_annots'] = $removed;

        return $result;
    }

    // ------------------------------------------------------------ история

    public static function record_run(string $name, string $kind, array $result, string $status = 'ok', ?string $error = NULL): bool
    {
        self::ensure_tables();

        $db   = self::_db();
        $pages = isset($result['pages']) ? (int) $result['pages'] : NULL;

        $db->query(Database::INSERT,
            'INSERT INTO nj_636_runs (client_ip, visitor_uid, name, kind, pages, status, result, error)
             VALUES ('.$db->quote(self::client_ip()).', '.$db->quote(Visitor::uid()).',
                     '.$db->quote(mb_substr($name, 0, 255)).', '.$db->quote($kind).',
                     '.($pages === NULL ? 'NULL' : $pages).',
                     '.$db->quote($status).', '.self::_jsonb($result).', '.self::_q($error).')', FALSE);

        return TRUE;
    }

    public static function recent_runs(int $limit = 6): array
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, name, kind, pages, status, error, created_at
             FROM nj_636_runs WHERE visitor_uid = '.self::_db()->quote(Visitor::uid()).'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            $out[] = [
                'id'         => (int) $r['id'],
                'name'       => (string) $r['name'],
                'kind'       => (string) $r['kind'],
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
        if (trim((string) getenv('NJ636_SCHEMA_AUTOCREATE')) !== '1')
        {
            return;
        }

        DB::query(NULL, <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_636_runs" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "client_ip"   VARCHAR(64)  NOT NULL DEFAULT '',
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "name"        VARCHAR(255) NOT NULL DEFAULT '',
    "kind"        VARCHAR(16)  NOT NULL DEFAULT 'stamp',
    "pages"       INTEGER      NULL,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'ok',
    "result"      JSONB        NULL,
    "error"       TEXT         NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_636_runs_ip_idx"  ON "nj_636_runs" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_636_runs_uid_idx" ON "nj_636_runs" ("visitor_uid", "id" DESC);
SQL
        )->execute();
    }

    // ------------------------------------------------------------- польза

    protected static function _page_count(string $bytes): int
    {
        try
        {
            $meta = Service_Nj634_Parser::meta($bytes);

            return isset($meta['ok']) && $meta['ok'] ? (int) $meta['pages'] : 0;
        }
        catch (Throwable $e)
        {
            return 0;
        }
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
