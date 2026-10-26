<?php defined('SYSPATH') or die('No direct script access.');

/** Stateless-конвертер единиц. Справочник лежит в config/nj506_units.php. */
class Service_Nj506_Units {

    const MAX_ABS = 1.0E+12;
    const SCALE = 8;

    public static function catalog()
    {
        return (array) Kohana::$config->load('nj506_units.categories');
    }

    public static function data_note()
    {
        return (string) Kohana::$config->load('nj506_units.data_note');
    }

    /** Прочитать число с запятой и разделителями тысяч; мусор → NULL. */
    public static function parse_number($raw)
    {
        if (is_int($raw) OR is_float($raw))
        {
            $number = (float) $raw;
            return (is_finite($number) AND abs($number) <= self::MAX_ABS) ? $number : NULL;
        }

        if ( ! is_string($raw)) return NULL;

        $value = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', '', trim($raw));
        $value = str_replace([',', '−', '–'], ['.', '-', '-'], $value);
        if ( ! preg_match('/^-?(\d{1,13}(\.\d{0,12})?|\.\d{1,12})$/', $value)) return NULL;

        $number = (float) $value;
        return (is_finite($number) AND abs($number) <= self::MAX_ABS) ? $number : NULL;
    }

    public static function format($number)
    {
        if ($number === NULL OR ! is_finite((float) $number)) return '—';
        $rounded = round((float) $number, self::SCALE, PHP_ROUND_HALF_UP);
        $formatted = number_format(abs($rounded), self::SCALE, ',', "\xC2\xA0");
        $formatted = rtrim(rtrim($formatted, '0'), ',');
        return ($rounded < 0 ? '−' : '').$formatted;
    }

    /**
     * Перевести значение из одной единицы в другую.
     *
     * @return array|null Подробный результат или NULL при недопустимых параметрах.
     */
    public static function calculate($category, $raw_value, $from, $to)
    {
        $catalog = self::catalog();
        if ( ! is_string($category) OR ! isset($catalog[$category])) return NULL;
        if ( ! is_string($from) OR ! is_string($to)) return NULL;

        $definition = $catalog[$category];
        if ( ! isset($definition['units'][$from], $definition['units'][$to])) return NULL;
        $value = self::parse_number($raw_value);
        if ($value === NULL) return NULL;
        if ($category !== 'temperature' AND $value < 0) return NULL;

        $source = $definition['units'][$from];
        $target = $definition['units'][$to];
        $base_value = $value * (float) $source['factor'] + (float) ($source['offset'] ?? 0);
        if ($category === 'temperature' AND $base_value < -273.15 - 1.0E-9) return NULL;

        $converted = ($base_value - (float) ($target['offset'] ?? 0)) / (float) $target['factor'];
        if ( ! is_finite($converted) OR abs($converted) > self::MAX_ABS) return NULL;
        $converted = round($converted, self::SCALE, PHP_ROUND_HALF_UP);

        return [
            'category' => $category,
            'value' => $value,
            'from' => $from,
            'to' => $to,
            'converted' => $converted,
            'formatted' => self::format($converted),
            'formula' => self::format($value).' '.$source['symbol'].' = '.self::format($converted).' '.$target['symbol'],
        ];
    }
}
