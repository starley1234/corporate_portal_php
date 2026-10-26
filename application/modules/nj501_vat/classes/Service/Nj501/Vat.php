<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Калькулятор НДС (модуль NJ-501) — чистые функции, БД и воркеры не нужны.
 *
 * Все суммы считаются В КОПЕЙКАХ (целые), округление — математическое до
 * копейки (half-up), как принято в бухгалтерских расчётах.
 *
 *   // выделить НДС 22% из суммы с НДС
 *   Service_Nj501_Vat::calculate('10 000', 22, 'extract');
 *   // → vat = 10 000 × 22/122 = 1 803,28; net = 8 196,72; total = 10 000,00
 *
 *   // начислить НДС 5% (УСН) сверху
 *   Service_Nj501_Vat::calculate(1000, 5, 'add');
 *   // → vat = 50,00; net = 1 000,00; total = 1 050,00
 *
 * Ставки (2026): 22% — общая (с 01.01.2026), 20% — общая до 2026,
 * 10% — льготная, 7%/5% — пониженные для УСН, 0% — без НДС.
 * Расчётные ставки (выделение из суммы): 22/122, 20/120, 10/110, 7/107, 5/105.
 *
 * JS-зеркало этих формул живёт во вьюхе views/501/script.php (инлайн <script>,
 * подключается и на /vat, и на главной для мини-демо) — при изменении формул
 * менять ОБА файла и запускать тесты (tests/Unit/VatServiceTest).
 */
class Service_Nj501_Vat {

    const MODE_EXTRACT = 'extract'; // НДС в сумме (выделить)
    const MODE_ADD     = 'add';     // НДС сверху (начислить)

    /** Максимальная сумма, руб. (защита от абсурдного ввода) */
    const MAX_AMOUNT = 999999999999;

    /** @var array доступные ставки => подписи */
    protected static $_rates = [
        22 => 'Общая ставка с 2026 года',
        20 => 'Общая ставка до 2026 года',
        10 => 'Льготная ставка',
        7  => 'Пониженная (УСН, доход 272,5–490,5 млн ₽)',
        5  => 'Пониженная (УСН, доход 20–272,5 млн ₽)',
        0  => 'Без НДС',
    ];

    /**
     * Список ставок для UI: [['rate'=>22,'label'=>..., 'note'=>...], ...].
     */
    public static function rates()
    {
        $notes = [
            22 => '2026', 20 => 'до 2026', 10 => '', 7 => 'УСН', 5 => 'УСН', 0 => '',
        ];
        $result = [];
        foreach (self::$_rates as $rate => $label)
        {
            $result[] = [
                'rate'  => $rate,
                'label' => $label,
                'note'  => $notes[$rate],
            ];
        }
        return $result;
    }

    /**
     * Нормализовать ставку: целое из списка или NULL.
     *
     * @param  mixed $rate
     * @return int|null
     */
    public static function normalize_rate($rate)
    {
        $rate = (int) $rate;
        return isset(self::$_rates[$rate]) ? $rate : NULL;
    }

    /**
     * Сумму (рубли; строка или число) → целые копейки. Некорректное — NULL.
     *
     * Принимает: 1000 | '1000' | '1 000' | '1 234,56' | '1234.5' | '1 234 567,89'
     *
     * @param  mixed $amount
     * @return int|null
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

        // убираем пробелы всех видов (обычные, nbsp, thin space)
        $s = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', '', trim($amount));
        $s = str_replace(',', '.', $s);

        if ($s === '' OR ! preg_match('/^\d{1,15}(\.\d{1,2})?$/', $s))
        {
            return NULL;
        }

        $kop = (int) round((float) $s * 100, 0, PHP_ROUND_HALF_UP);

        if ($kop > self::MAX_AMOUNT * 100)
        {
            return NULL;
        }

        return $kop;
    }

    /**
     * Копейки → строка «1 234 567,89 ₽».
     *
     * @param  int $kop
     * @param  bool $with_symbol
     * @return string
     */
    public static function format_kop($kop, $with_symbol = TRUE)
    {
        $kop = (int) $kop;
        $rub = intdiv($kop, 100);
        $fr  = sprintf('%02d', $kop % 100);

        $out = number_format($rub, 0, ',', ' ').','.$fr;
        return $with_symbol ? $out.' ₽' : $out;
    }

    /**
     * Расчёт НДС. Единая точка истины (SSR, API, тесты; JS — зеркало).
     *
     * @param  mixed  $amount сумма: '10 000' | 10000 | '1234,56'
     * @param  int    $rate   ставка из rates() (0,5,7,10,20,22)
     * @param  string $mode   extract (НДС в сумме) | add (начислить сверху)
     * @return array|NULL      NULL при некорректных данных
     */
    public static function calculate($amount, $rate, $mode)
    {
        $rate = self::normalize_rate($rate);

        if ($rate === NULL OR ($mode !== self::MODE_EXTRACT AND $mode !== self::MODE_ADD))
        {
            return NULL;
        }

        $kop = self::amount_to_kop($amount);

        if ($kop === NULL)
        {
            return NULL;
        }

        if ($mode === self::MODE_EXTRACT)
        {
            // НДС = S × r/(100+r)
            $vat = (int) round($kop * $rate / (100 + $rate), 0, PHP_ROUND_HALF_UP);
            $net = $kop - $vat;
            $total = $kop;
            $formula = $rate > 0
                ? self::format_kop($kop, FALSE).' × '.$rate.'/'.$rate.'00'
                : 'без НДС';
        }
        else
        {
            // НДС = S × r/100
            $net = $kop;
            $vat = (int) round($kop * $rate / 100, 0, PHP_ROUND_HALF_UP);
            $total = $kop + $vat;
            $formula = $rate > 0
                ? self::format_kop($kop, FALSE).' × '.$rate.'%'
                : 'без НДС';
        }

        return [
            'mode'      => $mode,
            'rate'      => $rate,
            'amount'    => self::format_kop($kop), // введённая сумма
            'net'       => self::format_kop($net),  // без НДС
            'vat'       => self::format_kop($vat),  // НДС
            'total'     => self::format_kop($total),// итого
            'formula'   => $formula,
            // копейки — для JS/интеграций:
            'amount_kop'=> $kop,
            'net_kop'   => $net,
            'vat_kop'   => $vat,
            'total_kop' => $total,
        ];
    }
}
