<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Парсер PDF на чистом PHP (модуль NJ-634) — без внешних бинарников.
 *
 * Работает со «структурой файла», а не с байт-точным xref: находит объекты
 * по маркерам «N G obj … endobj», разбирает дерево страниц, декодирует
 * потоки содержимого (FlateDecode) и минимальным интерпретатором вынимает
 * текст (Tj/TJ/``, Td/TD/Tm/T*). Поддерживает текстовые PDF (не сканы).
 *
 * Ограничения (осознанные, для легковесной утилиты):
 *  * объектные потоки (/Type/ObjStm) и xref-стримы не разбираются — такие
 *    файлы отвергаются понятной ошибкой;
 *  * зашифрованные PDF не читаются (пароля нет и не будет);
 *  * текст из сканов/кривых не извлекается (это изображения).
 */
class Service_Nj634_Parser
{
    /** Объекты файла: num => [dict, stream|null, span]. */
    public static function objects(string $bytes): array
    {
        // отсекаем префикс до первого «N G obj», чтобы xref/комментарии не мешали
        if (preg_match_all('/(\d+)\s+(\d+)\s+obj\b/', $bytes, $m, PREG_OFFSET_CAPTURE) < 1)
        {
            return [];
        }

        $objects = [];
        $headers = [];

        foreach ($m[0] as $i => $cap)
        {
            $headers[] = [(int) $m[1][$i][0], (int) $m[2][$i][0], (int) $cap[1]];
        }

        $n = count($headers);

        for ($i = 0; $i < $n; $i++)
        {
            [$num, , $pos] = $headers[$i];

            if (isset($objects[$num]))
            {
                continue; // первое по файлу — каноническое
            }

            $limit = $i + 1 < $n ? $headers[$i + 1][2] : strlen($bytes);
            $end = strpos($bytes, 'endobj', $pos);

            if ($end === FALSE OR $end > $limit)
            {
                $end = $limit;
            }

            $span    = substr($bytes, $pos, $end - $pos);
            $dict    = $span;
            $stream  = NULL;
            $is_objstm = FALSE;

            if (preg_match('/\r?\nstream\r?\n/', $span, $sm, PREG_OFFSET_CAPTURE))
            {
                $dict   = substr($span, 0, $sm[0][1]);
                $after  = substr($span, $sm[0][1] + strlen($sm[0][0]));
                $endstm = preg_match('/\r?\nendstream/', $after, $em, PREG_OFFSET_CAPTURE)
                    ? $em[0][1] : strlen($after);
                $stream = substr($after, 0, $endstm);
            }

            // отрезаем заголовок «N G obj» из начала словаря — дальше он не нужен
            $dict = preg_replace('/^\s*\d+\s+\d+\s+obj\s*/', '', $dict);

            if (preg_match('~/Type\s*/ObjStm~', $dict))
            {
                $is_objstm = TRUE;
            }

            $objects[$num] = [
                'dict'      => $dict,
                'stream'    => $stream,
                'is_objstm' => $is_objstm,
            ];
        }

        return $objects;
    }

    /**
     * Причина, по которой парсер не может работать с файлом (для честного UI).
     * Покрывает то, что `objects()`/дерево страниц не поддерживают:
     * xref-стримы, объектные потоки (ObjStm), шифрование. NULL = причина неясна.
     */
    public static function unsupported_reason(string $bytes): ?string
    {
        $head = substr($bytes, 0, 4096);
        $tail = substr($bytes, max(0, strlen($bytes) - 4096));

        if (preg_match('~/Encrypt\b~', $bytes))
        {
            return 'PDF зашифрован паролем — такие файлы сервис не открывает.';
        }

        // xref-поток: в «голове»/«хвосте» ссылка на /Type /XRef и xref как stream
        if (preg_match('~\\bstartxref\\b~', $head) AND preg_match('~\\sstream\\s~', $tail)
            AND preg_match('~/Type\\s*/XRef~', $tail))
        {
            return 'PDF со сжатой таблицей объектов (xref-поток) — формат не поддерживается.';
        }

        // объектный поток: объекты упакованы внутрь /Type/ObjStm
        if (preg_match('~/Type\\s*/ObjStm~', $bytes))
        {
            return 'PDF упаковывает объекты в ObjStm-поток — формат не поддерживается.';
        }

        return NULL;
    }

    /** Откат назад по дереву /Pages: [catalog_num, root_num, page_nums[], page_size]. */
    public static function tree(array $objects): ?array
    {
        $catalog = NULL;
        $root    = NULL;

        foreach ($objects as $num => $o)
        {
            if (preg_match('~/Type\s*/Catalog\b~', $o['dict']))
            {
                $catalog = (int) $num;

                if (preg_match('~/Pages\s+(\d+)\s+\d+\s+R\b~', $o['dict'], $m))
                {
                    $root = (int) $m[1];
                }

                break;
            }
        }

        if ($root === NULL)
        {
            return NULL;
        }

        $pages   = [];
        $pagebox = NULL;
        $seen    = [];

        $walk = function ($num) use (&$walk, &$pages, &$pagebox, &$seen, $objects) {
            if (isset($seen[$num]) OR ! isset($objects[$num]))
            {
                return;
            }

            $seen[$num] = TRUE;
            $dict = $objects[$num]['dict'];

            if (preg_match('~/Type\s*/Pages?\b~', $dict, $t))
            {
                // /Kids [ ... ] — прямые ссылки на детей
                if (preg_match('~/Kids\s*\[((?:\s*\d+\s+\d+\s+R\s*)*)\]~', $dict, $k))
                {
                    preg_match_all('/(\d+)\s+\d+\s+R/', $k[1], $kids);

                    foreach ($kids[1] as $child)
                    {
                        $child_num = (int) $child;

                        if ( ! isset($objects[$child_num]))
                        {
                            continue;
                        }

                        $cdict = $objects[$child_num]['dict'];

                        if (preg_match('~/Type\s*/Pages\b~', $cdict))
                        {
                            $walk($child_num);
                        }
                        else
                        {
                            $pages[] = $child_num;
                        }
                    }
                }
            }

            // размер первой страницы (MediaBox) — для справки
            if ($pagebox === NULL AND preg_match('~/MediaBox\s*\[\s*([0-9.\- ]+?)\s*\]~', $dict, $b))
            {
                $pagebox = preg_split('/\s+/', trim($b[1]));
            }
        };

        $walk($root);

        return [
            'catalog'   => $catalog,
            'root'      => $root,
            'pages'     => $pages,
            'count'     => count($pages),
            'media_box' => $pagebox,
        ];
    }

    /** Декодировать поток по /Filter (поддерживаем FlateDecode и «без фильтра»). */
    public static function decode_stream(string $dict, string $stream): array
    {
        $filter = NULL;

        if (preg_match('~/Filter\s*\[([^\]]*)\]~', $dict, $fa))
        {
            preg_match_all('~/([A-Za-z0-9]+)~', $fa[1], $fs);
            $filter = $fs[1] ?? [];
        }
        elseif (preg_match('~/Filter\s*/([A-Za-z0-9]+)~', $dict, $f1))
        {
            $filter = [$f1[1]];
        }

        if ($filter === NULL)
        {
            return ['ok' => TRUE, 'data' => $stream, 'warn' => []];
        }

        $data = $stream;
        $warn = [];

        foreach ($filter as $f)
        {
            if ($f === 'FlateDecode' OR $f === 'Fl')
            {
                $dec = @gzuncompress($data);

                if ($dec === FALSE)
                {
                    // некоторые генераторы пишут «голый» deflate
                    $dec = @gzuncompress("\x78\x9c".substr($data, 2));

                    if ($dec === FALSE)
                    {
                        $dec = @inflate_init(ZLIB_ENCODING_RAW) !== FALSE ? self::_raw_inflate($data) : FALSE;
                    }
                }

                if ($dec === FALSE)
                {
                    return ['ok' => FALSE, 'error' => 'Не удалось распаковать поток (FlateDecode).', 'warn' => $warn];
                }

                $data = $dec;
            }
            elseif ($f === 'ASCIIHexDecode' OR $f === 'AHx')
            {
                $data = self::_decode_hex($data);
            }
            else
            {
                $warn[] = 'фильтр /'.$f.' не поддержан — текст может быть неполным';
            }
        }

        return ['ok' => TRUE, 'data' => $data, 'warn' => $warn];
    }

    protected static function _raw_inflate(string $data)
    {
        $ctx = @inflate_init(ZLIB_ENCODING_RAW);

        if ($ctx === FALSE)
        {
            return FALSE;
        }

        $out = @inflate_add($ctx, $data);

        return $out === FALSE ? FALSE : $out;
    }

    protected static function _decode_hex(string $data)
    {
        // PDF ASCIIHex: «>» — конец, не hex-символы игнорируются
        $clean = preg_replace('/[^0-9a-fA-F]/', '', $data);
        $clean = strlen($clean) % 2 ? $clean.'0' : $clean;

        return (string) @hex2bin($clean);
    }

    /** Карта «код -> unicode» из ToUnicode CMap (пишется нашим генератором). */
    public static function tounicode_map(array $objects): array
    {
        $map = [];

        foreach ($objects as $o)
        {
            if ($o['stream'] === NULL OR strpos($o['stream'], 'beginbfchar') === FALSE)
            {
                continue;
            }

            $stream = $o['stream'];

            // только внутри bfchar-блока (иначе захватим codespacerange и прочее)
            if ( ! preg_match_all('/beginbfchar\s+(.*?)endbfchar/s', $stream, $blocks))
            {
                continue;
            }

            foreach ($blocks[1] as $block)
            {
                if ( ! preg_match_all('~<([0-9A-Fa-f]{2,4})>\s*<([0-9A-Fa-f]+)>~', $block, $m, PREG_SET_ORDER))
                {
                    continue;
                }

                foreach ($m as $pair)
                {
                    $key = strtoupper(str_pad($pair[1], 4, '0', STR_PAD_LEFT));

                    try
                    {
                        $raw = hex2bin($pair[2]);

                        // пары-суррогаты/несколько кодов — хотя бы первый канонично
                        $map[$key] = $raw === FALSE
                            ? ''
                            : mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
                    }
                    catch (Throwable $e)
                    {
                        // пропуск некорректной пары
                    }
                }
            }
        }

        return $map;
    }

    /** Вытащить текст из прямого или косвенного /Contents страницы. */
    public static function page_text(array $objects, int $page_num, array $tounicode = []): array
    {
        if ( ! isset($objects[$page_num]))
        {
            return ['ok' => FALSE, 'error' => 'Объект страницы не найден.'];
        }

        $dict = $objects[$page_num]['dict'];
        $refs = [];

        if (preg_match('~/Contents\s+(\d+)\s+\d+\s+R\b~', $dict, $c))
        {
            $refs[] = (int) $c[1];
        }
        elseif (preg_match('~/Contents\s*\[((?:\s*\d+\s+\d+\s+R\s*)*)\]~', $dict, $ca))
        {
            preg_match_all('/(\d+)\s+\d+\s+R/', $ca[1], $cm);
            foreach ($cm[1] as $r)
            {
                $refs[] = (int) $r;
            }
        }

        $chunks = [];
        $warns  = [];

        foreach ($refs as $ref)
        {
            if ( ! isset($objects[$ref]) OR $objects[$ref]['stream'] === NULL)
            {
                continue;
            }

            $dec = self::decode_stream($objects[$ref]['dict'], $objects[$ref]['stream']);

            if ( ! $dec['ok'])
            {
                return $dec + ['warn' => $warns];
            }

            $warns  = array_merge($warns, $dec['warn']);
            $chunks[] = $dec['data'];
        }

        $raw = implode("\n", $chunks);
        $text = self::_extract_text($raw, $tounicode);

        return ['ok' => TRUE, 'text' => $text, 'warn' => $warns];
    }

    /** Минимальный интерпретатор content-операторов → строка текста. */
    protected static function _extract_text(string $content, array $tounicode = [], string $encoding = 'Identity-H'): string
    {
        // Для Identity-H текст приходит hex-строками кодов глифов (2 байта на код);
        // для прочих кодировок — литералами в кодировке шрифта (чаще WinAnsi/UTF-16BE).
        // Ловим: литерал (...) | hex-строка <...> | массив [...] | число | оператор.
        if (preg_match_all('/
            (?<lit>\((?:[^()\\\\]|\\\\.)*\))
          | (?<hex><[0-9A-Fa-f\s]*>)
          | (?<arr>\[[^\]]*\])
          | (?<num>-?\d+(?:\.\d+)?)
          | (?<op>Td|TD|Tm|Tj|TJ|BT|ET|T\*|\x27|")
        /x', $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1)
        {
            return '';
        }

        $out       = [];
        $line      = '';
        $pending   = NULL;   // текст из литерала/hex (уже с учётом tounicode)
        $pending_hex = FALSE;
        $arr       = NULL;
        $nums      = [];
        $cur_x     = PHP_FLOAT_MIN;
        $cur_y     = 0.0;

        $emit = function ($txt) use (&$line, &$cur_x) {
            if ($txt !== '')
            {
                if (trim($line) !== '' AND $cur_x === 0.0)
                {
                    // тексты на одной строке с нулевым смещением склеиваем без разрывов
                }

                $line .= $txt;
            }
        };

        $flush = function () use (&$out, &$line) {
            $t = rtrim($line);

            if ($t !== '')
            {
                $out[] = $t;
            }

            $line = '';
        };

        foreach ($m as $cap)
        {
            foreach (['lit', 'hex', 'arr', 'num', 'op'] as $g)
            {
                if (isset($cap[$g]) AND $cap[$g][1] !== -1)
                {
                    $val = $cap[$g][0];
                    break;
                }
            }

            switch ($g)
            {
                case 'lit':
                    $pending     = self::_decode_literal($val);
                    $pending_hex = FALSE;
                    break;

                case 'hex':
                    $pending     = self::_hex_to_text($val, $tounicode);
                    $pending_hex = TRUE;
                    break;

                case 'arr':
                    $arr = self::_decode_tj_array($val, $tounicode, $encoding);
                    break;

                case 'num':
                    $nums[] = (float) $val;

                    if (count($nums) > 8)
                    {
                        array_shift($nums);
                    }
                    break;

                case 'op':
                    switch ($val)
                    {
                        case 'Tj':
                            if ($pending !== NULL) { $emit($pending); $pending = NULL; }
                            if ($arr !== NULL)     { $emit(implode('', $arr)); $arr = NULL; }
                            break;

                        case 'TJ':
                            if ($arr !== NULL) { $emit(implode('', $arr)); $arr = NULL; }
                            break;

                        case "'":
                        case '"':
                            if ($pending !== NULL) { $emit($pending); $pending = NULL; }
                            $flush();
                            break;

                        case 'T*':
                            $flush();
                            break;

                        case 'Td':
                        case 'TD':
                            // два числа: tx ty; смена строки → разрыв
                            $vals = array_slice($nums, -2);

                            if (count($vals) >= 2 AND $vals[0] === 0.0)
                            {
                                $flush();
                            }
                            break;

                        case 'Tm':
                            // матрица: a b c d e f; e — x, f — y начала строки
                            $vals = array_slice($nums, -6);

                            if (count($vals) === 6)
                            {
                                $flush();
                                $cur_x = (float) $vals[4];
                                $cur_y = (float) $vals[5];
                            }
                            break;

                        case 'ET':
                        case 'BT':
                            break;
                    }
                    break;
            }
        }

        $flush();

        return trim(implode("\n", $out));
    }

    /** Hex-строка <....> → текст: при Identity-H это 16-битные коды глифов, проходящие ToUnicode. */
    protected static function _hex_to_text(string $hex, array $tounicode): string
    {
        $clean = preg_replace('/[^0-9A-Fa-f]/', '', $hex);

        if ($clean === '' OR strlen($clean) % 4 !== 0)
        {
            // некратная 4 длина: пробуем с 3-байтовым выравниванием (UTF-16BE без BOM)
            $clean = substr($clean, 0, strlen($clean) - (strlen($clean) % 4));
        }

        $out    = '';
        $buffer = '';
        $len    = strlen($clean);

        for ($i = 0; $i < $len; $i += 4)
        {
            $code = substr($clean, $i, 4);

            // 2-байтовый код: в таблице ToUnicode ключ = hex-код (4 знака)
            $key = strtoupper($code);

            if (isset($tounicode[$key]))
            {
                $out .= $tounicode[$key];
            }
            else
            {
                $buffer .= chr(hexdec(substr($code, 0, 2))).chr(hexdec(substr($code, 2, 2)));
            }
        }

        // непокрытые коды — трактуем как UTF-16BE (на случай обычного текста в hex)
        if ($buffer !== '')
        {
            $dec = @mb_convert_encoding($buffer, 'UTF-8', 'UTF-16BE');

            if ($dec !== FALSE)
            {
                $out .= $dec;
            }
        }

        return $out;
    }

    protected static function _decode_tj_array(string $arr, array $tounicode = [], string $encoding = 'Identity-H'): array
    {
        $inner = substr($arr, 1, -1);
        $out   = [];

        // элементы: (литерал) или <hex>
        if (preg_match_all('~\((?:[^()\\\\]|\\\\.)*\)|<[0-9A-Fa-f\s]*>~', $inner, $m))
        {
            foreach ($m[0] as $item)
            {
                if ($item[0] === '(')
                {
                    $out[] = self::_decode_literal($item);
                }
                else
                {
                    $out[] = self::_hex_to_text($item, $tounicode);
                }
            }
        }

        return $out;
    }

    protected static function _decode_literal(string $lit): string
    {
        $inner = substr($lit, 1, -1);

        return preg_replace_callback('~\\\\(.)|\\\\([0-7]{1,3})~', function ($m) {
            if (isset($m[2]) AND $m[2] !== '')
            {
                return chr(octdec($m[2]));
            }

            switch ($m[1])
            {
                case 'n': return "\n";
                case 'r': return "\r";
                case 't': return "\t";
                case 'b': return "\x08";
                case 'f': return "\x0C";
                default:  return $m[1];
            }
        }, $inner);
    }

    /** Краткие метаданные файла (для «Информация о PDF»). */
    public static function meta(string $bytes): array
    {
        if (substr($bytes, 0, 5) !== '%PDF-')
        {
            return ['ok' => FALSE, 'error' => 'Это не PDF: файл должен начинаться с %PDF-.'];
        }

        preg_match('/%PDF-(\d+\.\d+)/', substr($bytes, 0, 1024), $v);
        $version = isset($v[1]) ? $v[1] : '?';

        if ( ! function_exists('mb_check_encoding'))
        {
            return ['ok' => FALSE, 'error' => 'Нет расширения mbstring.'];
        }

        $objects = self::objects($bytes);

        if ( ! $objects)
        {
            return ['ok' => FALSE, 'error' => self::unsupported_reason($bytes)
                ?: 'Не удалось разобрать объекты PDF (возможно, xref-поток или неверный файл).'];
        }

        $tree = self::tree($objects);

        $info = [];

        foreach ([
            'Title' => 'название', 'Author' => 'автор', 'Subject' => 'тема',
            'Keywords' => 'ключевые слова', 'Creator' => 'создатель',
            'Producer' => 'программа', 'CreationDate' => 'создан', 'ModDate' => 'изменён',
        ] as $field => $label)
        {
            if (preg_match('~/'.$field.'\s*\((.*?)\)~s', $bytes, $f))
            {
                $info[$label] = self::_decode_literal('('.$f[1].')');
            }
        }

        $encrypted = preg_match('~/Encrypt\b~', $bytes) > 0;

        return [
            'ok'          => TRUE,
            'version'     => $version,
            'size'        => strlen($bytes),
            'pages'       => $tree ? $tree['count'] : 0,
            'encrypted'   => $encrypted,
            'linearized'  => (bool) preg_match('~/Linearized\b~', substr($bytes, 0, 2048)),
            'info'        => $info,
            'media_box'   => $tree && $tree['media_box'] ? array_map('floatval', $tree['media_box']) : NULL,
            'obj_count'   => count($objects),
        ];
    }

    /** Полное извлечение текста (все страницы). */
    public static function text(string $bytes, int $max_pages = 500): array
    {
        if (substr($bytes, 0, 5) !== '%PDF-')
        {
            return ['ok' => FALSE, 'error' => 'Это не PDF: файл должен начинаться с %PDF-.'];
        }

        if (preg_match('~/Encrypt\b~', $bytes))
        {
            return ['ok' => FALSE, 'error' => 'PDF зашифрован — извлечение текста невозможно.'];
        }

        $objects = self::objects($bytes);

        if ( ! $objects)
        {
            return ['ok' => FALSE, 'error' => self::unsupported_reason($bytes)
                ?: 'Не удалось разобрать объекты PDF (возможно, xref-поток или неверный файл).'];
        }

        $tree = self::tree($objects);

        if ($tree === NULL)
        {
            return ['ok' => FALSE, 'error' => 'Не найдено дерево страниц (/Pages).'];
        }

        $pages = array_slice($tree['pages'], 0, max(1, $max_pages));
        $tounicode = self::tounicode_map($objects);
        $parts = [];
        $warns = [];

        foreach ($pages as $pn)
        {
            $r = self::page_text($objects, $pn, $tounicode);

            if ( ! $r['ok'])
            {
                return $r;
            }

            $warns = array_merge($warns, $r['warn']);

            if ($r['text'] !== '')
            {
                $parts[] = $r['text'];
            }
        }

        $text = trim(implode("\n\n", $parts));

        return [
            'ok'       => TRUE,
            'text'     => $text,
            'pages'    => count($pages),
            'pages_in' => $tree['count'],
            'warn'     => array_values(array_unique($warns)),
        ];
    }
}
