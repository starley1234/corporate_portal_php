<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON-инструмент (модуль NJ-614) — чистые функции, БД и воркеры не нужны.
 *
 * Одна точка истины для страницы (/json), публичного API (/nj614/api/*) и
 * интеграций: форматирование, минификация, валидация, сортировка ключей и
 * структурная статистика. JS-зеркало живёт во views/614/script.php.
 *
 * Принципиально НЕ используем htmlspecialchars/json_encode с экранированием
 * слэшей для вывода — красивый JSON отдаётся «как есть», а рендер вьюхи сам
 * экранирует (HTML::chars) и подсвечивает.
 */
class Service_Nj614_Json {

    /** Максимум входного текста, символов (защита от мегабайтных вставок). */
    const MAX_INPUT = 4 * 1024 * 1024;

    /** Максимальная вложенность, после которой перестаём считать (защита от рекурсии). */
    const MAX_DEPTH = 512;

    /**
     * Основной прогон: разобрать, при желании отсортировать ключи и
     * пересобрать в нужном виде. Возвращает нейтральную структуру для
     * страницы и API.
     *
     * @param  string $text     исходный JSON (или почти JSON)
     * @param  int    $indent   0 (минификация) | 1..8 (отступы пробелами) | -1 (таб)
     * @param  bool   $sort_keys сортировать ключи объектов рекурсивно
     * @return array            ['ok','output','error','pos','stats','mode']
     */
    public static function format($text, $indent = 2, $sort_keys = FALSE)
    {
        $text = (string) $text;

        if ($text === '')
        {
            return ['ok' => FALSE, 'output' => '', 'error' => 'Вставьте JSON — поле пустое.', 'pos' => NULL, 'stats' => NULL, 'mode' => 'format'];
        }

        if (mb_strlen($text) > self::MAX_INPUT)
        {
            return ['ok' => FALSE, 'output' => '', 'error' => 'Слишком большой текст (лимит '.self::MAX_INPUT.' символов).', 'pos' => NULL, 'stats' => NULL, 'mode' => 'format'];
        }

        $value = json_decode($text, TRUE);

        if ($value === NULL && trim($text) !== 'null')
        {
            // разобрать не удалось — показываем позицию ошибки по последнему валидному префиксу
            $pos = self::error_position($text);

            return [
                'ok'     => FALSE,
                'output' => '',
                'error'  => 'Невалидный JSON: '.json_last_error_msg(),
                'pos'    => $pos,
                'stats'  => NULL,
                'mode'   => 'format',
            ];
        }

        if ($sort_keys)
        {
            self::sort_recursive($value);
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $minify = ($indent <= 0 && $indent !== -1);

        $output = self::encode($value, $indent, $flags);

        $stats = self::stats($value);

        return [
            'ok'     => TRUE,
            'output' => $output,
            'error'  => '',
            'pos'    => NULL,
            'stats'  => $stats,
            'mode'   => $minify ? 'minify' : 'format',
        ];
    }

    /** Валидация: только ok/error/pos/stats, без пересборки. */
    public static function validate($text)
    {
        $text = (string) $text;

        if ($text === '')
        {
            return ['ok' => FALSE, 'error' => 'Вставьте JSON — поле пустое.', 'pos' => NULL, 'stats' => NULL];
        }

        if (mb_strlen($text) > self::MAX_INPUT)
        {
            return ['ok' => FALSE, 'error' => 'Слишком большой текст.', 'pos' => NULL, 'stats' => NULL];
        }

        $value = json_decode($text, TRUE);

        if ($value === NULL && trim($text) !== 'null')
        {
            return ['ok' => FALSE, 'error' => 'Невалидный JSON: '.json_last_error_msg(), 'pos' => self::error_position($text), 'stats' => NULL];
        }

        return ['ok' => TRUE, 'error' => '', 'pos' => NULL, 'stats' => self::stats($value)];
    }

    /**
     * Пересборка значения в строку. Отступы: -1 = таб, 0 = минификация,
     * 1..8 = пробелы. Большие integer-числа не плывут (PHP_JSON... не используем
     * — значение уже приведено к своим типам при decode).
     */
    protected static function encode($value, $indent, $flags)
    {
        if ($indent === -1)
        {
            // JSON_PRETTY_PRINT отступы — пробелы; таб делаем заменой в готовом виде
            $pretty = json_encode($value, $flags | JSON_PRETTY_PRINT);
            $lines  = explode("\n", (string) $pretty);
            $out    = [];

            foreach ($lines as $line)
            {
                // считаем ведущие пробелы и заменяем каждые 4 на таб
                $lead = 0;
                while ($lead < strlen($line) && $line[$lead] === ' ')
                {
                    $lead++;
                }
                $out[] = str_repeat("\t", intdiv($lead, 4)).substr($line, $lead);
            }

            return implode("\n", $out);
        }

        if ($indent <= 0)
        {
            return json_encode($value, $flags);
        }

        $indent = max(1, min(8, $indent));

        if (defined('JSON_PRETTY_PRINT'))
        {
            // компактный способ задать свой отступ без внешних либ
            $pretty = json_encode($value, $flags | JSON_PRETTY_PRINT);
            $pad    = str_repeat(' ', $indent);

            return preg_replace_callback('/^(?:    )+/m', function ($m) use ($pad, $indent) {
                $count = substr_count($m[0], '    ');
                return str_repeat($pad, $count);
            }, $pretty);
        }

        return json_encode($value, $flags);
    }

    /** Рекурсивная сортировка ключей объектов (asort по ключам, без мутаций типов). */
    public static function sort_recursive(&$value)
    {
        if (is_array($value))
        {
            if (self::is_list($value))
            {
                foreach ($value as &$item)
                {
                    self::sort_recursive($item);
                }
                unset($item);
            }
            else
            {
                ksort($value, SORT_STRING);

                foreach ($value as &$item)
                {
                    self::sort_recursive($item);
                }
                unset($item);
            }
        }
    }

    /** PHP-список (массив с числовыми индексами 0..n-1) — это JSON-массив. */
    protected static function is_list(array $a)
    {
        if ($a === [])
        {
            return TRUE;
        }

        return array_keys($a) === range(0, count($a) - 1);
    }

    /**
     * Приблизительная позиция ошибки JSON: лёгкий сканер ловит хвостовую
     * запятую, непарную скобку и мусорный символ; иначе — конец текста.
     */
    public static function error_position($text)
    {
        $text = (string) $text;
        $n    = strlen($text);
        $in_str = FALSE;
        $esc    = FALSE;
        $depth  = 0;
        $stack  = [];

        for ($i = 0; $i < $n; $i++)
        {
            $ch = $text[$i];

            if ($in_str)
            {
                if ($esc)
                {
                    $esc = FALSE;
                }
                elseif ($ch === '\\')
                {
                    $esc = TRUE;
                }
                elseif ($ch === '"')
                {
                    $in_str = FALSE;
                }
                continue;
            }

            if ($ch === '"')
            {
                $in_str = TRUE;
                continue;
            }

            if (ctype_space($ch))
            {
                continue;
            }

            if ($ch === '{')
            {
                $depth++;
                $stack[] = '{';
                continue;
            }

            if ($ch === '[')
            {
                $depth++;
                $stack[] = '[';
                continue;
            }

            if ($ch === '}' || $ch === ']')
            {
                $expect = ($ch === '}') ? '{' : '[';

                if ($depth === 0 || end($stack) !== $expect)
                {
                    return $i; // непарная/лишняя закрывающая скобка
                }

                array_pop($stack);
                $depth--;

                // хвостовая запятая: , перед закрывающей скобкой
                for ($j = $i - 1; $j >= 0; $j--)
                {
                    if (ctype_space($text[$j]))
                    {
                        continue;
                    }
                    if ($text[$j] === ',')
                    {
                        return $i;
                    }
                    break;
                }
                continue;
            }
        }

        // ошибка глубже — показываем конец (там, где оборвалось или остался мусор)
        return $n > 0 ? $n - 1 : 0;
    }

    /**
     * Структурная статистика: количества и вложенность. Карту узлов не держим —
     * только счётчики, чтобы не расти по памяти на больших документах.
     */
    public static function stats($value)
    {
        $s = [
            'total_keys'   => 0,   // ключи объектов суммарно
            'objects'      => 0,
            'arrays'       => 0,
            'strings'      => 0,
            'numbers'      => 0,
            'booleans'     => 0,
            'nulls'        => 0,
            'max_depth'    => 0,
            'longest_key'  => 0,
            'longest_str'  => 0,
        ];

        self::walk_stats($value, $s, 0);

        return $s;
    }

    protected static function walk_stats($v, &$s, $depth)
    {
        if ($depth > self::MAX_DEPTH)
        {
            return;
        }

        $s['max_depth'] = max($s['max_depth'], $depth);

        if (is_array($v))
        {
            if (self::is_list($v))
            {
                $s['arrays']++;

                foreach ($v as $item)
                {
                    self::walk_stats($item, $s, $depth + 1);
                }
            }
            else
            {
                $s['objects']++;

                foreach ($v as $k => $item)
                {
                    $s['total_keys']++;
                    $s['longest_key'] = max($s['longest_key'], mb_strlen((string) $k));
                    self::walk_stats($item, $s, $depth + 1);
                }
            }

            return;
        }

        if (is_string($v))
        {
            $s['strings']++;
            $s['longest_str'] = max($s['longest_str'], mb_strlen($v));
        }
        elseif (is_int($v) || is_float($v))
        {
            $s['numbers']++;
        }
        elseif (is_bool($v))
        {
            $s['booleans']++;
        }
        elseif ($v === NULL)
        {
            $s['nulls']++;
        }
    }
}
