<?php defined('SYSPATH') or die('No direct script access.');

/** Конвертер мер ингредиентов и пересчёта размеров форм. */
class Service_Nj507_Cooking {

    const MAX_AMOUNT = 1000000;

    public static function products()
    {
        return (array) Kohana::$config->load('nj507_cooking.products');
    }

    public static function measures()
    {
        return (array) Kohana::$config->load('nj507_cooking.measures');
    }

    public static function data_note()
    {
        return (string) Kohana::$config->load('nj507_cooking.data_note');
    }

    public static function sources()
    {
        return (array) Kohana::$config->load('nj507_cooking.sources');
    }

    public static function parse_number($raw)
    {
        if (is_int($raw) OR is_float($raw))
        {
            $number = (float) $raw;
            return (is_finite($number) AND $number >= 0 AND $number <= self::MAX_AMOUNT) ? $number : NULL;
        }
        if ( ! is_string($raw)) return NULL;

        $value = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', '', trim($raw));
        $value = str_replace([',', '−', '–'], ['.', '-', '-'], $value);
        if ( ! preg_match('/^\d{1,7}(\.\d{0,8})?$|^\.\d{1,8}$/', $value)) return NULL;
        $number = (float) $value;
        return (is_finite($number) AND $number <= self::MAX_AMOUNT) ? $number : NULL;
    }

    public static function format($number, $digits = 1)
    {
        if ($number === NULL OR ! is_finite((float) $number)) return '—';
        $rounded = round((float) $number, (int) $digits, PHP_ROUND_HALF_UP);
        $formatted = number_format($rounded, (int) $digits, ',', "\xC2\xA0");
        return rtrim(rtrim($formatted, '0'), ',');
    }

    /**
     * Перевести меру в диапазон граммов либо граммы в диапазон бытовых мер.
     * Состав порций сначала масштабируется: результат = ввод × порций нужно / порций было.
     */
    public static function calculate($product, $measure, $raw_amount, $direction = 'to_mass', $raw_from_servings = 1, $raw_to_servings = 1)
    {
        $products = self::products();
        $measures = self::measures();
        if ( ! is_string($product) OR ! isset($products[$product])) return NULL;
        if ( ! is_string($measure) OR ! isset($measures[$measure])) return NULL;
        if ( ! in_array($direction, ['to_mass', 'to_measure'], TRUE)) return NULL;

        $amount = self::parse_number($raw_amount);
        $from_servings = self::parse_number($raw_from_servings);
        $to_servings = self::parse_number($raw_to_servings);
        if ($amount === NULL OR $from_servings === NULL OR $to_servings === NULL
            OR $from_servings < 1 OR $to_servings < 1 OR $from_servings > 1000 OR $to_servings > 1000)
            return NULL;

        $scale = $to_servings / $from_servings;
        $scaled_amount = $amount * $scale;
        $density = $products[$product]['density'];
        $ml = (float) $measures[$measure]['ml'];

        if ($direction === 'to_mass')
        {
            $min = $scaled_amount * $ml * (float) $density[0];
            $max = $scaled_amount * $ml * (float) $density[1];
            $min = round($min, 1, PHP_ROUND_HALF_UP);
            $max = round($max, 1, PHP_ROUND_HALF_UP);
            $range = self::format($min).'–'.self::format($max).' г';
            $formula = self::format($scaled_amount).' × '.$measures[$measure]['ml'].' мл × '.self::format($density[0], 3).'–'.self::format($density[1], 3).' г/мл';
            return [
                'product' => $product, 'measure' => $measure, 'direction' => $direction,
                'amount' => $amount, 'scaled_amount' => $scaled_amount,
                'min' => $min, 'max' => $max, 'formatted' => $range,
                'formula' => $formula, 'unit' => 'г', 'scale' => $scale,
            ];
        }

        $scaled_grams = $scaled_amount;
        $min = $scaled_grams / ((float) $density[1] * $ml);
        $max = $scaled_grams / ((float) $density[0] * $ml);
        $min = round($min, 2, PHP_ROUND_HALF_UP);
        $max = round($max, 2, PHP_ROUND_HALF_UP);
        $range = ($min === $max ? self::format($min, 2) : self::format($min, 2).'–'.self::format($max, 2)).' '.$measures[$measure]['symbol'];
        $formula = self::format($scaled_grams).' г ÷ ('.$measures[$measure]['ml'].' мл × плотность '.$density[0].'–'.$density[1].' г/мл)';

        return [
            'product' => $product, 'measure' => $measure, 'direction' => $direction,
            'amount' => $amount, 'scaled_amount' => $scaled_grams,
            'min' => $min, 'max' => $max, 'formatted' => $range,
            'formula' => $formula, 'unit' => $measures[$measure]['symbol'], 'scale' => $scale,
        ];
    }

    /** Пересчитать количество ингредиентов между круглыми, квадратными и прямоугольными формами. */
    public static function form_factor($from_shape, $from_a_raw, $from_b_raw, $to_shape, $to_a_raw, $to_b_raw)
    {
        $from_area = self::_area($from_shape, $from_a_raw, $from_b_raw);
        $to_area = self::_area($to_shape, $to_a_raw, $to_b_raw);
        if ($from_area === NULL OR $to_area === NULL) return NULL;

        $factor = $to_area / $from_area;
        if ( ! is_finite($factor) OR $factor < 0.01 OR $factor > 100) return NULL;
        return [
            'from_area' => round($from_area, 2), 'to_area' => round($to_area, 2),
            'factor' => round($factor, 4, PHP_ROUND_HALF_UP),
            'formatted' => self::format($factor, 4),
            'change' => $factor > 1 ? 'увеличить' : ($factor < 1 ? 'уменьшить' : 'оставить без изменения'),
        ];
    }

    protected static function _area($shape, $a_raw, $b_raw)
    {
        $a = self::parse_number($a_raw);
        $b = self::parse_number($b_raw);
        if ($a === NULL OR $a < 1 OR $a > 200) return NULL;
        if ($shape === 'round') return M_PI * ($a / 2) * ($a / 2);
        if ($shape === 'square') return $a * $a;
        if ($shape === 'rectangle' AND $b !== NULL AND $b >= 1 AND $b <= 200) return $a * $b;
        return NULL;
    }
}
