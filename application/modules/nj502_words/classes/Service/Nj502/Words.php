<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Сумма прописью» (модуль NJ-502) — чистые функции, БД и воркеры не нужны.
 *
 *   Service_Nj502_Words::rubles('22 122,00');
 *   // → «двадцать две тысячи сто двадцать два рубля 00 копеек»
 *
 * Банковский стандарт: рубли словами, копейки цифрами (00). Опция
 * KOPECKS_WORDS пишет копейки словами («...рубля ноль копеек»).
 * Диапазон: до 999 999 999 999 руб. (12 цифр), копейки 0–99.
 *
 * JS-зеркало — views/502/script.php (инлайн, тот же алгоритм). При изменении
 * формул менять ОБА файла и тесты (tests/Unit/WordsServiceTest.php).
 */
class Service_Nj502_Words {

    /** Максимальная сумма, руб. */
    const MAX_RUB = 999999999999;

    const KOPECKS_DIGITS = 0; // «...рубля 00 копеек» (платёжка)
    const KOPECKS_WORDS  = 1; // «...рубля ноль копеек» (договор)

    protected static $_ones_m = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
    protected static $_ones_f = ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
    protected static $_teens  = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать',
        'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
    protected static $_tens   = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят',
        'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
    protected static $_hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот',
        'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];

    /** [одна, две-четыре, много] */
    protected static $_forms = [
        'rub'      => ['рубль', 'рубля', 'рублей'],
        'kop'      => ['копейка', 'копейки', 'копеек'],
        'thousand' => ['тысяча', 'тысячи', 'тысяч'],
        'million'  => ['миллион', 'миллиона', 'миллионов'],
        'billion'  => ['миллиард', 'миллиарда', 'миллиардов'],
    ];

    /**
     * Сумму (рубли) → копейки. Принимает '12 345,67' | '12345.67' | 12345.
     * Копия парсера из nj501 (намеренно: модули изолированы).
     */
    public static function amount_to_kop($amount)
    {
        if (is_int($amount) OR (is_float($amount) AND floor($amount) == $amount))
        {
            $amount = (string) (int) $amount;
        }

        if ( ! is_string($amount))
        {
            return NULL;
        }

        $s = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', '', trim($amount));
        $s = str_replace(',', '.', $s);

        if ($s === '' OR ! preg_match('/^\d{1,15}(\.\d{1,2})?$/', $s))
        {
            return NULL;
        }

        $kop = (int) round((float) $s * 100, 0, PHP_ROUND_HALF_UP);

        if (intdiv($kop, 100) > self::MAX_RUB)
        {
            return NULL;
        }

        return $kop;
    }

    /** Форма существительного по числу: plural(1,'рубль','рубля','рублей') → «рубль». */
    public static function plural($n, $one, $few, $many)
    {
        $n100 = $n % 100;
        $n10  = $n % 10;

        if ($n100 >= 11 AND $n100 <= 14) return $many;
        if ($n10 == 1)  return $one;
        if ($n10 >= 2 AND $n10 <= 4) return $few;
        return $many;
    }

    /** Триады → слова; $feminine — для «тысяча» (одна/две). */
    protected static function _group_words($g, $feminine)
    {
        $w = [];
        $h = intdiv($g, 100);
        $t = intdiv($g % 100, 10);
        $u = $g % 10;

        if ($h) $w[] = self::$_hundreds[$h];
        if ($t == 1)
        {
            $w[] = self::$_teens[$g % 100 - 10]; // 10..19 → 0..9
        }
        else
        {
            if ($t) $w[] = self::$_tens[$t];
            if ($u) $w[] = ($feminine ? self::$_ones_f : self::$_ones_m)[$u];
        }

        return implode(' ', $w);
    }

    /**
     * Сумма прописью. NULL при некорректном вводе.
     *
     * @param  mixed $amount   '22 122,00' | 22122
     * @param  int   $kopecks  KOPECKS_DIGITS | KOPECKS_WORDS
     * @return string|NULL
     */
    public static function rubles($amount, $kopecks = self::KOPECKS_DIGITS)
    {
        $kop = self::amount_to_kop($amount);

        if ($kop === NULL)
        {
            return NULL;
        }

        $rub = intdiv($kop, 100);
        $fr  = $kop % 100;

        // --- рубли ---
        if ($rub === 0)
        {
            $words = ['ноль'];
        }
        else
        {
            $words = [];
            $scales = ['rub', 'thousand', 'million', 'billion'];
            $rest = $rub;
            $groups = []; // от младших триад к старшим

            while ($rest > 0)
            {
                $groups[] = $rest % 1000;
                $rest = intdiv($rest, 1000);
            }

            for ($i = count($groups) - 1; $i >= 0; $i--)
            {
                $g = $groups[$i];

                if ($g === 0)
                {
                    continue; // нулевая триада (напр. в 1 000 000) — просто пропускаем
                }

                $scale = $scales[$i];
                $words[] = self::_group_words($g, $scale === 'thousand');

                // существительное триады; для рублей — один раз после всех триад
                if ($scale !== 'rub')
                {
                    $words[] = self::plural($g, ...self::$_forms[$scale]);
                }
            }
        }

        $text = implode(' ', $words).' '.self::plural($rub, ...self::$_forms['rub']);

        // --- копейки ---
        if ($kopecks === self::KOPECKS_WORDS)
        {
            $kop_words = ($fr === 0 ? 'ноль' : self::_group_words($fr, TRUE))
                .' '.self::plural($fr, ...self::$_forms['kop']);
            $text .= ' '.$kop_words;
        }
        else
        {
            $text .= ' '.sprintf('%02d', $fr).' '.self::plural($fr, ...self::$_forms['kop']);
        }

        return $text;
    }

    /**
     * Полный результат для страницы/API.
     *
     * @param  mixed $amount
     * @param  bool  $ucfirst первая буква заглавная («Двадцать две тысячи...»)
     * @param  int   $kopecks KOPECKS_DIGITS | KOPECKS_WORDS
     * @return array|NULL
     */
    public static function convert($amount, $ucfirst = FALSE, $kopecks = self::KOPECKS_DIGITS)
    {
        $text = self::rubles($amount, $kopecks);

        if ($text === NULL)
        {
            return NULL;
        }

        if ($ucfirst)
        {
            $text = mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
        }

        $kop = self::amount_to_kop($amount);

        return [
            'text'     => $text,
            'amount'   => number_format(intdiv($kop, 100), 0, ',', ' ').','.sprintf('%02d', $kop % 100),
            'rub'      => intdiv($kop, 100),
            'kop'      => $kop % 100,
        ];
    }
}
