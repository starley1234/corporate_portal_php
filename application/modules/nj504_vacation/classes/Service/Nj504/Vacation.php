<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Отпуск: отпускные, даты, компенсация, выгодное планирование (модуль NJ-504).
 *
 * Режимы:
 *   pay          — отпускные: средний дневной заработок = доход ÷ (месяцы × 29,3)
 *                  (ст. 139 ТК РФ), × дни отпуска, минус НДФЛ (целые рубли, ст. 52 НК);
 *   dates        — отпуск «с даты на N дней»: нерабочие ПРАЗДНИЧНЫЕ дни в число дней
 *                  отпуска не входят (ст. 120 ТК) — отпуск продлевается; дата выхода,
 *                  срок выплаты (за 3 календарных дня, ст. 136 ТК), слитный отдых;
 *   compensation — компенсация за неиспользованный отпуск при увольнении (ст. 127 ТК):
 *                  28/12 дня за месяц, остаток от 15 дней округляется до месяца,
 *                  дни — в пользу работника до целого;
 *   plan         — «выгодный отпуск»: в какие даты года N дней отпуска дают самый
 *                  длинный слитный отдых вместе с праздниками и выходными.
 *
 * Календарные данные (рабочий/выходной день) берутся из nj508_calendar — единая
 * истина о переносах. А вот КАКИЕ дни — праздничные (не входят в отпуск), определяет
 * ст. 112 ТК РФ и не зависит от переносов: 1–8 января, 23 февраля, 8 марта, 1 и 9 мая,
 * 12 июня, 4 ноября. Перенесённый выходной (например, 9 января 2026) — НЕ праздник
 * и в отпуск засчитывается.
 *
 * Деньги — в копейках (как в nj501_vat).
 */
class Service_Nj504_Vacation {

    /** Среднемесячное число календарных дней (ст. 139 ТК РФ) × 10 */
    const AVG_DAYS_X10 = 293;

    /** Предел суммы дохода, ₽ */
    const MAX_INCOME = 100000000;

    const NDFL_RATES = [13, 15, 18, 20, 22];

    // ----------------------------------------------------------- праздники

    /** Нерабочий праздничный день по ст. 112 ТК РФ (независимо от переносов). */
    public static function is_holiday(DateTimeImmutable $d)
    {
        $m = (int) $d->format('n');
        $day = (int) $d->format('j');

        switch ($m)
        {
            case 1:  return $day >= 1 AND $day <= 8;
            case 2:  return $day === 23;
            case 3:  return $day === 8;
            case 5:  return $day === 1 OR $day === 9;
            case 6:  return $day === 12;
            case 11: return $day === 4;
        }

        return FALSE;
    }

    /** День отдыха (выходной/праздник/перенос) или NULL, если календаря нет. */
    protected static function _is_off(DateTimeImmutable $d, $approx = FALSE)
    {
        $i = Service_Nj508_Calendar::day_info($d);

        if ($i === NULL)
        {
            return $approx ? ((int) $d->format('N') >= 6 OR self::is_holiday($d)) : NULL;
        }

        return $i['type'] !== 'work';
    }

    // ----------------------------------------------------------- отпускные

    /**
     * Средний дневной заработок, копеек (округление до копейки half-up).
     */
    public static function avg_daily_kop($income_kop, $months)
    {
        $num = $income_kop * 10;
        $den = $months * self::AVG_DAYS_X10;

        return intdiv(2 * $num + $den, 2 * $den);
    }

    /** НДФЛ по плоской ставке в целых рублях (ст. 52 НК РФ), копеек. */
    public static function ndfl_kop($gross_kop, $rate)
    {
        return intdiv($gross_kop * (int) $rate + 5000, 10000) * 100;
    }

    /**
     * Отпускные.
     *
     * @param mixed $income   доход за расчётный период, ₽ (или среднемесячный × месяцы)
     * @param int   $months   полных месяцев в расчётном периоде (1–12)
     * @param int   $days     оплачиваемых дней отпуска (1–400)
     * @param int   $rate     ставка НДФЛ, %
     */
    public static function pay($income, $months, $days, $rate = 13)
    {
        $kop = Service_Nj501_Vat::amount_to_kop($income);
        $months = (int) $months;
        $days = (int) $days;
        $rate = (int) $rate;

        if ($kop === NULL OR $kop <= 0 OR $kop > self::MAX_INCOME * 100)
        {
            return self::_err('bad_income', 'Укажите доход за расчётный период (больше нуля, не более '.number_format(self::MAX_INCOME, 0, '', ' ').' ₽).');
        }
        if ($months < 1 OR $months > 12)
        {
            return self::_err('bad_months', 'Расчётный период — от 1 до 12 полных месяцев.');
        }
        if ($days < 1 OR $days > 400)
        {
            return self::_err('bad_days', 'Число дней отпуска — от 1 до 400.');
        }
        if ( ! in_array($rate, self::NDFL_RATES, TRUE))
        {
            return self::_err('bad_rate', 'Ставка НДФЛ: 13, 15, 18, 20 или 22 %.');
        }

        $avg = self::avg_daily_kop($kop, $months);
        $gross = $avg * $days;
        $ndfl = self::ndfl_kop($gross, $rate);

        $table = [];
        foreach ([7, 14, 21, 28] as $n)
        {
            $g = $avg * $n;
            $table[] = ['days' => $n, 'gross_kop' => $g, 'net_kop' => $g - self::ndfl_kop($g, $rate)];
        }

        $warnings = [];
        if ($rate === 13)
        {
            $warnings[] = 'НДФЛ — 13 %. Если доход с начала года превысил 2,4 млн ₽, часть отпускных облагается по повышенной ставке — выберите её вручную.';
        }
        $warnings[] = 'Расчёт упрощённый: считаются полные месяцы расчётного периода без премий за неотработанное время и индексаций. Для неполных месяцев бухгалтерия применяет коэффициент 29,3 ÷ дней в месяце.';

        return [
            'mode'       => 'pay',
            'income_kop' => $kop,
            'months'     => $months,
            'days'       => $days,
            'rate'       => $rate,
            'avg_daily_kop' => $avg,
            'gross_kop'  => $gross,
            'ndfl_kop'   => $ndfl,
            'net_kop'    => $gross - $ndfl,
            'per_day_net_kop' => intdiv($gross - $ndfl, $days),
            'table'      => $table,
            'warnings'   => $warnings,
        ];
    }

    // ----------------------------------------------------------- даты отпуска

    /**
     * Отпуск с даты на N дней.
     *
     * @param string $start Y-m-d / ДД.ММ.ГГГГ
     * @param int    $days  дней отпуска (1–400) без праздничных
     */
    public static function dates($start, $days)
    {
        $d0 = Service_Nj508_Calendar::parse_date((string) $start);
        $days = (int) $days;

        if ($d0 === NULL)
        {
            return self::_err('bad_date', 'Дата начала указана неверно — формат ДД.ММ.ГГГГ.');
        }
        if ($days < 1 OR $days > 400)
        {
            return self::_err('bad_days', 'Число дней отпуска — от 1 до 400.');
        }

        // конец: считаем дни, пропуская праздничные
        $counted = 0;
        $d = $d0;
        $holidays = [];
        $end = $d0;

        for ($guard = 0; $guard < 1000; $guard++)
        {
            if (self::is_holiday($d))
            {
                $holidays[] = $d;
            }
            else
            {
                $counted++;
                $end = $d;

                if ($counted === $days) break;
            }

            $d = $d->modify('+1 day');
        }

        // праздники ПОСЛЕ последнего дня отпуска в отпуск не входят — отсекаем
        $holidays = array_values(array_filter($holidays, function ($h) use ($end) { return $h < $end; }));

        $span = (int) $d0->diff($end)->days + 1;
        $approx = FALSE;

        // возврат на работу: первый рабочий день после конца
        $ret = $end->modify('+1 day');

        for ($i = 0; $i < 60; $i++)
        {
            $off = self::_is_off($ret);

            if ($off === NULL)
            {
                $off = self::_is_off($ret, TRUE);
                $approx = TRUE;
            }

            if ( ! $off) break;
            $ret = $ret->modify('+1 day');
        }

        // слитный отдых вокруг отпуска
        $chain = self::_chain($d0, $end);

        // сколько рабочих дней по календарю пропущено
        $work_skipped = 0;
        for ($x = $d0; $x <= $end; $x = $x->modify('+1 day'))
        {
            $off = self::_is_off($x);
            if ($off === NULL) { $off = self::_is_off($x, TRUE); $approx = TRUE; }
            if ( ! $off) $work_skipped++;
        }

        $warnings = [];
        if ($approx)
        {
            $warnings[] = 'Производственного календаря на этот год ещё нет: выходные учтены только по субботам и воскресеньям, переносы не учитываются.';
        }
        $pay_before = $d0->modify('-3 days');
        if (self::_is_off($pay_before) === TRUE)
        {
            $warnings[] = 'Срок выплаты отпускных ('.Service_Nj508_Calendar::human($pay_before).') выпадает на нерабочий день — безопаснее выплатить в предыдущий рабочий.';
        }
        if ($d0->format('N') >= 6)
        {
            $warnings[] = 'Отпуск начинается в выходной: можно сдвинуть начало на рабочий день и не тратить день отпуска впустую.';
        }

        return [
            'mode'       => 'dates',
            'start'      => $d0->format('Y-m-d'),
            'start_human'=> Service_Nj508_Calendar::human($d0).', '.Service_Nj508_Calendar::weekday($d0),
            'end'        => $end->format('Y-m-d'),
            'end_human'  => Service_Nj508_Calendar::human($end).', '.Service_Nj508_Calendar::weekday($end),
            'return'     => $ret->format('Y-m-d'),
            'return_human' => Service_Nj508_Calendar::human($ret).', '.Service_Nj508_Calendar::weekday($ret),
            'pay_before' => $d0->modify('-3 days')->format('Y-m-d'),
            'pay_before_human' => Service_Nj508_Calendar::human($d0->modify('-3 days')),
            'days'       => $days,
            'span'       => $span,
            'holidays'   => array_map(function ($h) {
                return ['date' => $h->format('Y-m-d'), 'human' => Service_Nj508_Calendar::human($h, FALSE), 'weekday' => Service_Nj508_Calendar::weekday($h)];
            }, $holidays),
            'work_skipped' => $work_skipped,
            'rest'       => $chain,
            'approx'     => $approx,
            'warnings'   => $warnings,
        ];
    }

    /**
     * Слитный отдых вокруг отпуска [d0; end]: сколько дней подряд можно не работать,
     * если примыкающие выходные и праздники идут встык.
     */
    protected static function _chain(DateTimeImmutable $d0, DateTimeImmutable $end)
    {
        $from = $d0;
        for ($i = 0; $i < 30; $i++)
        {
            $p = $from->modify('-1 day');
            $off = self::_is_off($p, TRUE);
            if ( ! $off) break;
            $from = $p;
        }

        $to = $end;
        for ($i = 0; $i < 30; $i++)
        {
            $n = $to->modify('+1 day');
            $off = self::_is_off($n, TRUE);
            if ( ! $off) break;
            $to = $n;
        }

        $len = (int) $from->diff($to)->days + 1;

        return [
            'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'),
            'from_human' => Service_Nj508_Calendar::human($from, FALSE), 'to_human' => Service_Nj508_Calendar::human($to, FALSE),
            'len' => $len,
        ];
    }

    // ----------------------------------------------------------- компенсация

    /**
     * Полные месяцы между датами с правилом округления остатка (≥ 15 дней — месяц).
     *
     * @return array ['months' => int полных, 'extra_days' => остаток, 'rounded' => int итог]
     */
    public static function worked_months(DateTimeImmutable $hire, DateTimeImmutable $end)
    {
        // включительно: первый и последний день работы — рабочие
        $to = $end->modify('+1 day');
        $p = Service_Nj508_Calendar::period($hire, $to);
        $m = $p['years'] * 12 + $p['months'];
        $d = $p['days'];

        return ['months' => $m, 'extra_days' => $d, 'rounded' => $m + ($d >= 15 ? 1 : 0)];
    }

    /**
     * Компенсация за неиспользованный отпуск.
     */
    public static function compensation($hire, $dismiss, $annual_days, $used_days, $income, $months, $rate = 13)
    {
        $h = Service_Nj508_Calendar::parse_date((string) $hire);
        $e = Service_Nj508_Calendar::parse_date((string) $dismiss);

        if ($h === NULL OR $e === NULL)
        {
            return self::_err('bad_date', 'Даты указаны неверно — формат ДД.ММ.ГГГГ.');
        }
        if ($e < $h)
        {
            return self::_err('bad_period', 'Дата увольнения раньше даты приёма.');
        }

        $annual = (int) $annual_days;
        $used = (float) str_replace(',', '.', (string) $used_days);

        if ($annual < 1 OR $annual > 70)
        {
            return self::_err('bad_annual', 'Ежегодный отпуск — от 1 до 70 дней (обычно 28).');
        }
        if ($used < 0 OR $used > 2000)
        {
            return self::_err('bad_used', 'Использованные дни — от 0 до 2000.');
        }

        $pay = self::pay($income, $months, 1, $rate);

        if (isset($pay['error']))
        {
            return $pay;
        }

        $wm = self::worked_months($h, $e);
        $earned = $annual * $wm['rounded'] / 12;
        $unused = $earned - $used;
        $paid_days = $unused > 0 ? (int) ceil(round($unused, 6)) : 0;
        $avg = $pay['avg_daily_kop'];
        $gross = $avg * $paid_days;
        $ndfl = self::ndfl_kop($gross, (int) $rate);

        $warnings = [];
        if ($unused <= 0)
        {
            $warnings[] = 'Неиспользованных дней нет: отпуск использован полностью'.($unused < 0 ? ' (возможно удержание за отпуск «авансом» — решает работодатель и только с согласия работника или по ст. 137 ТК).' : '.');
        }
        $warnings[] = 'Дни за год считаются от даты приёма без учёта отпусков без сохранения зарплаты свыше 14 дней и прогулов — они сокращают стаж для отпуска.';
        $warnings[] = 'Неполные сутки в пользу работника округляются до целого дня (разъяснение Роструда).';

        return [
            'mode'        => 'compensation',
            'hire_human'  => Service_Nj508_Calendar::human($h),
            'end_human'   => Service_Nj508_Calendar::human($e),
            'months'      => $wm['months'],
            'extra_days'  => $wm['extra_days'],
            'months_rounded' => $wm['rounded'],
            'annual_days' => $annual,
            'earned_days' => round($earned, 2),
            'used_days'   => $used,
            'unused_days' => round(max(0, $unused), 2),
            'paid_days'   => $paid_days,
            'avg_daily_kop' => $avg,
            'gross_kop'   => $gross,
            'ndfl_kop'    => $ndfl,
            'net_kop'     => $gross - $ndfl,
            'rate'        => (int) $rate,
            'warnings'    => $warnings,
        ];
    }

    // ----------------------------------------------------------- выгодный отпуск

    /**
     * Выгодные даты отпуска в году.
     *
     * Для каждого рабочего дня года берём его началом отпуска на $days дней
     * (праздники внутри отпуск продлевают), считаем слитный отдых вместе с
     * примыкающими выходными/праздниками. «Бонус» = дней подряд без работы минус дней
     * отпуска, потраченных из лимита. Одинаковые окна отдыха сводим к одному
     * (берём самое позднее начало — меньше рабочих дней потеряно в начале).
     *
     * @return array|array{error} топ окон и лучший вариант по месяцам
     */
    public static function plan($year, $days, $top = 6)
    {
        $year = (int) $year;
        $days = (int) $days;

        if ( ! Service_Nj508_Calendar::has_year($year))
        {
            return self::_err('no_calendar', 'Производственного календаря на '.$year.' год нет. Доступны: '.implode(', ', Service_Nj508_Calendar::known_years()).'.');
        }
        if ($days < 1 OR $days > 42)
        {
            return self::_err('bad_days', 'Для подбора — от 1 до 42 дней отпуска.');
        }

        $windows = [];

        for ($d = new DateTimeImmutable($year.'-01-01', new DateTimeZone('UTC')); (int) $d->format('Y') === $year; $d = $d->modify('+1 day'))
        {
            if (self::_is_off($d) !== FALSE)
            {
                continue; // начинать отпуск имеет смысл в рабочий день
            }

            // конец отпуска
            $counted = 0; $x = $d; $end = $d;
            for ($g = 0; $g < 120; $g++)
            {
                if ( ! self::is_holiday($x))
                {
                    $counted++; $end = $x;
                    if ($counted === $days) break;
                }
                $x = $x->modify('+1 day');
            }

            if ((int) $end->format('Y') !== $year)
            {
                continue; // отпуск должен уложиться в год
            }

            $chain = self::_chain($d, $end);
            $spent = $days;
            $bonus = $chain['len'] - $spent;
            $key = $chain['from'].'|'.$chain['to'];

            $w = [
                'start' => $d->format('Y-m-d'), 'start_human' => Service_Nj508_Calendar::human($d, FALSE).', '.Service_Nj508_Calendar::weekday($d),
                'end' => $end->format('Y-m-d'), 'end_human' => Service_Nj508_Calendar::human($end, FALSE).', '.Service_Nj508_Calendar::weekday($end),
                'month' => (int) $d->format('n'),
                'rest' => $chain, 'bonus' => $bonus,
                'work_days' => 0,
            ];

            // рабочих дней потрачено (из нормы часов): сколько рабочих дней реально «съел» отпуск
            for ($y = $d; $y <= $end; $y = $y->modify('+1 day'))
            {
                if (self::_is_off($y) === FALSE) $w['work_days']++;
            }

            // для одинаковых окон оставляем с меньшим числом потерянных рабочих дней, затем позднее
            if ( ! isset($windows[$key]) OR $w['work_days'] < $windows[$key]['work_days']
                OR ($w['work_days'] === $windows[$key]['work_days'] AND $w['start'] > $windows[$key]['start']))
            {
                $windows[$key] = $w;
            }
        }

        $list = array_values($windows);
        usort($list, function ($a, $b) {
            if ($a['bonus'] !== $b['bonus']) return $b['bonus'] - $a['bonus'];
            if ($a['rest']['len'] !== $b['rest']['len']) return $b['rest']['len'] - $a['rest']['len'];
            return strcmp($a['start'], $b['start']);
        });

        $by_month = [];
        foreach ($list as $w)
        {
            if ( ! isset($by_month[$w['month']])) $by_month[$w['month']] = $w;
        }
        ksort($by_month);

        return [
            'mode'     => 'plan',
            'year'     => $year,
            'days'     => $days,
            'top'      => array_slice($list, 0, max(1, (int) $top)),
            'by_month' => array_values($by_month),
            'known_years' => Service_Nj508_Calendar::known_years(),
            'warnings' => [
                'Отпуск в российском календаре считается календарными днями, поэтому выходные внутри отпуска «съедаются», а праздничные дни — нет и отпуск продлевают. Перенесённые выходные праздниками не считаются.',
            ],
        ];
    }

    protected static function _err($code, $message)
    {
        return ['error' => $code, 'message' => $message];
    }
}
