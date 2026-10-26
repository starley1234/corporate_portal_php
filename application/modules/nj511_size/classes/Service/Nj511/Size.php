<?php defined('SYSPATH') or die('No direct script access.');

/** Подбор ближайшего размера по мерке из локальной справочной таблицы. */
class Service_Nj511_Size {

    const MAX_MEASURE = 300;

    public static function categories()
    {
        return (array) Kohana::$config->load('nj511_size.categories');
    }

    public static function sources()
    {
        return (array) Kohana::$config->load('nj511_size.sources');
    }

    public static function notice()
    {
        return (string) Kohana::$config->load('nj511_size.notice');
    }

    public static function parse_number($raw)
    {
        if (is_int($raw) OR is_float($raw))
        {
            $number = (float) $raw;
            return (is_finite($number) AND $number > 0 AND $number <= self::MAX_MEASURE) ? $number : NULL;
        }
        if ( ! is_string($raw)) return NULL;
        $value = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', '', trim($raw));
        $value = str_replace([',', '−', '–'], ['.', '-', '-'], $value);
        if ( ! preg_match('/^\d{1,3}(\.\d{0,3})?$/', $value)) return NULL;
        $number = (float) $value;
        return ($number > 0 AND $number <= self::MAX_MEASURE) ? $number : NULL;
    }

    public static function format($number, $digits = 1)
    {
        $text = number_format((float) $number, (int) $digits, ',', "\xC2\xA0");
        return rtrim(rtrim($text, '0'), ',');
    }

    public static function calculate($category, $raw_measure)
    {
        $categories = self::categories();
        if ( ! is_string($category) OR ! isset($categories[$category])) return NULL;
        $measure = self::parse_number($raw_measure);
        if ($measure === NULL) return NULL;

        $best = NULL;
        $distance = NULL;
        foreach ($categories[$category]['rows'] as $row)
        {
            $current = abs((float) $row['measure'] - $measure);
            if ($distance === NULL OR $current < $distance)
            {
                $best = $row;
                $distance = $current;
            }
        }
        if ($best === NULL) return NULL;

        return [
            'category' => $category,
            'measure' => $measure,
            'unit' => $categories[$category]['unit'],
            'matched_measure' => (float) $best['measure'],
            'display' => (string) $best['display'],
            'difference' => round((float) $distance, 3),
            'sizes' => (array) $best['sizes'],
            'details' => (array) $best['details'],
            'nearest' => $best,
        ];
    }
}
