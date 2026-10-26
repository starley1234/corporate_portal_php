<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Генератор «текст → PDF» (модуль NJ-634) на чистом PHP.
 *
 * Модель: CID = GID шрифта (CIDToGIDMap /Identity), поэтому в контенте —
 * hex-коды глифов, а ToUnicode CMap ставит глиф↔Unicode для копипасты.
 * TrueType-шрифт встраивается целиком (DejaVu Sans ~700 КБ), кириллица
 * работает без внешних утилит. Страница A4, поля 2 см, ручной перенос.
 */
class Service_Nj634_Txt2pdf
{
    /** Генерация PDF по тексту. Возвращает ['ok'=>..] с 'pdf' (bytes) или 'error'. */
    public static function build(string $text, ?string $title = NULL): array
    {
        if ( ! function_exists('gzcompress') OR ! function_exists('mb_convert_encoding'))
        {
            return ['ok' => FALSE, 'error' => 'Для генерации нужны расширения zlib и mbstring.'];
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = rtrim($text, "\n");

        if (trim($text) === '')
        {
            return ['ok' => FALSE, 'error' => 'Пустой текст — генерировать нечего.'];
        }

        if (mb_strlen($text) > 300000)
        {
            return ['ok' => FALSE, 'error' => 'Текст слишком длинный (максимум 300 000 символов).'];
        }

        if ($title !== NULL AND trim($title) !== '')
        {
            $title = trim(mb_substr($title, 0, 200));
        }
        else
        {
            $title = NULL;
        }

        $font_path = self::_font_path();

        if ($font_path === NULL)
        {
            return ['ok' => FALSE, 'error' => 'Не найден TrueType-шрифт с кириллицей (DejaVu Sans). Укажите NJ634_FONT_TTF.'];
        }

        $font = self::_read_font($font_path);

        if ($font === NULL)
        {
            return ['ok' => FALSE, 'error' => 'Не удалось прочитать шрифт '.basename($font_path).' (нужна таблица cmap format 4/12).'];
        }

        // --- геометрия страницы (A4, поля 2 см) ---
        $W = 595.28;
        $H = 841.89;
        $margin = 56.7;
        $font_size = 11.0;
        $leading = 15.0;        // межстрочный
        $title_size = 16.0;
        $title_lead = 24.0;

        // --- разбивка на строки и страницы ---
        $lines_all = array_filter(array_map('rtrim', explode("\n", $text)), function ($l) {
            return $l !== '';
        });

        if ( ! $lines_all)
        {
            return ['ok' => FALSE, 'error' => 'Текст без строк — нечего разместить.'];
        }

        $avg = 0.55 * $font_size;
        $max_chars = max(10, (int) floor(($W - $margin * 2) / $avg));
        $lines_all = self::_wrap($lines_all, $max_chars);

        $pages = [];
        $y = $H - $margin;

        if ($title !== NULL)
        {
            $y -= $title_lead + 4;
        }

        $cur = ['title' => $title, 'lines' => []];

        foreach ($lines_all as $line)
        {
            if ($y - $leading < $margin)
            {
                $pages[] = $cur;
                $cur = ['title' => NULL, 'lines' => []];
                $y = $H - $margin;
            }

            $cur['lines'][] = $line;
            $y -= $leading;
        }

        $pages[] = $cur;

        // --- маппинг «символ → gid» (CID = GID) ---
        $cp2gid = [];
        $used_gids = [];

        $space_gid = $font['cmap'][0x20] ?? 0;
        $used_gids[$space_gid] = 0x20; // пробел всегда нужен

        $scan = $title !== NULL ? $title."\n".$text : $text;
        $len = mb_strlen($scan);

        for ($i = 0; $i < $len; $i++)
        {
            $c = mb_substr($scan, $i, 1);
            $cp = self::_codepoint($c);

            if ($cp === NULL OR $cp > 0xFFFF)
            {
                continue; // вне Basic Multilingual Plane — пропускаем (замена пробелом)
            }

            if (isset($cp2gid[$cp]))
            {
                continue;
            }

            $gid = $font['cmap'][$cp] ?? NULL;

            if ($gid === NULL)
            {
                $gid = $space_gid; // нет глифа — показываем пробел
            }

            if (isset($used_gids[$gid]))
            {
                continue; // глиф уже закреплён за другим символом — второй не повторяем
            }

            $used_gids[$gid] = $cp;
            $cp2gid[$cp] = $gid;
        }

        // ширины глифов (из hmtx, в тысячных em)
        $widths = [];

        foreach ($used_gids as $gid => $cp)
        {
            $adv = $font['hmtx'][$gid] ?? 1000;
            $widths[$gid] = (int) round(1000 * $adv / max(1, $font['unitsPerEm']));
        }

        // --- объекты PDF ---
        $objects = [];
        $n = 1;

        $fontfile_obj  = $n++;
        $fontdesc_obj  = $n++;
        $cidfont_obj   = $n++;
        $type0_obj     = $n++;
        $tounicode_obj = $n++;
        $res_obj       = $n++;

        $page_objs    = [];
        $content_objs = [];

        foreach ($pages as $idx => $p)
        {
            $content_objs[$idx] = $n++;
        }

        foreach ($pages as $idx => $p)
        {
            $page_objs[$idx] = $n++;
        }

        $root_obj = $n++;
        $cat_obj  = $n++;

        $objects[$fontfile_obj] = Service_Nj634_Writer::stream(
            $fontfile_obj, '/Length1 '.strlen($font['raw']), $font['raw']);

        $objects[$fontdesc_obj] = Service_Nj634_Writer::dict($fontdesc_obj,
            '/Type /FontDescriptor /FontName /DejaVuSans /FontFile2 '.$fontfile_obj.' 0 R'
            .' /Flags 32 /FontBBox ['.implode(' ', $font['bbox']).'] /ItalicAngle 0'
            .' /Ascent '.$font['ascent'].' /Descent '.$font['descent']
            .' /CapHeight '.$font['capheight'].' /StemV 80');

        $w_parts = [];

        foreach ($widths as $gid => $w)
        {
            $w_parts[] = $gid.' ['.$w.']';
        }

        $objects[$cidfont_obj] = Service_Nj634_Writer::dict($cidfont_obj,
            '/Type /Font /Subtype /CIDFontType2 /BaseFont /DejaVuSans'
            .' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
            .' /FontDescriptor '.$fontdesc_obj.' 0 R /CIDToGIDMap /Identity /DW 1000'
            .' /W ['.implode(' ', $w_parts).']');

        $objects[$type0_obj] = Service_Nj634_Writer::dict($type0_obj,
            '/Type /Font /Subtype /Type0 /BaseFont /DejaVuSans /Encoding /Identity-H'
            .' /DescendantFonts ['.$cidfont_obj.' 0 R] /ToUnicode '.$tounicode_obj.' 0 R');

        $objects[$tounicode_obj] = Service_Nj634_Writer::stream(
            $tounicode_obj, '', self::_tounicode($used_gids));

        $objects[$res_obj] = Service_Nj634_Writer::dict($res_obj,
            '/Font << /F1 '.$type0_obj.' 0 R >> /ProcSet [/PDF /Text]');

        $kids = implode(' ', array_map(function ($p) { return $p.' 0 R'; }, $page_objs));

        $objects[$root_obj] = Service_Nj634_Writer::dict($root_obj,
            '/Type /Pages /Kids ['.$kids.'] /Count '.count($pages));

        $objects[$cat_obj] = Service_Nj634_Writer::dict($cat_obj,
            '/Type /Catalog /Pages '.$root_obj.' 0 R');

        foreach ($pages as $idx => $page)
        {
            $ops = self::_content($page, $cp2gid, $space_gid,
                $font_size, $leading, $title_size, $title_lead, $margin, $H);

            $data = gzcompress($ops, 9);
            $compressed = $data !== FALSE ? $data : $ops;

            $objects[$content_objs[$idx]] = Service_Nj634_Writer::stream(
                $content_objs[$idx],
                $data !== FALSE ? '/Filter /FlateDecode' : '',
                $compressed);

            $objects[$page_objs[$idx]] = Service_Nj634_Writer::dict($page_objs[$idx],
                '/Type /Page /Parent '.$root_obj.' 0 R'
                .' /MediaBox [0 0 '.self::_n($W).' '.self::_n($H).']'
                .' /Resources '.$res_obj.' 0 R /Contents '.$content_objs[$idx].' 0 R');
        }

        $pdf = Service_Nj634_Writer::build($objects, $cat_obj, [
            '/ID' => '[<'.bin2hex(random_bytes(12)).'><'.bin2hex(random_bytes(12)).'>]',
        ]);

        if ($pdf === '')
        {
            return ['ok' => FALSE, 'error' => 'Не удалось собрать PDF-файл.'];
        }

        return ['ok' => TRUE, 'pdf' => $pdf, 'pages' => count($pages),
                'size' => strlen($pdf), 'glyphs' => count($used_gids)];
    }

    /** Контент одной страницы: Tf/Tm/Tj с hex-кодами глифов. */
    protected static function _content(array $page, array $cp2gid, int $space_gid, float $size, float $leading, float $ts, float $tl, float $margin, float $H): string
    {
        $ops = ["BT"];

        if ($page['title'] !== NULL)
        {
            $ops[] = '/F1 '.self::_n($ts).' Tf';
            $ops[] = '1 0 0 1 '.self::_n($margin).' '.self::_n($H - $margin).' Tm';
            $ops[] = self::_hex($page['title'], $cp2gid, $space_gid).' Tj';
            $y = $H - $margin - $tl - $leading;
        }
        else
        {
            $y = $H - $margin;
        }

        foreach ($page['lines'] as $line)
        {
            $y = max($margin, $y);
            $ops[] = '/F1 '.self::_n($size).' Tf';
            $ops[] = '1 0 0 1 '.self::_n($margin).' '.self::_n($y).' Tm';
            $ops[] = self::_hex($line, $cp2gid, $space_gid).' Tj';
            $y -= $leading;
        }

        $ops[] = 'ET';

        return implode("\n", $ops)."\n";
    }

    protected static function _hex(string $text, array $cp2gid, int $space_gid): string
    {
        $out = '';
        $len = mb_strlen($text);

        for ($i = 0; $i < $len; $i++)
        {
            $c = mb_substr($text, $i, 1);
            $cp = self::_codepoint($c);
            $gid = ($cp !== NULL AND isset($cp2gid[$cp])) ? $cp2gid[$cp] : $space_gid;
            $out .= strtoupper(str_pad(dechex($gid & 0xFFFF), 4, '0', STR_PAD_LEFT));
        }

        return '<'.$out.'>';
    }

    protected static function _tounicode(array $gid2cp): string
    {
        $pairs = [];
        ksort($gid2cp, SORT_NUMERIC);

        foreach ($gid2cp as $gid => $cp)
        {
            $pairs[] = '<'.strtoupper(str_pad(dechex($gid & 0xFFFF), 4, '0', STR_PAD_LEFT)).'> <'
                .strtoupper(str_pad(dechex($cp), 4, '0', STR_PAD_LEFT)).'>';
        }

        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            ."/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            ."/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            ."1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            .count($pairs)." beginbfchar\n".implode("\n", $pairs)."\nendbfchar\n"
            ."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    // ------------------------------------------------------------- шрифт (TTF)

    /**
     * Публичная загрузка готового к работе шрифта (для сервисов-надстроек):
     * ['unitsPerEm','bbox','ascent','descent','capheight','cmap','hmtx','raw'].
     * NULL — шрифт с кириллицей не найден (см. NJ634_FONT_TTF).
     */
    public static function load_font(): ?array
    {
        $path = self::_font_path();

        if ($path === NULL)
        {
            return NULL;
        }

        return self::_read_font($path);
    }

    /** Кодовая точка UTF-8 символа (NULL вне BMP). Публично — для надстроек. */
    public static function codepoint(string $char): ?int
    {
        return self::_codepoint($char);
    }

    /** Средняя ширина глифа в тысячных em. */
    public static function glyph_advance(array $font, int $gid): int
    {
        $adv = $font['hmtx'][$gid] ?? 1000;

        return (int) round(1000 * $adv / max(1, (int) ($font['unitsPerEm'] ?? 1000)));
    }

    /** Текст → строка 4-значных hex-кодов глифов (Identity-H), как в контенте. */
    public static function hex_of_gids(string $text, array $cp2gid, int $space_gid): string
    {
        $out = '';
        $len = mb_strlen($text);

        for ($i = 0; $i < $len; $i++)
        {
            $c  = mb_substr($text, $i, 1);
            $cp = self::_codepoint($c);
            $gid = ($cp !== NULL AND isset($cp2gid[$cp])) ? $cp2gid[$cp] : $space_gid;
            $out .= strtoupper(str_pad(dechex($gid & 0xFFFF), 4, '0', STR_PAD_LEFT));
        }

        return '<'.$out.'>';
    }

    /** ToUnicode CMap (gid => cp) для набора глифов — публично для надстроек. */
    public static function tounicode(array $gid2cp): string
    {
        return self::_tounicode($gid2cp);
    }

    /** Словарь /W (ширины глифов) для CIDFont — публично для надстроек. */
    public static function widths_array(array $widths): string
    {
        $parts = [];

        foreach ($widths as $gid => $w)
        {
            $parts[] = $gid.' ['.$w.']';
        }

        return implode(' ', $parts);
    }

    protected static function _font_path(): ?string
    {
        $env = trim((string) getenv('NJ634_FONT_TTF'));

        if ($env !== '' AND is_file($env) AND is_readable($env))
        {
            return $env;
        }

        $candidates = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ];

        foreach ($candidates as $c)
        {
            if (is_file($c) AND is_readable($c))
            {
                return $c;
            }
        }

        foreach ((array) glob('/usr/share/fonts/**/DejaVuSans.ttf') as $c)
        {
            if (is_file($c))
            {
                return $c;
            }
        }

        return NULL;
    }

    protected static function _read_font(string $path): ?array
    {
        $data = @file_get_contents($path);

        if ($data === FALSE OR strlen($data) < 12)
        {
            return NULL;
        }

        $num_tables = self::_u16($data, 4);
        $tables = [];

        for ($i = 0; $i < $num_tables; $i++)
        {
            $off = 12 + $i * 16;

            if ($off + 16 > strlen($data))
            {
                break;
            }

            $tables[substr($data, $off, 4)] = [
                'offset' => self::_u32($data, $off + 8),
                'length' => self::_u32($data, $off + 12),
            ];
        }

        if ( ! isset($tables['head'], $tables['hhea'], $tables['hmtx'], $tables['cmap'], $tables['maxp']))
        {
            return NULL;
        }

        $head = $tables['head']['offset'];
        $hhea = $tables['hhea']['offset'];

        return [
            'unitsPerEm' => max(1, self::_u16($data, $head + 18)),
            'bbox'       => [
                self::_s16($data, $head + 36), self::_s16($data, $head + 38),
                self::_s16($data, $head + 40), self::_s16($data, $head + 42),
            ],
            'ascent'     => self::_s16($data, $hhea + 4),
            'descent'    => self::_s16($data, $hhea + 6),
            'capheight'  => self::_s16($data, $hhea + 4),
            'cmap'       => self::_cmap($data, $tables['cmap']['offset']),
            'hmtx'       => self::_hmtx($data, $tables['hmtx']['offset'], self::_u16($data, $hhea + 34)),
            'raw'        => $data,
        ];
    }

    protected static function _cmap(string $d, int $off): array
    {
        $num = self::_u16($d, $off + 2);
        $chosen = NULL;

        for ($i = 0; $i < $num; $i++)
        {
            $p = $off + 4 + $i * 8;
            $plat = self::_u16($d, $p);
            $enc  = self::_u16($d, $p + 2);
            $soff = self::_u32($d, $p + 4);

            if (($plat === 0 AND $enc === 3) OR ($plat === 3 AND $enc === 1))
            {
                $chosen = $off + $soff;

                if ($plat === 0)
                {
                    break;
                }
            }
        }

        if ($chosen === NULL)
        {
            return [];
        }

        $format = self::_u16($d, $chosen);

        if ($format === 4)
        {
            return self::_cmap4($d, $chosen);
        }

        if ($format === 12)
        {
            return self::_cmap12($d, $chosen);
        }

        return [];
    }

    protected static function _cmap4(string $d, int $p): array
    {
        $seg_count = self::_u16($d, $p + 6) >> 1;
        $e = $p + 14;                    // endCode[]
        $s = $e + 2 * $seg_count + 2;    // startCode[]
        $dlt = $s + 2 * $seg_count;      // idDelta[]
        $off = $dlt + 2 * $seg_count;    // idRangeOffset[]
        $map = [];

        for ($i = 0; $i < $seg_count; $i++)
        {
            $start = self::_u16($d, $s + $i * 2);
            $end   = self::_u16($d, $e + $i * 2);

            if ($start === 0xFFFF)
            {
                break;
            }

            for ($c = $start; $c <= $end AND $c < 0xFFFF; $c++)
            {
                $delta = self::_s16($d, $dlt + $i * 2);
                $ro    = self::_u16($d, $off + $i * 2);
                $gid   = 0;

                if ($ro === 0)
                {
                    $gid = ($c + $delta) & 0xFFFF;
                }
                else
                {
                    $addr = $off + $i * 2 + $ro + 2 * ($c - $start);
                    $addr = $off + $i * 2 + $ro + 2 * ($c - $start);

                    $gid = self::_u16($d, $addr);
                    $gid = $gid === 0 ? 0 : ($gid + $delta) & 0xFFFF;
                }

                if ($gid !== 0)
                {
                    $map[$c] = $gid;
                }
            }
        }

        return $map;
    }

    protected static function _cmap12(string $d, int $p): array
    {
        $ngroups = self::_u32($d, $p + 12);
        $map = [];

        for ($g = 0; $g < $ngroups; $g++)
        {
            $base = $p + 16 + $g * 12;
            $start = self::_u32($d, $base);
            $end   = self::_u32($d, $base + 4);
            $gid0  = self::_u32($d, $base + 8);

            for ($c = $start; $c <= $end AND $c < 0x110000; $c++)
            {
                $map[$c] = $gid0 + ($c - $start);
            }
        }

        return $map;
    }

    protected static function _hmtx(string $d, int $off, int $count): array
    {
        $map = [];

        for ($i = 0; $i < $count; $i++)
        {
            $map[$i] = self::_u16($d, $off + $i * 4);
        }

        return $map;
    }

    // --------------------------------------------------------------- утилиты

    protected static function _codepoint(string $char): ?int
    {
        $u32 = @mb_convert_encoding($char, 'UTF-32BE', 'UTF-8');

        if ($u32 === FALSE OR strlen($u32) !== 4)
        {
            return NULL;
        }

        return hexdec(bin2hex($u32));
    }

    protected static function _wrap(array $lines, int $max_chars): array
    {
        $out = [];

        foreach ($lines as $line)
        {
            while (mb_strlen($line) > $max_chars)
            {
                $out[] = mb_substr($line, 0, $max_chars);
                $line = mb_substr($line, $max_chars);
            }

            $out[] = $line;
        }

        return $out;
    }

    protected static function _u16(string $d, int $off): int
    {
        return isset($d[$off + 1]) ? (ord($d[$off]) << 8) | ord($d[$off + 1]) : 0;
    }

    protected static function _s16(string $d, int $off): int
    {
        $v = self::_u16($d, $off);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    protected static function _u32(string $d, int $off): int
    {
        if ( ! isset($d[$off + 3]))
        {
            return 0;
        }

        return (ord($d[$off]) << 24) | (ord($d[$off + 1]) << 16) | (ord($d[$off + 2]) << 8) | ord($d[$off + 3]);
    }

    protected static function _n(float $v): string
    {
        return rtrim(rtrim(sprintf('%.3f', $v), '0'), '.');
    }
}
