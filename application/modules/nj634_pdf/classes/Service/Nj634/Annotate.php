<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Наложение текста на страницы существующего PDF (общий движок NJ-634).
 *
 * Используется сервисами-надстройками:
 *  * nj637_watermark — полупрозрачный диагональный фон / отпечаток;
 *  * nj636_pdfsign  — строка подписи/виза поверх страницы.
 *
 * Как устроено (чистый PHP, без внешних бинарников):
 *  1. Разбираем файл (Service_Nj634_SplitMerge::pages) → объекты + страницы.
 *  2. Загружаем тот же шрифт с кириллицей, что и «Текст → PDF»
 *     (Service_Nj634_Txt2pdf::load_font), строим подмножество глифов.
 *  3. Для каждой выбранной страницы: свой контент-поток (FlateDecode) и
 *     СВОЙ словарь /Resources — это исходные ресурсы страницы, в которые
 *     под уникальным именем /FNJ влит наш шрифт (исходные /Font, /XObject,
 *     /ProcSet сохраняются — оригинальное содержимое продолжает рисоваться).
 *  4. Поток добавляется в /Contents страницы: для водяного знака — ПЕРВЫМ
 *     (за текстом), для подписи — ПОСЛЕДНИМ (поверх).
 *  5. Сборка через Service_Nj634_SplitMerge::emit (перенумерация + новый xref).
 *
 * Прозрачность без ExtGState (максимальная совместимость): «лёгкость» достигается
 * серым цветом заливки (0 = чёрный … 1 = белый). Чем меньше opacity — тем светлее.
 */
class Service_Nj634_Annotate
{
    const MAX_TEXT = 1200;     // символов в тексте наложения (блок многострочен)
    const MAX_LINES = 48;      // строк в блоке подписи
    const MAX_PAGES = 400;     // потолок страниц в исходнике (как в nj634)

    /** Полупрозрачный диагональный водяной знак ПОЗАДИ содержимого. */
    public static function watermark(string $bytes, string $text, array $opts = []): array
    {
        return self::_run($bytes, $text, ['diagonal' => TRUE, 'behind' => TRUE] + $opts);
    }

    /** Строка подписи/виза ПОВЕРХ содержимого. */
    public static function stamp(string $bytes, string $text, array $opts = []): array
    {
        return self::_run($bytes, $text, ['diagonal' => FALSE, 'behind' => FALSE] + $opts);
    }

    // ------------------------------------------------------------- ядро

    protected static function _run(string $bytes, string $text, array $opts): array
    {
        $diagonal = ! empty($opts['diagonal']);
        $behind   = ! empty($opts['behind']);
        $underline = ! empty($opts['underline']);
        $size     = (float) ($opts['size'] ?? ($diagonal ? 56.0 : 11.0));
        $opacity  = (float) ($opts['opacity'] ?? ($diagonal ? 0.12 : 0.9));
        $opacity  = max(0.02, min(0.98, $opacity));
        $rotation = (float) ($opts['rotation'] ?? ($diagonal ? 0.0 : 0.0));
        $size     = max(6.0, min(144.0, $size));

        $raw = str_replace(["\r\n", "\r"], "\n", (string) $text);

        if (trim(preg_replace('/\s+/u', '', $raw)) === '')
        {
            return ['ok' => FALSE, 'error' => 'Пустой текст наложения.'];
        }

        if (mb_strlen($raw) > self::MAX_TEXT)
        {
            return ['ok' => FALSE, 'error' => 'Текст наложения длиннее '.self::MAX_TEXT.' символов.'];
        }

        // Водяной знак — одна строка (схлопываем переносы). Подпись — блок строк.
        if ($diagonal)
        {
            $lines = [self::_collapse_inline($raw)];
        }
        else
        {
            $lines = [];

            foreach (explode("\n", $raw) as $line)
            {
                $line = self::_collapse_inline($line);

                if ($line !== '')
                {
                    $lines[] = $line;
                }
            }

            if ( ! $lines)
            {
                return ['ok' => FALSE, 'error' => 'Пустой текст наложения.'];
            }

            if (count($lines) > self::MAX_LINES)
            {
                $lines = array_slice($lines, 0, self::MAX_LINES);
            }
        }

        $parsed = Service_Nj634_SplitMerge::pages($bytes);

        if ( ! $parsed['ok'])
        {
            return $parsed;
        }

        $pages = $parsed['pages'];

        if (count($pages) > self::MAX_PAGES)
        {
            return ['ok' => FALSE, 'error' => 'Слишком много страниц (максимум '.self::MAX_PAGES.').'];
        }

        // какой набор страниц обрабатываем (нумерация с 1)
        $targets = self::_select_pages(count($pages), $opts);

        if (empty($targets))
        {
            return ['ok' => FALSE, 'error' => 'Диапазон страниц не покрывает ни одной страницы.'];
        }

        // шрифт
        $font = Service_Nj634_Txt2pdf::load_font();

        if ($font === NULL)
        {
            return ['ok' => FALSE, 'error' => 'Не найден TrueType-шрифт с кириллицей (DejaVu Sans). Укажите NJ634_FONT_TTF.'];
        }

        $space_gid = $font['cmap'][0x20] ?? 0;
        $full = implode(' ', $lines);

        [$cp2gid, $used_gids, $widths] = self::_build_font($font, $full, $space_gid);

        // hex-строки глифов и ширины строк
        $hexes = [];
        $tws   = [];

        foreach ($lines as $line)
        {
            $hexes[] = Service_Nj634_Txt2pdf::hex_of_gids($line, $cp2gid, $space_gid);
            $tws[]   = self::_text_width($line, $cp2gid, $widths, $space_gid, $size);
        }

        $tw     = max($tws);
        $leading = $size * 1.4;

        // объекты: основа + шрифтовой набор (в формате SplitMerge: dict + stream)
        $objects = $parsed['objects'];
        $n = max(array_keys($objects)) + 1;

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

        $count = 0;

        foreach ($targets as $page_index)
        {
            $page_num = $pages[$page_index];
            $page     = $objects[$page_num];

            [$W, $H] = self::_page_box($page['dict']);

            // координатчик блока (верх строки первой строки)
            [$x, $y] = self::_anchor($opts, $W, $H, $tw, $size, count($lines), $leading);

            $ops = $diagonal
                ? self::_content_diagonal($hexes[0], $size, $opacity, $rotation, $W, $H, $tws[0])
                : self::_content_block($hexes, $tws, $size, $opacity, $rotation, $x, $y, $leading, $underline, $H);

            $data = gzcompress($ops, 9);
            $compressed = $data !== FALSE ? $data : $ops;

            $content_obj = $n++;
            $res_obj     = $n++;

            // сливаем /Resources страницы с нашим шрифтом
            $objects[$res_obj] = [
                'dict'   => '<< '.self::_merged_resources($page['dict'], $objects, $type0_obj).' >>',
                'stream' => NULL,
            ];
            $objects[$content_obj] = [
                'dict'   => $data !== FALSE
                    ? '<< /Filter /FlateDecode /Length '.strlen($compressed).' >>'
                    : '<< /Length '.strlen($compressed).' >>',
                'stream' => $compressed,
            ];

            // вклеиваем новый поток в /Contents и переключаем /Resources на новый узел
            $objects[$page_num]['dict'] = self::_append_contents($page['dict'], $content_obj, $behind);
            $objects[$page_num]['dict'] = self::_set_resources($objects[$page_num]['dict'], $res_obj);
            $count++;
        }

        $result = Service_Nj634_SplitMerge::emit($objects, $pages, count($pages));

        if ( ! isset($result['ok']) OR ! $result['ok'])
        {
            return $result;
        }

        $result['stamped_pages'] = $count;
        $result['text']          = implode("\n", $lines);

        return $result;
    }

    // ------------------------------------------------------ контент-потоки

    /** Сжать внутренние пробельные последовательности в один пробел. */
    protected static function _collapse_inline(string $line): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $line));
    }

    /** Диагональная строка через центр страницы (вращение углом). */
    protected static function _content_diagonal(string $hex, float $size, float $opacity, float $rotation, float $W, float $H, float $tw): string
    {
        $gray   = self::_gray($opacity);
        $angle  = $rotation !== 0.0 ? $rotation : 35.0; // классический наклон
        $rad    = deg2rad($angle);
        $cos    = cos($rad);
        $sin    = sin($rad);
        $cx     = $W / 2;
        $cy     = $H / 2;

        // матрица: вращение углом angle вокруг (cx, cy)
        $e = $cx - $cos * $cx + $sin * $cy;
        $f = $cy - $sin * $cx - $cos * $cy;

        return "q\n"
            .self::_n($cos).' '.self::_n($sin).' '.self::_n(-$sin).' '.self::_n($cos).' '
            .self::_n($e).' '.self::_n($f)." cm\n"
            .self::_n($gray)." g\n"
            .'/FNJ '.self::_n($size)." Tf\n"
            .'1 0 0 1 '.self::_n(-$tw / 2).' 0 '."Tm\n"
            .$hex." Tj\n"
            ."Q\n";
    }

    /** Блок строк (подпись): каждая строка убывает по y, подчёркивание сверху. */
    protected static function _content_block(array $hexes, array $tws, float $size, float $opacity, float $rotation, float $x, float $y, float $leading, bool $underline, float $H): string
    {
        $gray   = self::_gray($opacity);
        $ops    = "q\n".self::_n($gray)." g\n";
        $line_y = $y;

        foreach ($hexes as $i => $hex)
        {
            // Текст
            $ops .= '/FNJ '.self::_n($size)." Tf\n";
            $ops .= '1 0 0 1 '.self::_n($x).' '.self::_n(max(0.0, $line_y))." Tm\n";
            $ops .= $hex." Tj\n";

            // Подчёркивание-линия под строкой
            if ($underline)
            {
                $lx = max(12.0, $x - 8.0);
                $lw = $tws[$i] + 16.0;

                if ($lx + $lw > 680.0)
                {
                    $lw = 680.0 - $lx;
                }

                $ly = max(14.0, $line_y - $size * 0.55);
                $ops .= $lx.' '.$ly.' '.self::_n($lw)." 0.6 re f\n";
            }

            $line_y -= $leading;
        }

        $ops .= "Q\n";

        return $ops;
    }

    // ------------------------------------------------------------- шрифт

    /** Подмножество глифов под текст наложения. */
    protected static function _build_font(array $font, string $text, int $space_gid): array
    {
        $cp2gid   = [];
        $used_gids = [$space_gid => 0x20];
        $scan     = $text;
        $len      = mb_strlen($scan);

        for ($i = 0; $i < $len; $i++)
        {
            $c  = mb_substr($scan, $i, 1);
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

        // ширины (тысячные em)
        $widths = [];

        foreach ($used_gids as $gid => $cp)
        {
            $widths[$gid] = Service_Nj634_Txt2pdf::glyph_advance($font, $gid);
        }

        return [$cp2gid, $used_gids, $widths];
    }

    /** Ширина строки в пунктах при данном кегле. */
    protected static function _text_width(string $text, array $cp2gid, array $widths, int $space_gid, float $size): float
    {
        $tw = 0.0;
        $len = mb_strlen($text);

        for ($i = 0; $i < $len; $i++)
        {
            $c  = mb_substr($text, $i, 1);
            $cp = Service_Nj634_Txt2pdf::codepoint($c);
            $gid = ($cp !== NULL AND isset($cp2gid[$cp])) ? $cp2gid[$cp] : $space_gid;
            $tw += ($widths[$gid] ?? 1000) / 1000.0 * $size;
        }

        return $tw;
    }

    // ------------------------------------------------------------ страницы

    /** Номера обрабатываемых страниц (индексы 0-based). */
    protected static function _select_pages(int $total, array $opts): array
    {
        $spec = $opts['pages'] ?? 'all';
        $mode = (string) $spec;

        if ($mode === 'first')
        {
            return [0];
        }

        if ($mode === 'last')
        {
            return [max(0, $total - 1)];
        }

        if ($mode === 'range' OR (is_string($spec) AND preg_match('/^\d+-\d+$/', $spec)))
        {
            [$first, $last] = self::_range($spec, $total);
        }
        elseif (is_int($spec) OR ctype_digit((string) $spec))
        {
            $first = (int) $spec;
            $last  = $first;
        }
        else
        {
            $first = 1;
            $last  = $total;
        }

        $first = max(1, $first);
        $last  = min($total, max($first, $last));

        return $first <= $total ? range($first - 1, $last - 1) : [];
    }

    /** Разобрать диапазон [first,last] из спеки (для «range»). */
    protected static function _range($spec, int $total): array
    {
        if (preg_match('/^(\d+)-(\d+)?$/', (string) $spec, $m))
        {
            $first = (int) $m[1];
            $last  = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $total;

            return [$first, $last];
        }

        return [1, $total];
    }

    /** MediaBox страницы (фолбэк — A4). */
    protected static function _page_box(string $dict): array
    {
        if (preg_match('~/MediaBox\s*\[\s*([0-9.\- ]+?)\s*\]~', $dict, $m))
        {
            $nums = preg_split('/\s+/', trim($m[1]));
            $nums = array_values(array_filter($nums, 'is_numeric'));

            if (count($nums) >= 4)
            {
                return [(float) $nums[2] - (float) $nums[0], (float) $nums[3] - (float) $nums[1]];
            }
        }

        return [595.28, 841.89];
    }

    /** Опорная точка блока: x и y ВЕРХНЕЙ строки (позиционные пресеты). */
    protected static function _anchor(array $opts, float $W, float $H, float $tw, float $size, int $lines, float $leading): array
    {
        $pos = (string) ($opts['pos'] ?? 'bl');
        $margin = 56.7;
        $block_h = $leading * max(1, $lines);

        switch ($pos)
        {
            case 'tl':
                return [$margin, $H - $margin];

            case 'tr':
                return [$W - $margin - $tw, $H - $margin];

            case 'br':
                return [$W - $margin - $tw, $margin + $block_h];

            case 'center':
                return [$W / 2 - $tw / 2, $H / 2 + $block_h / 2];

            case 'bl':
            default:
                return [$margin, $margin + $block_h];
        }

        if (isset($opts['x'], $opts['y']))
        {
            // уже вернули сверху; компилятор этого не знает
            return [(float) $opts['x'], (float) $opts['y']];
        }

        return [$margin, $margin + $block_h];
    }

    /** Содержимое /Resources страницы без обёртки «<< >>» (или пустая строка). */
    protected static function _raw_resources(string $page_dict, array $objects): string
    {
        // ресурсы отдельным объектом
        if (preg_match('~/Resources\s+(\d+)\s+\d+\s+R\b~', $page_dict, $m)
            AND isset($objects[(int) $m[1]]))
        {
            return trim((string) preg_replace('/^<<\s*|\s*>>$/s', '', (string) $objects[(int) $m[1]]['dict']));
        }

        // inline-ресурсы
        $inline = self::_inline_resources($page_dict);

        if ($inline !== NULL)
        {
            return trim((string) preg_replace('/^<<\s*|\s*>>$/s', '', $inline));
        }

        return '';
    }

    /** Ресурсы страницы + наш шрифт /FNJ (исходные ключи сохраняются). */
    protected static function _merged_resources(string $page_dict, array $objects, int $type0_obj): string
    {
        $inner = self::_raw_resources($page_dict, $objects);

        if ($inner !== '')
        {
            $inner .= ' ';
        }

        // влить шрифт в существующий /Font (или создать), не затирая исходные
        if (preg_match('~/Font\s*<<\s*(.*?)\s*>>~s', $inner, $fm))
        {
            $inner = str_replace($fm[0], '/Font << '.$fm[1].' /FNJ '.$type0_obj.' 0 R >>', $inner);
        }
        else
        {
            $inner .= '/Font << /FNJ '.$type0_obj.' 0 R >>';
        }

        if (preg_match('~/ProcSet\s*\[~', $inner) === 0)
        {
            $inner .= ' /ProcSet [/PDF /Text]';
        }

        return trim($inner);
    }

    /** Inline-ресурсы страницы '/Resources << ... >>' (или NULL). */
    protected static function _inline_resources(string $dict): ?string
    {
        if ( ! preg_match('~/Resources\s*(<<)~', $dict, $m, PREG_OFFSET_CAPTURE))
        {
            return NULL;
        }

        return self::_balanced_span($dict, $m[1][1]);
    }

    /** Переключить /Resources страницы на новый узел-ресурсов. */
    protected static function _set_resources(string $dict, int $res_obj): string
    {
        if (preg_match('~/Resources\s+\d+\s+\d+\s+R\b~', $dict))
        {
            return (string) preg_replace('~/Resources\s+\d+\s+\d+\s+R\b~', '/Resources '.$res_obj.' 0 R', $dict, 1);
        }

        if (preg_match('~/Resources\s*<<~', $dict, $m, PREG_OFFSET_CAPTURE))
        {
            $start  = $m[0][1];
            $double = strpos($dict, '<<', $start);
            $span   = $double === FALSE ? NULL : self::_balanced_span($dict, $double);

            if ($span !== NULL)
            {
                return substr($dict, 0, $start).'/Resources '.$res_obj.' 0 R'
                    .substr($dict, $double + strlen($span));
            }
        }

        // ресурсов нет — добавим перед закрывающим «>>»
        return (string) preg_replace('~(?=>>\s*$)~', '/Resources '.$res_obj.' 0 R ', $dict, 1);
    }

    /** Найти закрывающий «>>» парного «<<» с позиции открытия. */
    protected static function _balanced_span(string $dict, int $open): ?string
    {
        $len   = strlen($dict);
        $depth = 0;
        $i     = $open;

        while ($i < $len)
        {
            $two = substr($dict, $i, 2);

            if ($two === '<<')
            {
                $depth++;
                $i += 2;
                continue;
            }

            if ($two === '>>')
            {
                $depth--;

                if ($depth === 0)
                {
                    return substr($dict, $open, $i + 2 - $open);
                }

                $i += 2;
                continue;
            }

            $i++;
        }

        return NULL;
    }

    /** Вклеить новый поток в /Contents страницы (в начало или в конец). */
    protected static function _append_contents(string $page_dict, int $content_obj, bool $behind): string
    {
        if (preg_match('~/Contents\s*\[\s*((?:\d+\s+\d+\s+R\s*)+)\]~', $page_dict, $m))
        {
            $list = $m[1].$content_obj.' 0 R ';

            if ( ! $behind)
            {
                $list = trim($m[1]).' '.$content_obj.' 0 R ';
            }

            return str_replace($m[0], '/Contents ['.trim($list).']', $page_dict);
        }

        if (preg_match('~/Contents\s+(\d+)\s+\d+\s+R\b~', $page_dict, $m))
        {
            $array = $behind
                ? $content_obj.' 0 R '.$m[1].' 0 R'
                : $m[1].' 0 R '.$content_obj.' 0 R';

            return str_replace($m[0], '/Contents ['.$array.']', $page_dict);
        }

        // без /Contents — просто ставим наш поток
        return preg_replace('~(?=>>\s*$)~', '/Contents '.$content_obj.' 0 R ', $page_dict, 1);
    }

    // ------------------------------------------------------------- утилиты

    /** Серый цвет заливки из «непрозрачности» (0 — чёрный, 1 — белый). */
    protected static function _gray(float $opacity): float
    {
        return 1.0 - $opacity;
    }

    protected static function _n(float $v): string
    {
        return rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
    }
}
