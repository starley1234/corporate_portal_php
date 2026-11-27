<?php defined('SYSPATH') or die('No direct script access.');

/**
 * BOOLEAN из PostgreSQL: драйвер отдаёт 't'/'f' (строкой), PDO — bool.
 * (bool) 'f' === TRUE — так «выключенный» шаблон считался включённым.
 */
final class Prx400_Bool
{
    public static function from_db($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;

        return in_array(strtolower(trim((string) $value)), ['t', 'true', '1', 'y', 'yes', 'on'], TRUE);
    }
}
