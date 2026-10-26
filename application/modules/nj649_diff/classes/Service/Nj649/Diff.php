<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сравнение текстов (модуль NJ-649) — чистые функции, БД и воркеры не нужны.
 *
 * Отвечает на вопрос «что изменилось»: построчное сравнение двух версий с
 * подсветкой изменённых слов внутри строки, сводка и готовый unified diff
 * (стандартный формат заплаток, его понимают git и редакторы).
 *
 * Алгоритм. Сначала срезаются одинаковые начало и конец. Дальше ищутся
 * «якоря» — строки, встречающиеся РОВНО ОДИН раз в обоих текстах: их порядок
 * почти наверняка сохранился, и по ним задача разваливается на короткие
 * независимые куски (приём известен как patience diff). Только внутри таких
 * кусков включается полный перебор (LCS) — иначе на документе в тысячу строк
 * квадратичная матрица съела бы память впустую.
 */
class Service_Nj649_Diff {

    /** Больше символов в одном поле не принимаем */
    const MAX_CHARS = 300000;

    /** Больше строк — и таблица становится нечитаемой, а память дорогой */
    const MAX_LINES = 4000;

    /** Потолок клеток матрицы LCS внутри одного куска */
    const LCS_CELLS = 150000;

    /** Потолок слов в строке для подсветки слов (длиннее — только строка целиком) */
    const WORD_CELLS = 40000;

    /** Строк контекста в unified diff */
    const CONTEXT = 3;

    /**
     * Параметры сравнения для UI.
     *
     * @return array code => [title, hint]
     */
    public static function options()
    {
        return [
            'case'  => ['title' => 'Не различать регистр', 'hint' => '«Договор» и «договор» — одно и то же'],
            'space' => ['title' => 'Игнорировать пробелы', 'hint' => 'Лишние отступы и двойные пробелы не считаются изменением'],
            'empty' => ['title' => 'Пропускать пустые строки', 'hint' => 'Пустые строки не попадают в сравнение'],
            'words' => ['title' => 'Подсвечивать слова', 'hint' => 'Внутри изменённой строки видно, какие слова отличаются'],
        ];
    }

    /**
     * Сравнить два текста.
     *
     * @param  string $left
     * @param  string $right
     * @param  array  $opts case|space|empty|words
     * @return array ['ok','error','rows','stats','unified']
     */
    public static function compare($left, $right, array $opts = [])
    {
        $left  = (string) $left;
        $right = (string) $right;

        if (strlen($left) > self::MAX_CHARS OR strlen($right) > self::MAX_CHARS)
        {
            return self::_error('Слишком длинный текст: принимаем до '
                .number_format(self::MAX_CHARS, 0, ',', "\xC2\xA0").' символов в каждом поле.');
        }

        $a = self::_lines($left, $opts);
        $b = self::_lines($right, $opts);

        if (count($a) > self::MAX_LINES OR count($b) > self::MAX_LINES)
        {
            return self::_error('Слишком много строк: сравниваем до '.self::MAX_LINES.' строк в каждом тексте.');
        }

        $ka = array_map(static function ($l) use ($opts) { return self::_key($l, $opts); }, $a);
        $kb = array_map(static function ($l) use ($opts) { return self::_key($l, $opts); }, $b);

        $ops = [];
        self::_walk($ka, $kb, 0, count($ka), 0, count($kb), $ops);

        $rows  = self::_rows($ops, $a, $b, ! empty($opts['words']));
        $stats = self::_stats($rows, count($a), count($b));

        return [
            'ok'      => TRUE,
            'error'   => '',
            'rows'    => $rows,
            'stats'   => $stats,
            'unified' => self::unified($ops, $a, $b),
        ];
    }

    /**
     * Текст → массив строк (с учётом «пропускать пустые»).
     *
     * @return array
     */
    protected static function _lines($text, array $opts)
    {
        $text  = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = ($text === '') ? [] : explode("\n", $text);

        if ( ! empty($opts['empty']))
        {
            $lines = array_values(array_filter($lines, static function ($l) {
                return trim($l) !== '';
            }));
        }

        return $lines;
    }

    /**
     * Ключ сравнения строки: то, по чему решаем «строки одинаковые».
     *
     * @return string
     */
    protected static function _key($line, array $opts)
    {
        $key = (string) $line;

        if ( ! empty($opts['space']))
        {
            $key = preg_replace('/\s+/u', ' ', $key);
            $key = trim((string) $key);
        }

        if ( ! empty($opts['case']))
        {
            $key = mb_strtolower($key, 'UTF-8');
        }

        return $key;
    }

    /**
     * Рекурсивный разбор отрезка: общий префикс/суффикс → якоря → LCS.
     *
     * @param array $a   ключи левого текста
     * @param array $b   ключи правого текста
     * @param int   $a0  начало отрезка слева
     * @param int   $a1  конец (не включается)
     * @param int   $b0
     * @param int   $b1
     * @param array $ops накопитель операций
     */
    protected static function _walk(array $a, array $b, $a0, $a1, $b0, $b1, array &$ops)
    {
        // одинаковое начало
        while ($a0 < $a1 AND $b0 < $b1 AND $a[$a0] === $b[$b0])
        {
            $ops[] = ['type' => 'eq', 'a' => $a0++, 'b' => $b0++];
        }

        // одинаковый конец — откладываем, чтобы дописать после середины
        $tail = [];

        while ($a1 > $a0 AND $b1 > $b0 AND $a[$a1 - 1] === $b[$b1 - 1])
        {
            $tail[] = ['type' => 'eq', 'a' => --$a1, 'b' => --$b1];
        }

        if ($a0 === $a1 OR $b0 === $b1)
        {
            // одна сторона кончилась — остальное удалено либо добавлено
            for ($i = $a0; $i < $a1; $i++)
            {
                $ops[] = ['type' => 'del', 'a' => $i, 'b' => NULL];
            }

            for ($j = $b0; $j < $b1; $j++)
            {
                $ops[] = ['type' => 'ins', 'a' => NULL, 'b' => $j];
            }
        }
        else
        {
            $anchors = self::_anchors($a, $b, $a0, $a1, $b0, $b1);

            if ($anchors)
            {
                $pa = $a0;
                $pb = $b0;

                foreach ($anchors as $pair)
                {
                    list($ai, $bi) = $pair;
                    self::_walk($a, $b, $pa, $ai, $pb, $bi, $ops);
                    $ops[] = ['type' => 'eq', 'a' => $ai, 'b' => $bi];
                    $pa = $ai + 1;
                    $pb = $bi + 1;
                }

                self::_walk($a, $b, $pa, $a1, $pb, $b1, $ops);
            }
            else
            {
                self::_lcs($a, $b, $a0, $a1, $b0, $b1, $ops);
            }
        }

        for ($i = count($tail) - 1; $i >= 0; $i--)
        {
            $ops[] = $tail[$i];
        }
    }

    /**
     * Якоря: строки, встречающиеся ровно один раз и там, и там, взятые в
     * возрастающем порядке (наибольшая возрастающая подпоследовательность).
     *
     * @return array [[индекс слева, индекс справа], …]
     */
    protected static function _anchors(array $a, array $b, $a0, $a1, $b0, $b1)
    {
        $count_a = [];
        $count_b = [];

        for ($i = $a0; $i < $a1; $i++)
        {
            $line = $a[$i];
            $count_a[$line] = isset($count_a[$line]) ? [$count_a[$line][0] + 1, $count_a[$line][1]] : [1, $i];
        }

        for ($j = $b0; $j < $b1; $j++)
        {
            $line = $b[$j];
            $count_b[$line] = isset($count_b[$line]) ? [$count_b[$line][0] + 1, $count_b[$line][1]] : [1, $j];
        }

        $pairs = [];

        foreach ($count_a as $line => $info)
        {
            if ($info[0] === 1 AND isset($count_b[$line]) AND $count_b[$line][0] === 1)
            {
                $pairs[$info[1]] = $count_b[$line][1];
            }
        }

        if ( ! $pairs)
        {
            return [];
        }

        ksort($pairs);

        return self::_increasing($pairs);
    }

    /**
     * Наибольшая возрастающая подпоследовательность по значениям (O(n log n)).
     *
     * @param  array $pairs индекс слева => индекс справа (по возрастанию ключа)
     * @return array [[a, b], …]
     */
    protected static function _increasing(array $pairs)
    {
        $keys = array_keys($pairs);
        $vals = array_values($pairs);
        $n    = count($vals);

        $tails = [];   // длина-1 => позиция в $vals
        $prev  = array_fill(0, $n, -1);

        for ($i = 0; $i < $n; $i++)
        {
            $lo = 0;
            $hi = count($tails);

            while ($lo < $hi)
            {
                $mid = intdiv($lo + $hi, 2);

                if ($vals[$tails[$mid]] < $vals[$i])
                {
                    $lo = $mid + 1;
                }
                else
                {
                    $hi = $mid;
                }
            }

            if ($lo > 0)
            {
                $prev[$i] = $tails[$lo - 1];
            }

            $tails[$lo] = $i;
        }

        $out = [];
        $k = count($tails) ? $tails[count($tails) - 1] : -1;

        while ($k >= 0)
        {
            $out[] = [$keys[$k], $vals[$k]];
            $k = $prev[$k];
        }

        return array_reverse($out);
    }

    /**
     * Полный перебор (наибольшая общая подпоследовательность) для короткого
     * куска. Слишком большой кусок заменяется целиком — честнее, чем врать
     * красивыми совпадениями после часа расчёта.
     */
    protected static function _lcs(array $a, array $b, $a0, $a1, $b0, $b1, array &$ops)
    {
        $n = $a1 - $a0;
        $m = $b1 - $b0;

        if ($n * $m > self::LCS_CELLS)
        {
            for ($i = $a0; $i < $a1; $i++)
            {
                $ops[] = ['type' => 'del', 'a' => $i, 'b' => NULL];
            }

            for ($j = $b0; $j < $b1; $j++)
            {
                $ops[] = ['type' => 'ins', 'a' => NULL, 'b' => $j];
            }

            return;
        }

        $dp = [];

        for ($i = $n; $i >= 0; $i--)
        {
            $dp[$i] = array_fill(0, $m + 1, 0);
        }

        for ($i = $n - 1; $i >= 0; $i--)
        {
            for ($j = $m - 1; $j >= 0; $j--)
            {
                $dp[$i][$j] = ($a[$a0 + $i] === $b[$b0 + $j])
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $i = 0;
        $j = 0;

        while ($i < $n AND $j < $m)
        {
            if ($a[$a0 + $i] === $b[$b0 + $j])
            {
                $ops[] = ['type' => 'eq', 'a' => $a0 + $i, 'b' => $b0 + $j];
                $i++;
                $j++;
            }
            elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1])
            {
                $ops[] = ['type' => 'del', 'a' => $a0 + $i, 'b' => NULL];
                $i++;
            }
            else
            {
                $ops[] = ['type' => 'ins', 'a' => NULL, 'b' => $b0 + $j];
                $j++;
            }
        }

        for (; $i < $n; $i++)
        {
            $ops[] = ['type' => 'del', 'a' => $a0 + $i, 'b' => NULL];
        }

        for (; $j < $m; $j++)
        {
            $ops[] = ['type' => 'ins', 'a' => NULL, 'b' => $b0 + $j];
        }
    }

    /**
     * Операции → строки таблицы: удаление и добавление, идущие подряд,
     * склеиваются в «изменено» и показываются парой.
     *
     * @return array
     */
    protected static function _rows(array $ops, array $a, array $b, $words)
    {
        $rows = [];
        $dels = [];
        $inss = [];

        $flush = static function () use (&$rows, &$dels, &$inss, $a, $b, $words) {
            $pairs = max(count($dels), count($inss));

            for ($k = 0; $k < $pairs; $k++)
            {
                $left  = isset($dels[$k]) ? $dels[$k] : NULL;
                $right = isset($inss[$k]) ? $inss[$k] : NULL;

                if ($left !== NULL AND $right !== NULL)
                {
                    $row = [
                        'type'  => 'mod',
                        'ln'    => $left + 1,
                        'rn'    => $right + 1,
                        'left'  => $a[$left],
                        'right' => $b[$right],
                    ];

                    if ($words)
                    {
                        list($row['left_parts'], $row['right_parts']) = self::words_diff($a[$left], $b[$right]);
                    }

                    $rows[] = $row;
                }
                elseif ($left !== NULL)
                {
                    $rows[] = ['type' => 'del', 'ln' => $left + 1, 'rn' => NULL, 'left' => $a[$left], 'right' => NULL];
                }
                else
                {
                    $rows[] = ['type' => 'ins', 'ln' => NULL, 'rn' => $right + 1, 'left' => NULL, 'right' => $b[$right]];
                }
            }

            $dels = [];
            $inss = [];
        };

        foreach ($ops as $op)
        {
            if ($op['type'] === 'eq')
            {
                $flush();
                $rows[] = [
                    'type'  => 'eq',
                    'ln'    => $op['a'] + 1,
                    'rn'    => $op['b'] + 1,
                    'left'  => $a[$op['a']],
                    'right' => $b[$op['b']],
                ];
            }
            elseif ($op['type'] === 'del')
            {
                $dels[] = $op['a'];
            }
            else
            {
                $inss[] = $op['b'];
            }
        }

        $flush();

        return $rows;
    }

    /**
     * Разница внутри строки по словам.
     *
     * @param  string $left
     * @param  string $right
     * @return array [[['eq'|'del', текст], …], [['eq'|'ins', текст], …]]
     */
    public static function words_diff($left, $right)
    {
        $a = self::_tokens($left);
        $b = self::_tokens($right);

        if (count($a) * count($b) > self::WORD_CELLS)
        {
            return [[['del', $left]], [['ins', $right]]];
        }

        $ops = [];
        self::_lcs($a, $b, 0, count($a), 0, count($b), $ops);

        $l = [];
        $r = [];

        foreach ($ops as $op)
        {
            if ($op['type'] === 'eq')
            {
                self::_append($l, 'eq', $a[$op['a']]);
                self::_append($r, 'eq', $b[$op['b']]);
            }
            elseif ($op['type'] === 'del')
            {
                self::_append($l, 'del', $a[$op['a']]);
            }
            else
            {
                self::_append($r, 'ins', $b[$op['b']]);
            }
        }

        return [$l, $r];
    }

    /** Строка → слова и пробелы отдельными кусками. */
    protected static function _tokens($line)
    {
        $parts = preg_split('/(\s+)/u', (string) $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? $parts : [(string) $line];
    }

    /** Склеить соседние куски одного вида — меньше тегов в разметке. */
    protected static function _append(array &$out, $type, $text)
    {
        $n = count($out);

        if ($n AND $out[$n - 1][0] === $type)
        {
            $out[$n - 1][1] .= $text;

            return;
        }

        $out[] = [$type, $text];
    }

    /**
     * Сводка: сколько строк добавлено, удалено, изменено и насколько тексты похожи.
     *
     * @return array
     */
    protected static function _stats(array $rows, $count_a, $count_b)
    {
        $same = $add = $del = $mod = 0;

        foreach ($rows as $row)
        {
            if ($row['type'] === 'eq') $same++;
            elseif ($row['type'] === 'ins') $add++;
            elseif ($row['type'] === 'del') $del++;
            else $mod++;
        }

        $total = $count_a + $count_b;

        return [
            'same'       => $same,
            'added'      => $add,
            'removed'    => $del,
            'changed'    => $mod,
            'lines_a'    => $count_a,
            'lines_b'    => $count_b,
            'identical'  => ($add === 0 AND $del === 0 AND $mod === 0),
            // Похожесть: доля совпавших строк в сумме обоих текстов
            'similarity' => $total > 0 ? round($same * 2 / $total * 100, 1) : 100.0,
        ];
    }

    /**
     * Стандартный unified diff — его понимают git apply, patch и редакторы.
     *
     * @return string
     */
    public static function unified(array $ops, array $a, array $b)
    {
        $changed = FALSE;

        foreach ($ops as $op)
        {
            if ($op['type'] !== 'eq')
            {
                $changed = TRUE;
                break;
            }
        }

        if ( ! $changed)
        {
            return '';
        }

        // Группируем операции в куски: изменения + CONTEXT строк вокруг
        $keep = [];

        foreach ($ops as $i => $op)
        {
            if ($op['type'] === 'eq')
            {
                continue;
            }

            for ($k = $i - self::CONTEXT; $k <= $i + self::CONTEXT; $k++)
            {
                if ($k >= 0 AND $k < count($ops))
                {
                    $keep[$k] = TRUE;
                }
            }
        }

        ksort($keep);

        $out  = ['--- было', '+++ стало'];
        $hunk = [];
        $prev = NULL;

        $flush = static function () use (&$out, &$hunk, $ops, $a, $b) {
            if ( ! $hunk)
            {
                return;
            }

            $a_start = $a_len = $b_start = $b_len = 0;

            foreach ($hunk as $idx)
            {
                $op = $ops[$idx];

                if ($op['a'] !== NULL)
                {
                    $a_start = $a_start ?: ($op['a'] + 1);
                    $a_len++;
                }

                if ($op['b'] !== NULL)
                {
                    $b_start = $b_start ?: ($op['b'] + 1);
                    $b_len++;
                }
            }

            $out[] = '@@ -'.($a_start ?: 0).','.$a_len.' +'.($b_start ?: 0).','.$b_len.' @@';

            foreach ($hunk as $idx)
            {
                $op = $ops[$idx];

                if ($op['type'] === 'eq')
                {
                    $out[] = ' '.$a[$op['a']];
                }
                elseif ($op['type'] === 'del')
                {
                    $out[] = '-'.$a[$op['a']];
                }
                else
                {
                    $out[] = '+'.$b[$op['b']];
                }
            }

            $hunk = [];
        };

        foreach (array_keys($keep) as $idx)
        {
            if ($prev !== NULL AND $idx > $prev + 1)
            {
                $flush();
            }

            $hunk[] = $idx;
            $prev = $idx;
        }

        $flush();

        return implode("\n", $out);
    }

    protected static function _error($message)
    {
        return [
            'ok'      => FALSE,
            'error'   => (string) $message,
            'rows'    => [],
            'stats'   => NULL,
            'unified' => '',
        ];
    }
}
