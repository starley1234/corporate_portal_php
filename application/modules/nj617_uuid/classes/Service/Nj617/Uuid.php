<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Генератор идентификаторов (модуль NJ-617) — UUID v4 / v7 и ULID.
 *
 * Одна точка истины для страницы (/uuid), API (/nj617/api/*) и агентов.
 * Без внешних библиотек: UUID v4 — random_bytes (RFC 4122), UUID v7 — время +
 * случайность (сортируется по времени), ULID — Crockford Base32, монотонный.
 */
class Service_Nj617_Uuid {

    /** @var array доступные форматы => [label, верхний регистр, нижний регистр] */
    public static function kinds()
    {
        return [
            'uuid4' => ['UUID v4 — случайный (RFC 4122)', TRUE, TRUE],
            'uuid7' => ['UUID v7 — сортируется по времени', TRUE, TRUE],
            'ulid'  => ['ULID — 26 знаков, Base32', FALSE, TRUE],
        ];
    }

    /** Один UUID v4. */
    public static function uuid4($upper = FALSE)
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // версия 0100
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // вариант 10xx
        $hex = bin2hex($b);
        $u   = sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));

        return $upper ? strtoupper($u) : $u;
    }

    /** Один UUID v7 (первые 48 бит — миллисекунды Unix-времени, RFC 9562). */
    public static function uuid7($upper = FALSE)
    {
        $ms  = (int) floor(microtime(TRUE) * 1000);
        $b   = random_bytes(10);
        $hex = sprintf('%012x%s', $ms, bin2hex($b));

        // байт 6 (символы 12-13): старший полубайт = версия 7
        // байт 8 (символы 16-17): старший полубайт = вариант 10xx
        $variant = dechex(8 + (hexdec($hex[16]) & 0x3));

        $u = sprintf(
            '%s-%s-7%s-%s%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            $variant,
            substr($hex, 17, 3),
            substr($hex, 20, 12)
        );

        return $upper ? strtoupper($u) : $u;
    }

    /** Один ULID (48 бит времени + 80 бит случайности, Crockford Base32). */
    public static function ulid($lower = TRUE)
    {
        static $last_ms = 0, $last_rand = '';

        $ms = (int) floor(microtime(TRUE) * 1000);

        if ($ms === $last_ms)
        {
            // монотонность в пределах одной миллисекунды: инкрементируем хвост
            $last_rand = self::_inc_rand($last_rand);
        }
        else
        {
            $last_ms   = $ms;
            $last_rand = self::_rand80();
        }

        $enc = self::_crockford_encode($ms, 10);
        $ulid = $enc.$last_rand;

        return $lower ? strtolower($ulid) : $ulid;
    }

    protected static function _rand80()
    {
        $b    = random_bytes(10);
        $bits = '';

        for ($i = 0; $i < strlen($b); $i++)
        {
            $bits .= str_pad(decbin(ord($b[$i])), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        for ($i = 0; $i < 16; $i++)
        {
            $chunk = substr($bits, $i * 5, 5);
            $out  .= '0123456789abcdefghjkmnpqrstvwxyz'[bindec($chunk)];
        }

        return $out;
    }

    /** Инкремент случайного хвоста ULID (разрядно, как целое в Base32). */
    protected static function _inc_rand($s)
    {
        $alphabet = '0123456789abcdefghjkmnpqrstvwxyz';
        $chars    = str_split($s);
        $base     = strlen($alphabet);
        $pos      = strlen($s) - 1;

        while ($pos >= 0)
        {
            $idx = strpos($alphabet, $chars[$pos]);

            if ($idx === $base - 1)
            {
                $chars[$pos] = $alphabet[0];
                $pos--;
                continue;
            }

            $chars[$pos] = $alphabet[$idx + 1];
            break;
        }

        return implode('', $chars);
    }

    /** Crockford Base32 — без I,L,O,U (легко читается). */
    protected static function _crockford_encode($num, $len)
    {
        $alphabet = '0123456789abcdefghjkmnpqrstvwxyz';
        $base     = strlen($alphabet);
        $out      = '';

        while ($num > 0)
        {
            $out = $alphabet[$num % $base].$out;
            $num = intdiv($num, $base);
        }

        return str_pad($out, $len, '0', STR_PAD_LEFT);
    }

    /** Пачка идентификаторов одним вызовом. */
    public static function batch($kind, $count, $upper = FALSE)
    {
        $count = max(1, min(100, (int) $count));
        $out   = [];

        for ($i = 0; $i < $count; $i++)
        {
            switch ($kind)
            {
                case 'uuid7':
                    $out[] = self::uuid7($upper);
                    break;
                case 'ulid':
                    $out[] = self::ulid(! $upper);
                    break;
                default:
                    $out[] = self::uuid4($upper);
            }
        }

        return $out;
    }
}
