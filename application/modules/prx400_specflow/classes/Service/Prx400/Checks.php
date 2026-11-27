<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Построчные проверки PHP-стороны — зеркало parse.run_math_checks воркера
 * для тех же флагов после правки оператором (qty/ед. меняются в UI).
 */
final class Service_Prx400_Checks
{
    public const TOLERANCE = 0.01;
    public const COUNT_UNITS = ['шт', 'компл', 'комплект', 'пар'];

    /** Флаги math_row/unit_fractional по данным строки. */
    public static function row_flags(array $row): array
    {
        $flags = [];
        $qty = self::number($row['qty'] ?? NULL);
        $price = self::number($row['unit_price'] ?? NULL);
        $total = self::number($row['total_price'] ?? NULL);
        if ($qty !== NULL AND $price !== NULL AND $total !== NULL)
        {
            $expected = round($qty * $price, 2);
            if (abs($expected - $total) > self::TOLERANCE) $flags[] = ['type' => 'math_row', 'expected' => $expected];
        }

        $unit = rtrim(mb_strtolower(trim((string) ($row['unit'] ?? ''))), '.,');
        if ($qty !== NULL AND in_array($unit, self::COUNT_UNITS, TRUE) AND floor($qty) != $qty)
        {
            $flags[] = ['type' => 'unit_fractional', 'unit' => (string) $row['unit']];
        }

        return $flags;
    }

    public static function number($value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : NULL;
    }
}
