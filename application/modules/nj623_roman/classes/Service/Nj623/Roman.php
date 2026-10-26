<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Римские числа (модуль NJ-623) — перевод арабских ↔ римских.
 *
 * Одна точка истины для /roman и /nj623/api/*. Классический диапазон
 * 1–3999 (без «vinculum» — надстроек для тысяч). Перевод строгий:
 * неверная запись не «угадывается», а честно возвращает NULL.
 */
class Service_Nj623_Roman {

    /** @var array<int,string> римские значения от старшего к младшему */
    const MAP = [
        1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C',
        90   => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX',
        5    => 'V', 4  => 'IV', 1  => 'I',
    ];

    /** Арабское → римское (NULL вне 1–3999). */
    public static function to_roman(int $n): ?string
    {
        if ($n < 1 OR $n > 3999)
        {
            return NULL;
        }

        $out = '';
        foreach (self::MAP as $value => $numeral)
        {
            while ($n >= $value)
            {
                $out .= $numeral;
                $n   -= $value;
            }
        }

        return $out;
    }

    /** Римское → арабское (NULL при неверной записи и пустой строке). */
    public static function from_roman(string $s): ?int
    {
        $s = strtoupper(trim($s));

        if ($s === '')
        {
            return NULL;
        }

        // строгая форма: не более трёх M подряд, корректные вычитания
        if ( ! preg_match('/^M{0,3}(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/', $s))
        {
            return NULL;
        }

        static $value = ['M' => 1000, 'D' => 500, 'C' => 100, 'L' => 50, 'X' => 10, 'V' => 5, 'I' => 1];

        $total = 0;
        $i     = 0;
        $len   = strlen($s);

        while ($i < $len)
        {
            $cur  = $value[$s[$i]];
            $next = ($i + 1 < $len) ? $value[$s[$i + 1]] : 0;

            if ($next > $cur)
            {
                $total += $next - $cur;
                $i     += 2;
            }
            else
            {
                $total += $cur;
                $i     += 1;
            }
        }

        return $total;
    }

    /**
     * Автоопределение направления: цифры → арабское в римское, иначе наоборот.
     * Возвращает [dir, in, out] либо NULL, если перевести нельзя.
     */
    public static function convert(string $value): ?array
    {
        $value = trim($value);

        if ($value === '')
        {
            return NULL;
        }

        if (preg_match('/^[0-9]+$/', $value))
        {
            $out = self::to_roman((int) $value);

            return $out === NULL ? NULL : ['dir' => 'to', 'in' => $value, 'out' => $out];
        }

        $out = self::from_roman($value);

        return $out === NULL ? NULL : ['dir' => 'from', 'in' => strtoupper($value), 'out' => (string) $out];
    }
}
