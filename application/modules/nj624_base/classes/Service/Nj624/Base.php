<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Системы счисления (модуль NJ-624) — 2/8/10/16 + битовые операции.
 *
 * Значения отдано строками (decimal — строка), чтобы в порядке 64+ бит
 * не терять точность; арифметика И/ИЛИ/искл.ИЛИ выполняется побитно.
 */
class Service_Nj624_Base {

    /** Допустимые основания. */
    public static function bases(): array
    {
        return [2 => 'двоичная (bin)', 8 => 'восьмеричная (oct)', 10 => 'десятичная (dec)', 16 => 'шестнадцатеричная (hex)'];
    }

    /** Проверка записи числа в заданном основании. */
    public static function is_valid(string $value, int $base): bool
    {
        $value = strtolower(trim(str_replace(' ', '', $value)));

        return $value !== '' && preg_match('/^[0-9a-f]+$/', $value) && base_convert($value, $base, 10) !== false && self::_digits_ok($value, $base);
    }

    protected static function _digits_ok(string $value, int $base): bool
    {
        $max = ($base === 16) ? 'f' : (string) ($base - 1);

        foreach (str_split($value) as $ch)
        {
            if (hexdec($ch) > hexdec($max))
            {
                return false;
            }
        }

        return true;
    }

    /** Перевод в друг sizes основания, NULL при ошибке. */
    public static function convert(string $value, int $from_base, int $to_base): ?string
    {
        if ( ! isset(self::bases()[$from_base]) OR ! isset(self::bases()[$to_base]))
        {
            return NULL;
        }

        $clean = strtolower(trim(str_replace([' ', '_', '0x'], '', $value)));

        if ( ! self::is_valid($clean, $from_base))
        {
            return NULL;
        }

        return base_convert($clean, $from_base, $to_base);
    }

    /** Десятичное представление строки в основании from_base. */
    public static function to_dec(string $value, int $from_base): ?string
    {
        return base_convert(strtolower(trim($value)), $from_base, 10);
    }

    /** Побитовые операции над двумя числами из одной системы (аргументы — в 10-й). */
    public static function bitop(string $op, string $a, string $b): ?array
    {
        if (strlen($a) > 30 OR strlen($b) > 30)
        {
            $a = self::_cap($a);
            $b = self::_cap($b);
        }

        $x = (int) $a;
        $y = (int) $b;

        // 32-битная семантика, как у побитовых операторов JavaScript:
        // результат приводится к знаковому int32, чтобы сервер и браузер совпадали.
        switch ($op)
        {
            case 'and': $r = self::_i32($x & $y); break;
            case 'or':  $r = self::_i32($x | $y); break;
            case 'xor': $r = self::_i32($x ^ $y); break;
            case 'not': $r = self::_i32(~$x); break;
            case 'shl': $r = self::_i32($x << 1); break;
            case 'shr': $r = self::_i32($x >> 1); break;
            default: return NULL;
        }

        // для hex/oct отрицательное значение показываем в 32-битном виде
        $u32 = $r < 0 ? $r + 0x100000000 : $r;

        return [
            'op'   => $op,
            'a'    => (string) $x,
            'b'    => (string) $y,
            'dec'  => (string) $r,
            'bin'  => self::_fmt_bin($r),
            'hex'  => strtoupper(dechex($u32)),
            'oct'  => decoct($u32),
        ];
    }

    /** Защита от выхода за диапазон платформы (для очень длинных чисел). */
    protected static function _cap(string $v): string
    {
        $v = ltrim($v, '-');
        return strlen($v) > 18 ? substr($v, 0, 18) : $v;
    }

    /** Знаковое 32-битное число (как JS: `x | 0`). */
    protected static function _i32(int $n): int
    {
        $n &= 0xFFFFFFFF;

        if ($n >= 0x80000000)
        {
            $n -= 0x100000000;
        }

        return $n;
    }

    /** Красивое бинарное представление: группы по 4 бита. */
    public static function _fmt_bin(int $n): string
    {
        if ($n < 0)
        {
            // 32-битное дополнение до двух для наглядности
            $n = 0xFFFFFFFF & $n;
        }

        $s = decbin($n);
        $s = str_pad($s, (int) ceil(strlen($s) / 4) * 4, '0', STR_PAD_LEFT);

        return implode(' ', str_split($s, 4));
    }
}
