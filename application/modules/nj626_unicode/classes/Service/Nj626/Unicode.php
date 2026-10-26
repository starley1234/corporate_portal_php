<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Unicode-анализатор (модуль NJ-626) — разбор строки по кодовым точкам.
 *
 * Для каждого символа: сама буква, кодовую точку (U+XXXX), UTF-8 байты,
 * UTF-16 escape (суррогаты) и \u{...} / \uXXXX записи. Работает на mbstring
 * (есть в окружении), повреждённые строки не роняет.
 */
class Service_Nj626_Unicode {

    /** Разбор строки → список символов с кодами. */
    public static function analyze(string $text): array
    {
        $chars = [];
        $len   = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);

        for ($i = 0; $i < $len; $i++)
        {
            $ch = function_exists('mb_substr') ? mb_substr($text, $i, 1, 'UTF-8') : $text[$i];

            $chars[] = self::char_info($ch);
        }

        return $chars;
    }

    /** Информация об одном символе. */
    public static function char_info(string $ch): array
    {
        $has_mb = function_exists('mb_ord');

        $cp = $has_mb ? mb_ord($ch, 'UTF-8') : self::_cp_at($ch, 0);
        $name = ($has_mb && function_exists('mb_chr')) ? self::_fallback_name($cp) : '';

        return [
            'char'     => $ch,
            'cp'       => $cp,
            'uplus'    => sprintf('U+%04X', $cp),
            'utf8'     => strtoupper(bin2hex($ch)),
            'escape'   => self::esc_utf16($cp),
            'u_braces' => self::esc_u_braces($cp),
            'entity'   => '&#x'.dechex($cp).';',
            'name'     => $name,
        ];
    }

    /** \u{1F600} — современная запись (ECMAScript/Python). */
    public static function esc_u_braces(int $cp): string
    {
        return '\u{'.strtoupper(dechex($cp)).'}';
    }

    /** \uXXXX (BMP) или пара суррогатов \uD83D\uDE00. */
    public static function esc_utf16(int $cp): string
    {
        if ($cp <= 0xFFFF)
        {
            return sprintf('\u%04X', $cp);
        }

        $v    = $cp - 0x10000;
        $high = 0xD800 + ($v >> 10);
        $low  = 0xDC00 + ($v & 0x3FF);

        return sprintf('\u%04X\u%04X', $high, $low);
    }

    /** Декодирование балкового \uXXXX / \u{...} текста. */
    public static function decode_escapes(string $text): ?array
    {
        if (preg_match('/\\\\u\{[0-9a-fA-F]{1,6}\}/', $text))
        {
            $text = preg_replace_callback('/\\\\u\{([0-9a-fA-F]{1,6})\}/', function ($m) {
                $cp = (int) hexdec($m[1]);

                return self::_chr($cp);
            }, $text);
        }

        if (preg_match('/\\\\u[0-9a-fA-F]{4}/', $text))
        {
            $text = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($m) {
                return self::_chr((int) hexdec($m[1]));
            }, $text);
        }

        return ['text' => $text];
    }

    /** Символ по кодовой точке (с защитой от неверного диапазона). */
    protected static function _chr(int $cp): string
    {
        if (function_exists('mb_chr'))
        {
            try
            {
                return mb_chr($cp, 'UTF-8');
            }
            catch (\Throwable $e)
            {
                return "\u{FFFD}";
            }
        }

        if ($cp <= 0x7F)
        {
            return chr($cp);
        }

        if ($cp <= 0x7FF)
        {
            return chr(0xC0 | ($cp >> 6)).chr(0x80 | ($cp & 0x3F));
        }

        if ($cp <= 0xFFFF)
        {
            return chr(0xE0 | ($cp >> 12)).chr(0x80 | (($cp >> 6) & 0x3F)).chr(0x80 | ($cp & 0x3F));
        }

        return chr(0xF0 | ($cp >> 18)).chr(0x80 | (($cp >> 12) & 0x3F)).chr(0x80 | (($cp >> 6) & 0x3F)).chr(0x80 | ($cp & 0x3F));
    }

    protected static function _cp_at(string $ch, int $i): int
    {
        // крайний fallback без mbstring — для латинского/кириллицы
        $bytes = str_split($ch);
        $b0    = ord($bytes[0]);

        if ($b0 < 0x80)
        {
            return $b0;
        }

        if (count($bytes) >= 2 AND ($b0 & 0xE0) === 0xC0)
        {
            return (($b0 & 0x1F) << 6) | (ord($bytes[1]) & 0x3F);
        }

        if (count($bytes) >= 3 AND ($b0 & 0xF0) === 0xE0)
        {
            return (($b0 & 0x0F) << 12) | ((ord($bytes[1]) & 0x3F) << 6) | (ord($bytes[2]) & 0x3F);
        }

        if (count($bytes) >= 4 AND ($b0 & 0xF8) === 0xF0)
        {
            return ((($b0 & 0x07) << 18) | ((ord($bytes[1]) & 0x3F) << 12) | ((ord($bytes[2]) & 0x3F) << 6) | (ord($bytes[3]) & 0x3F));
        }

        return 0xFFFD;
    }

    protected static function _fallback_name(int $cp): string
    {
        // несколько частых символов для подписи; полная база имён не нужна
        static $names = [
            0x200B => 'неразрывный пробел нулевой ширины (zero-width space)',
            0x00A0 => 'неразрывный пробел (nbsp)',
            0x202F => 'узкий неразрывный пробел',
        ];

        return $names[$cp] ?? '';
    }
}
