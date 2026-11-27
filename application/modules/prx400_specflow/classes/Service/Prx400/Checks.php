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

    /** Флаги, которые считает сервер (пересчёт после правки); прочие (gap) — контекст документа. */
    public const SERVER_FLAGS = ['math_row', 'unit_fractional', 'unit_mismatch'];

    /** Единица строки расходится с единицей привязанной позиции НСИ (синонимы — Match::unit_key). */
    public static function match_flags(array $row, ?array $match): array
    {
        $catalog_unit = Service_Prx400_Match::unit_key((string) ($match['unit'] ?? ''));
        $row_unit = Service_Prx400_Match::unit_key((string) ($row['unit'] ?? ''));
        if (empty($match['code']) OR $catalog_unit === '' OR $row_unit === '' OR $catalog_unit === $row_unit) return [];

        return [['type' => 'unit_mismatch', 'unit' => mb_substr((string) $match['unit'], 0, 16)]];
    }

    /** Флаги строки заново: контекст документа сохраняется, серверные — пересчитываются. */
    public static function refresh(array $row, ?array $match): array
    {
        $kept = array_values(array_filter((array) ($row['flags'] ?? []), static fn ($f): bool =>
            is_array($f) && ! in_array((string) ($f['type'] ?? ''), self::SERVER_FLAGS, TRUE)));

        return array_merge($kept, self::row_flags($row), self::match_flags($row, $match));
    }

    public static function number($value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : NULL;
    }
}
