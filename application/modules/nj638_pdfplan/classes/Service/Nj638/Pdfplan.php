<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Конструктор А4» (nj638_pdfplan) — серверный слой-генератор.
 *
 * Собирает аккуратный документ А4 на чистом PHP поверх шрифтового движка
 * nj634 (тот же DejaVu Sans, глифы Identity-H, ToUnicode для копипасты).
 * Умеет:
 *  * шапка: заголовок, подзаголовок, дата + разделительная линия;
 *  * тело: 1 или 2 колонки, перенос по словам с учётом ширины глифов;
 *  * подвал каждой страницы: «Страница X из Y» + свой текст подвала.
 *
 * Без exec/shell, без внешних бинарников. История — nj_638_runs
 * (client_ip → rate limit через nj100_platform).
 */
class Service_Nj638_Pdfplan
{
    const RATE_PER_MINUTE = 5;
    const RATE_PER_HOUR   = 40;

    const W = 595.28;      // A4 в пунктах
    const H = 841.89;

    /** Конфиг модуля. */
    public static function cfg($key, $default = NULL)
    {
        $cfg = Kohana::$config->load('nj638_pdfplan');

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

    public static function rate_ok($ip)
    {
        self::ensure_tables();

        $counts = Service_Nj100_Ratelimit::window_counts(
            'nj_638_runs', (string) $ip, 'client_ip');

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

    // ------------------------------------------------------------ генерация

    /**
     * Собрать документ А4.
     *
     * @param array $doc [
     *   'title'    => string,        // заголовок шапки
     *   'subtitle' => ?string,       // подзаголовок
     *   'date'     => ?string,       // дата справа в шапке
     *   'footer'   => ?string,       // текст подвала (слева)
     *   'text'     => string,        // тело
     *   'columns'  => 1|2,
     *   'size'     => float (9..14), // кегль тела
     * ]
     */
    public static function build(array $doc): array
    {
        $title    = self::_collapse($doc['title'] ?? '');
        $subtitle = self::_collapse($doc['subtitle'] ?? '');
        $date     = self::_collapse($doc['date'] ?? '');
        $footer   = self::_collapse($doc['footer'] ?? '');
        $body     = str_replace(["\r\n", "\r"], "\n", (string) ($doc['text'] ?? ''));

        if (trim($title) === '' AND trim($body) === '')
        {
            return ['ok' => FALSE, 'error' => 'Заполните хотя бы заголовок или текст документа.'];
        }

        if (mb_strlen($body) > (int) self::cfg('max_text_chars', 120000))
        {
            return ['ok' => FALSE, 'error' => 'Текст длиннее лимита (120 000 символов).'];
        }

        $columns = (int) ($doc['columns'] ?? 1);
        $columns = $columns === 2 ? 2 : 1;

        $size = (float) ($doc['size'] ?? 11);
        $size = max(9.0, min(14.0, $size));

        // шрифт
        if ( ! function_exists('gzcompress') OR ! function_exists('mb_convert_encoding'))
        {
            return ['ok' => FALSE, 'error' => 'Нужны расширения zlib и mbstring.'];
        }

        $font = Service_Nj634_Txt2pdf::load_font();

        if ($font === NULL)
        {
            return ['ok' => FALSE, 'error' => 'Не найден TrueType-шрифт с кириллицей. Укажите NJ634_FONT_TTF.'];
        }

        $space_gid = $font['cmap'][0x20] ?? 0;

        $all_text = implode(' ', array_filter([$title, $subtitle, $date, $footer, $body], function ($s) {
            return $s !== '';
        }));

        [$cp2gid, $used_gids, $widths] = self::_build_font($font, $all_text, $space_gid);

        $hex  = function ($s) use ($cp2gid, $space_gid) {
            return Service_Nj634_Txt2pdf::hex_of_gids($s, $cp2gid, $space_gid);
        };

        $adv = function ($s, $pt) use ($cp2gid, $widths, $space_gid) {
            return self::_measure($s, $cp2gid, $widths, $space_gid, $pt);
        };

        // геометрия
        $margin  = 56.7;
        $title_pt   = $size + 5;
        $sub_pt     = $size - 1;
        $body_lead  = $size * 1.32;
        $head_height = 0.0;

        if ($title !== '')   { $head_height += $title_pt * 1.3; }
        if ($subtitle !== ''){ $head_height += $sub_pt * 1.3; }
        if ($date !== '' AND $head_height < $title_pt * 1.3 + 4) { /* дата в той же строке-шапке */ }

        $top_y    = self::H - $margin;
        $header_block = $head_height > 0 ? $head_height + 22 : 0;
        $body_top = $top_y - $header_block;
        $body_bottom = $margin + 26; // место подвала

        // перенос тела по колонкам
        $col_w = ($columns === 2)
            ? (self::W - $margin * 2 - 24) / 2
            : self::W - $margin * 2;

        $lines = self::_layout_body($body, $col_w, $size, $adv);

        // разбивка линий по страницам/колонкам
        $lines_per_col = max(1, (int) floor(($body_top - $body_bottom) / $body_lead));

        // поток «страница → колонка → линии»
        $cols = [];
        $col  = [];

        foreach ($lines as $line)
        {
            if (count($col) >= $lines_per_col)
            {
                $cols[] = $col;
                $col    = [];
            }

            $col[] = $line;
        }

        if ($col)
        {
            $cols[] = $col;
        }

        $pages_count = max(1, (int) ceil(count($cols) / $columns));

        // --- объекты PDF ---
        $objects = [];
        $n = 1;

        $fontfile_obj = $n++;
        $fontdesc_obj = $n++;
        $cidfont_obj  = $n++;
        $type0_obj    = $n++;
        $tounicode_obj = $n++;

        $objects[$fontfile_obj] = [
            'dict'   => '<< /Length1 '.strlen($font['raw']).' /Length '.strlen($font['raw']).' >>',
            'stream' => $font['raw'],
        ];
        $objects[$fontdesc_obj] = [
            'dict'   => '<< /Type /FontDescriptor /FontName /DejaVuSans /FontFile2 '.$fontfile_obj.' 0 R'
                .' /Flags 32 /FontBBox ['.implode(' ', $font['bbox']).'] /ItalicAngle 0'
                .' /Ascent '.$font['ascent'].' /Descent '.$font['descent']
                .' /CapHeight '.$font['capheight'].' /StemV 80 >>',
            'stream' => NULL,
        ];
        $objects[$cidfont_obj] = [
            'dict'   => '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /DejaVuSans'
                .' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
                .' /FontDescriptor '.$fontdesc_obj.' 0 R /CIDToGIDMap /Identity /DW 1000'
                .' /W ['.Service_Nj634_Txt2pdf::widths_array($widths).'] >>',
            'stream' => NULL,
        ];
        $objects[$type0_obj] = [
            'dict'   => '<< /Type /Font /Subtype /Type0 /BaseFont /DejaVuSans /Encoding /Identity-H'
                .' /DescendantFonts ['.$cidfont_obj.' 0 R] /ToUnicode '.$tounicode_obj.' 0 R >>',
            'stream' => NULL,
        ];
        $tounicode_stream = Service_Nj634_Txt2pdf::tounicode($used_gids);
        $objects[$tounicode_obj] = [
            'dict'   => '<< /Length '.strlen($tounicode_stream).' >>',
            'stream' => $tounicode_stream,
        ];

        $res_obj = $n++;
        $objects[$res_obj] = [
            'dict'   => '<< /Font << /F1 '.$type0_obj.' 0 R >> /ProcSet [/PDF /Text] >>',
            'stream' => NULL,
        ];

        $content_objs = [];
        $page_objs    = [];

        for ($p = 0; $p < $pages_count; $p++)
        {
            $content_objs[$p] = $n++;
        }

        for ($p = 0; $p < $pages_count; $p++)
        {
            $page_objs[$p] = $n++;
        }

        $root_obj = $n++;
        $cat_obj  = $n++;

        // контент страниц
        for ($p = 0; $p < $pages_count; $p++)
        {
            $ops = self::_page_content($p, $pages_count, $columns, $cols,
                $lines_per_col, $title, $subtitle, $date, $footer,
                $size, $title_pt, $sub_pt, $body_lead, $margin,
                $body_top, self::W, self::H, $hex, $adv);

            $data = gzcompress($ops, 9);
            $compressed = $data !== FALSE ? $data : $ops;

            $objects[$content_objs[$p]] = [
                'dict'   => $data !== FALSE
                    ? '<< /Filter /FlateDecode /Length '.strlen($compressed).' >>'
                    : '<< /Length '.strlen($compressed).' >>',
                'stream' => $compressed,
            ];

            $objects[$page_objs[$p]] = [
                'dict'   => '<< /Type /Page /Parent '.$root_obj.' 0 R'
                    .' /MediaBox [0 0 '.self::_n(self::W).' '.self::_n(self::H).']'
                    .' /Resources '.$res_obj.' 0 R /Contents '.$content_objs[$p].' 0 R >>',
                'stream' => NULL,
            ];
        }

        $kids = implode(' ', array_map(function ($r) { return $r.' 0 R'; }, $page_objs));

        $objects[$root_obj] = [
            'dict'   => '<< /Type /Pages /Kids ['.$kids.'] /Count '.$pages_count.' >>',
            'stream' => NULL,
        ];
        $objects[$cat_obj] = [
            'dict'   => '<< /Type /Catalog /Pages '.$root_obj.' 0 R >>',
            'stream' => NULL,
        ];

        // собрать готовый PDF (перенумерация не нужна: нумеровали вручную подряд)
        $pdf = Service_Nj634_Writer::build(
            array_map(function ($obj) {
                return $obj['dict'] . ($obj['stream'] !== NULL
                    ? "\nstream\n".$obj['stream']."\nendstream"
                    : '');
            }, $objects),
            $cat_obj,
            ['/ID' => '[<'.bin2hex(random_bytes(12)).'> <'.bin2hex(random_bytes(12)).'>]']
        );

        if ($pdf === '')
        {
            return ['ok' => FALSE, 'error' => 'Не удалось собрать PDF-файл.'];
        }

        return [
            'ok'      => TRUE,
            'pdf'     => $pdf,
            'pages'   => $pages_count,
            'columns' => $columns,
            'size'    => strlen($pdf),
        ];
    }

    // ------------------------------------------------------ контент страницы

    protected static function _page_content(
        int $page, int $total_pages, int $columns, array $cols, int $cols_per_page,
        string $title, string $subtitle, string $date, string $footer,
        float $size, float $title_pt, float $sub_pt, float $lead, float $margin,
        float $body_top, float $W, float $H, callable $hex, callable $adv
    ): string {
        $ops = ["BT"];
        $y = $H - $margin;

        // --- шапка на каждой странице ---
        if ($page === 0 AND $title !== '')
        {
            $ops[] = '/F1 '.self::_n($title_pt).' Tf';
            $ops[] = '1 0 0 1 '.self::_n($margin).' '.self::_n($y).' Tm';
            $ops[] = $hex($title).' Tj';
            $y -= $title_pt * 1.3;
        }

        if ($page === 0 AND $subtitle !== '')
        {
            $ops[] = '/F1 '.self::_n($sub_pt).' Tf';
            $ops[] = '0.4 0.4 0.45 rg';
            $ops[] = '1 0 0 1 '.self::_n($margin).' '.self::_n($y).' Tm';
            $ops[] = $hex($subtitle).' Tj';
            $ops[] = '0 0 0 rg';
            $y -= $sub_pt * 1.3;
        }

        if ($page === 0 AND $date !== '')
        {
            $w = $adv($date, $sub_pt);
            $ops[] = '/F1 '.self::_n($sub_pt).' Tf';
            $ops[] = '0.35 0.35 0.4 rg';
            $ops[] = '1 0 0 1 '.self::_n($W - $margin - $w).' '.self::_n($H - $margin).' Tm';
            $ops[] = $hex($date).' Tj';
            $ops[] = '0 0 0 rg';
        }

        // линия под шапкой (первая страница)
        if ($page === 0 AND ($title !== '' OR $subtitle !== ''))
        {
            $line_y = $H - $margin - 16;
            $ops[] = '0.55 0.55 0.6 RG 1 w';
            $ops[] = $margin.' '.self::_n($line_y).' m '.self::_n($W - $margin).' '.self::_n($line_y).' S';
        }

        // --- тело (колонки этой страницы) ---
        $start_col = $page * $columns;

        for ($c = 0; $c < $columns; $c++)
        {
            $idx = $start_col + $c;

            if ( ! isset($cols[$idx]))
            {
                continue;
            }

            $col_x = $columns === 2
                ? $margin + $c * (($W - $margin * 2) / 2 + 16)
                : $margin;

            $line_index = 0;

            foreach ($cols[$idx] as $line)
            {
                if ($line === '')
                {
                    $line_index++;
                    continue;
                }

                // верхняя строка колонки: ниже шапки, шаг вниз — lead
                $line_y = $body_top - $line_index * $lead;
                $ops[] = '/F1 '.self::_n($size).' Tf';
                $ops[] = '1 0 0 1 '.self::_n($col_x).' '.self::_n(max(30.0, $line_y)).' Tm';
                $ops[] = $hex($line).' Tj';
                $line_index++;
            }
        }

        // --- подвал ---
        $foot = 'Страница '.($page + 1).' из '.$total_pages;
        $fw   = $adv($foot, $sub_pt);
        $ops[] = '/F1 '.self::_n($sub_pt).' Tf';
        $ops[] = '0.4 0.4 0.45 rg';
        $ops[] = '1 0 0 1 '.self::_n($W / 2 - $fw / 2).' '.self::_n($margin - 10).' Tm';
        $ops[] = $hex($foot).' Tj';

        if ($footer !== '')
        {
            $ops[] = '1 0 0 1 '.self::_n($margin).' '.self::_n($margin - 10).' Tm';
            $ops[] = $hex($footer).' Tj';
        }

        $ops[] = '0 0 0 rg';
        $ops[] = 'ET';

        return implode("\n", $ops)."\n";
    }

    // ------------------------------------------------------------- раскладка

    /** Перенос тела по строкам с учётом ширины глифов. */
    protected static function _layout_body(string $body, float $col_w, float $size, callable $adv): array
    {
        $out = [];

        foreach (explode("\n", $body) as $para)
        {
            $para = trim((string) $para);

            if ($para === '')
            {
                $out[] = '';
                continue;
            }

            $words = preg_split('/\s+/u', $para);
            $line  = '';

            foreach ($words as $word)
            {
                $candidate = $line === '' ? $word : $line.' '.$word;

                if ($adv($candidate, $size) <= $col_w OR $line === '')
                {
                    $line = $candidate;
                }
                else
                {
                    $out[] = $line;
                    $line  = $word;
                }
            }

            if ($line !== '')
            {
                $out[] = $line;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------- шрифт/утилиты

    protected static function _build_font(array $font, string $text, int $space_gid): array
    {
        $cp2gid   = [];
        $used_gids = [$space_gid => 0x20];
        $len      = mb_strlen($text);

        for ($i = 0; $i < $len; $i++)
        {
            $c  = mb_substr($text, $i, 1);
            $cp = Service_Nj634_Txt2pdf::codepoint($c);

            if ($cp === NULL OR $cp > 0xFFFF)
            {
                continue;
            }

            $gid = $font['cmap'][$cp] ?? $space_gid;

            if (isset($used_gids[$gid]))
            {
                continue;
            }

            $used_gids[$gid] = $cp;
            $cp2gid[$cp]     = $gid;
        }

        $widths = [];

        foreach ($used_gids as $gid => $cp)
        {
            $widths[$gid] = Service_Nj634_Txt2pdf::glyph_advance($font, $gid);
        }

        return [$cp2gid, $used_gids, $widths];
    }

    protected static function _measure(string $text, array $cp2gid, array $widths, int $space_gid, float $size): float
    {
        $w = 0.0;
        $len = mb_strlen($text);

        for ($i = 0; $i < $len; $i++)
        {
            $c  = mb_substr($text, $i, 1);
            $cp = Service_Nj634_Txt2pdf::codepoint($c);
            $gid = ($cp !== NULL AND isset($cp2gid[$cp])) ? $cp2gid[$cp] : $space_gid;
            $w += ($widths[$gid] ?? 1000) / 1000.0 * $size;
        }

        return $w;
    }

    protected static function _collapse($v): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $v));
    }

    // ------------------------------------------------------------ история

    public static function record_run(string $title, array $result, string $status = 'ok', ?string $error = NULL): bool
    {
        self::ensure_tables();

        $db   = self::_db();
        $pages = isset($result['pages']) ? (int) $result['pages'] : NULL;

        $db->query(Database::INSERT,
            'INSERT INTO nj_638_runs (client_ip, visitor_uid, title, pages, columns, status, result, error)
             VALUES ('.$db->quote(self::client_ip()).', '.$db->quote(Visitor::uid()).',
                     '.$db->quote(mb_substr($title, 0, 255)).',
                     '.($pages === NULL ? 'NULL' : $pages).',
                     '.(int) ($result['columns'] ?? 1).',
                     '.$db->quote($status).', '.self::_jsonb($result).', '.self::_q($error).')', FALSE);

        return TRUE;
    }

    public static function recent_runs(int $limit = 6): array
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, title, pages, columns, status, error, created_at
             FROM nj_638_runs WHERE visitor_uid = '.self::_db()->quote(Visitor::uid()).'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ((array) $rows as $r)
        {
            $out[] = [
                'id'         => (int) $r['id'],
                'title'      => (string) $r['title'],
                'pages'      => $r['pages'] === NULL ? NULL : (int) $r['pages'],
                'columns'    => (int) $r['columns'],
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
        if (trim((string) getenv('NJ638_SCHEMA_AUTOCREATE')) !== '1')
        {
            return;
        }

        DB::query(NULL, <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_638_runs" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "client_ip"   VARCHAR(64)  NOT NULL DEFAULT '',
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "title"       VARCHAR(255) NOT NULL DEFAULT '',
    "pages"       INTEGER      NULL,
    "columns"     SMALLINT     NOT NULL DEFAULT 1,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'ok',
    "result"      JSONB        NULL,
    "error"       TEXT         NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_638_runs_ip_idx"  ON "nj_638_runs" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_638_runs_uid_idx" ON "nj_638_runs" ("visitor_uid", "id" DESC);
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

    protected static function _n(float $v): string
    {
        return rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
    }
}
