<?php defined('SYSPATH') or die('No direct script access.');

/** Преобразование календарной даты в русские слова и документные форматы. */
class Service_Nj513_Datewords {

    protected static $_months = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    protected static $_day_genitive = [
        1 => 'первого', 2 => 'второго', 3 => 'третьего', 4 => 'четвёртого', 5 => 'пятого',
        6 => 'шестого', 7 => 'седьмого', 8 => 'восьмого', 9 => 'девятого', 10 => 'десятого',
        11 => 'одиннадцатого', 12 => 'двенадцатого', 13 => 'тринадцатого', 14 => 'четырнадцатого',
        15 => 'пятнадцатого', 16 => 'шестнадцатого', 17 => 'семнадцатого', 18 => 'восемнадцатого',
        19 => 'девятнадцатого', 20 => 'двадцатого', 30 => 'тридцатого',
    ];

    protected static $_day_accusative = [
        1 => 'первое', 2 => 'второе', 3 => 'третье', 4 => 'четвёртое', 5 => 'пятое',
        6 => 'шестое', 7 => 'седьмое', 8 => 'восьмое', 9 => 'девятое', 10 => 'десятое',
        11 => 'одиннадцатое', 12 => 'двенадцатое', 13 => 'тринадцатое', 14 => 'четырнадцатое',
        15 => 'пятнадцатое', 16 => 'шестнадцатое', 17 => 'семнадцатое', 18 => 'восемнадцатое',
        19 => 'девятнадцатое', 20 => 'двадцатое', 30 => 'тридцатое',
    ];

    protected static $_tens_genitive = [2 => 'двадцатого', 3 => 'тридцатого', 4 => 'сорокового', 5 => 'пятидесятого', 6 => 'шестидесятого', 7 => 'семидесятого', 8 => 'восьмидесятого', 9 => 'девяностого'];
    protected static $_tens_accusative = [2 => 'двадцатое', 3 => 'тридцатое', 4 => 'сороковое', 5 => 'пятидесятое', 6 => 'шестидесятое', 7 => 'семидесятое', 8 => 'восьмидесятое', 9 => 'девяностое'];
    protected static $_tens_cardinal = [2 => 'двадцать', 3 => 'тридцать', 4 => 'сорок', 5 => 'пятьдесят', 6 => 'шестьдесят', 7 => 'семьдесят', 8 => 'восемьдесят', 9 => 'девяносто'];
    protected static $_hundreds_cardinal = [1 => 'сто', 2 => 'двести', 3 => 'триста', 4 => 'четыреста', 5 => 'пятьсот', 6 => 'шестьсот', 7 => 'семьсот', 8 => 'восемьсот', 9 => 'девятьсот'];
    protected static $_hundreds_ordinal = [1 => 'сотого', 2 => 'двухсотого', 3 => 'трёхсотого', 4 => 'четырёхсотого', 5 => 'пятисотого', 6 => 'шестисотого', 7 => 'семисотого', 8 => 'восьмисотого', 9 => 'девятисотого'];

    /** Разобрать ISO YYYY-MM-DD или привычную запись DD.MM.YYYY. */
    public static function parse_date($raw)
    {
        if ( ! is_string($raw)) return NULL;
        $raw = trim($raw);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m))
        {
            $year = (int) $m[1]; $month = (int) $m[2]; $day = (int) $m[3];
        }
        elseif (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $raw, $m))
        {
            $day = (int) $m[1]; $month = (int) $m[2]; $year = (int) $m[3];
        }
        else return NULL;

        if ($year < 1 OR $year > 9999 OR ! checkdate($month, $day, $year)) return NULL;
        return ['iso' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'year' => $year, 'month' => $month, 'day' => $day];
    }

    /** День в родительном падеже («первого») или винительном («первое»). */
    public static function day_words($day, $case = 'genitive')
    {
        $day = (int) $day;
        if ($day < 1 OR $day > 31 OR ! in_array($case, ['genitive', 'accusative'], TRUE)) return NULL;
        $map = $case === 'genitive' ? self::$_day_genitive : self::$_day_accusative;
        if (isset($map[$day])) return $map[$day];
        if ($day === 31) return 'тридцать '.($case === 'genitive' ? 'первого' : 'первое');

        $tens = (int) floor($day / 10);
        $ones = $day % 10;
        $prefix = self::$_tens_cardinal[$tens] ?? '';
        $tail = $case === 'genitive' ? self::$_day_genitive[$ones] : self::$_day_accusative[$ones];
        return $prefix.' '.$tail;
    }

    /** Год как порядковое числительное: 2026 → «две тысячи двадцать шестого». */
    public static function year_words($year)
    {
        $year = (int) $year;
        if ($year < 1 OR $year > 9999) return NULL;
        $thousands = (int) floor($year / 1000);
        $remainder = $year % 1000;
        $prefix = '';
        if ($thousands === 1) $prefix = 'тысяча';
        elseif ($thousands === 2) $prefix = 'две тысячи';
        elseif ($thousands === 3) $prefix = 'три тысячи';
        elseif ($thousands === 4) $prefix = 'четыре тысячи';
        elseif ($thousands >= 5) $prefix = self::_small_cardinal($thousands).' тысяч';

        if ($remainder === 0) return $prefix;
        $tail = self::_ordinal_group($remainder);
        return trim($prefix.' '.$tail);
    }

    /** Форматы даты для документов. */
    public static function convert($raw_date)
    {
        $date = self::parse_date($raw_date);
        if ($date === NULL) return NULL;
        $month = self::$_months[$date['month']];
        $year_words = self::year_words($date['year']);
        $long = self::day_words($date['day']).' '.$month.' '.$year_words.' года';

        return [
            'date' => $date['iso'], 'day' => $date['day'], 'month' => $date['month'], 'year' => $date['year'],
            'month_word' => $month,
            'long' => $long,
            'quoted' => '«'.sprintf('%02d', $date['day']).'» '.$month.' '.$date['year'].' г.',
            'numeric' => sprintf('%02d.%02d.%04d', $date['day'], $date['month'], $date['year']),
            'plain' => $date['day'].' '.$month.' '.$date['year'].' г.',
        ];
    }

    /** Диапазон: краткая форма для одного месяца, полная — при переходе месяца/года. */
    public static function range($raw_from, $raw_to)
    {
        $from = self::parse_date($raw_from);
        $to = self::parse_date($raw_to);
        if ($from === NULL OR $to === NULL OR $to['iso'] < $from['iso']) return NULL;

        if ($from['iso'] === $to['iso'])
        {
            return ['from' => $from['iso'], 'to' => $to['iso'], 'long' => self::convert($from['iso'])['long'], 'numeric' => self::convert($from['iso'])['numeric']];
        }

        if ($from['year'] === $to['year'] AND $from['month'] === $to['month'])
        {
            $long = 'с '.self::day_words($from['day']).' по '.self::day_words($to['day'], 'accusative')
                .' '.self::$_months[$from['month']].' '.self::year_words($from['year']).' года';
        }
        else
        {
            $long = 'с '.self::day_words($from['day']).' '.self::$_months[$from['month']].' '.self::year_words($from['year']).' года'
                .' по '.self::day_words($to['day'], 'accusative').' '.self::$_months[$to['month']].' '.self::year_words($to['year']).' года';
        }

        return [
            'from' => $from['iso'], 'to' => $to['iso'], 'long' => $long,
            'numeric' => sprintf('%02d.%02d.%04d', $from['day'], $from['month'], $from['year']).' — '.sprintf('%02d.%02d.%04d', $to['day'], $to['month'], $to['year']),
        ];
    }

    protected static function _ordinal_group($number)
    {
        $number = (int) $number;
        if ($number <= 99)
        {
            if ($number <= 20 OR $number === 30) return self::$_day_genitive[$number] ?? NULL;
            if ($number % 10 !== 0)
            {
                return self::$_tens_cardinal[(int) floor($number / 10)].' '.self::$_day_genitive[$number % 10];
            }
            return self::$_tens_genitive[(int) floor($number / 10)] ?? NULL;
        }

        $hundreds = (int) floor($number / 100);
        $remainder = $number % 100;
        if ($remainder === 0) return self::$_hundreds_ordinal[$hundreds] ?? NULL;
        return self::$_hundreds_cardinal[$hundreds].' '.self::_ordinal_group($remainder);
    }

    protected static function _small_cardinal($number)
    {
        $words = [0 => 'ноль', 1 => 'одна', 2 => 'две', 3 => 'три', 4 => 'четыре', 5 => 'пять', 6 => 'шесть', 7 => 'семь', 8 => 'восемь', 9 => 'девять'];
        return $words[(int) $number] ?? '';
    }
}
