<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конвертер цветов (модуль NJ-629) — HEX ↔ RGB ↔ HSL + оттенки.
 *
 * Одна точка истины для /color и /nj629/api/*. HEX и RGB парсятся строго
 * (#RGB/#RRGGBB, rgb(r,g,b)); HSL переводится стандартными формулами.
 * Оттенки и комплементарный цвет помогают подобрать палитру.
 */
class Service_Nj629_Color {

    /** Разбор введённого цвета → [r,g,b] 0–255 или NULL. */
    public static function parse(string $value): ?array
    {
        $value = strtolower(trim($value));

        // hex #RGB / #RRGGBB
        if (preg_match('/^#?(([0-9a-f]{3})|([0-9a-f]{6}))$/', $value, $m))
        {
            $hex = str_replace('#', '', $value);

            if (strlen($hex) === 3)
            {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }

            return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        }

        // rgb(r,g,b) / r,g,b
        if (preg_match('/^(?:rgb\()?\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)?$/', $value, $m))
        {
            $rgb = [(int) $m[1], (int) $m[2], (int) $m[3]];

            foreach ($rgb as $c)
            {
                if ($c < 0 OR $c > 255)
                {
                    return NULL;
                }
            }

            return $rgb;
        }

        return NULL;
    }

    /** RGB → HEX (#RRGGBB). */
    public static function to_hex(array $rgb): string
    {
        return '#'.strtoupper(sprintf('%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]));
    }

    /** RGB → HSL (0–360, 0–100, 0–100). */
    public static function to_hsl(array $rgb): array
    {
        $r = $rgb[0] / 255; $g = $rgb[1] / 255; $b = $rgb[2] / 255;
        $max = max($r, $g, $b); $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min)
        {
            $h = $s = 0;
        }
        else
        {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

            switch ($max)
            {
                case $r: $h = ($g - $b) / $d + ($g < $b ? 6 : 0); break;
                case $g: $h = ($b - $r) / $d + 2; break;
                default: $h = ($r - $g) / $d + 4;
            }

            $h = round($h * 60);
        }

        return [(int) $h, (int) round($s * 100), (int) round($l * 100)];
    }

    /** HSL → RGB. */
    public static function hsl_to_rgb(float $h, float $s, float $l): array
    {
        $h = fmod($h, 360); if ($h < 0) { $h += 360; }
        $s = max(0, min(100, $s)) / 100;
        $l = max(0, min(100, $l)) / 100;

        if ($s === 0.0)
        {
            $v = (int) round($l * 255);
            return [$v, $v, $v];
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $hk = $h / 360;

        $tr = $hk + 1 / 3; $tg = $hk; $tb = $hk - 1 / 3;

        return [self::_hue($p, $q, $tr), self::_hue($p, $q, $tg), self::_hue($p, $q, $tb)];
    }

    protected static function _hue(float $p, float $q, float $t): int
    {
        if ($t < 0) { $t += 1; }
        if ($t > 1) { $t -= 1; }

        if ($t < 1 / 6)      { $v = $p + ($q - $p) * 6 * $t; }
        elseif ($t < 1 / 2)  { $v = $q; }
        elseif ($t < 2 / 3)  { $v = $p + ($q - $p) * (2 / 3 - $t) * 6; }
        else                 { $v = $p; }

        return (int) round($v * 255);
    }

    /** Оттенки (светлее/темнее на step%). */
    public static function shades(array $rgb, int $step = 10): array
    {
        $out = [];

        foreach ([-20, -10, 10, 20] as $d)
        {
            $out[] = ['pct' => $d, 'rgb' => self::_shift($rgb, $d), 'hex' => self::to_hex(self::_shift($rgb, $d))];
        }

        return $out;
    }

    protected static function _shift(array $rgb, int $pct): array
    {
        $out = [];

        foreach ($rgb as $c)
        {
            $out[] = max(0, min(255, (int) round($c + $c * $pct / 100)));
        }

        return $out;
    }

    /** Комплементарный цвет (поворот на 180° в HSL). */
    public static function complement(array $rgb): array
    {
        [$h, $s, $l] = self::to_hsl($rgb);
        $c = self::hsl_to_rgb($h + 180, $s, $l);

        return ['rgb' => $c, 'hex' => self::to_hex($c)];
    }

    /** Полный отчёт для API/UI. */
    public static function report(array $rgb): array
    {
        [$h, $s, $l] = self::to_hsl($rgb);
        $hex = self::to_hex($rgb);
        $c   = self::complement($rgb);

        $luma = 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2];

        return [
            'hex'    => $hex,
            'rgb'    => $rgb,
            'hsl'    => [$h, $s, $l],
            'hsl_s'  => "hsl($h, $s%, $l%)",
            'luma'   => (int) round($luma),
            'is_light' => $luma > 128,
            'shades'   => self::shades($rgb),
            'complement' => $c,
        ];
    }
}
