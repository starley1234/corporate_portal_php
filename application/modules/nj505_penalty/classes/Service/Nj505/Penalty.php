<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Пени и проценты за просрочку (модуль NJ-505).
 *
 * Три режима:
 *   tax      — пени по ст. 75 НК РФ: 1/300 ключевой ставки за день; для
 *              организаций с 31-го календарного дня просрочки — 1/150;
 *              ИП и физлицам — всегда 1/300;
 *   gk395    — проценты за пользование чужими деньгами (ст. 395 ГК РФ):
 *              ключевая ставка ÷ число дней в году (365/366 — по каждому
 *              году периода отдельно); мораторий ПП РФ № 497 (2022);
 *   contract — договорная неустойка: «% за каждый день просрочки», с
 *              потолком в % от долга; для сравнения показываем, сколько
 *              вышло бы по ст. 395 ГК и по двукратной ключевой ставке
 *              (ориентир для ст. 333 ГК — снижения неустойки судом).
 *
 * Чистые функции, БД не нужна. Деньги — в КОПЕЙКАХ. Ставки — в ТЫСЯЧНЫХ долях
 * процента (14,25 % → 14250), чтобы всё считалось целыми числами.
 *
 * Счёт ведётся по дням: просрочка — со следующего за сроком платежа дня по день
 * уплаты включительно (флаг incl=0 исключает день уплаты). Соседние дни с
 * одинаковыми параметрами сливаются в строки таблицы; сумма каждой строки
 * округляется до копейки (как в судебных калькуляторах), итог — сумма строк.
 *
 * Ключевая ставка — config/nj505_penalty.php (пополнять после заседаний ЦБ).
 */
class Service_Nj505_Penalty {

    const MODE_TAX      = 'tax';
    const MODE_GK395    = 'gk395';
    const MODE_CONTRACT = 'contract';

    /** Предел суммы долга, ₽ (целочисленная арифметика не должна переполняться) */
    const MAX_SUM = 100000000;

    /** @var array|NULL кеш: [ymd => r1000] отсортированный */
    protected static $_rates = NULL;

    // ----------------------------------------------------------- ставки

    protected static function _config()
    {
        return Kohana::$config->load('nj505_penalty')->as_array();
    }

    /** @return array [ymd => ставка в тысячных долях %] по возрастанию дат */
    public static function rates()
    {
        if (self::$_rates === NULL)
        {
            $out = [];
            foreach ((array) (self::_config()['key_rate'] ?? []) as $d => $r)
            {
                $out[(string) $d] = (int) round($r * 1000);
            }
            ksort($out);
            self::$_rates = $out;
        }

        return self::$_rates;
    }

    /** Ключевая ставка на дату (тысячные доли %) или NULL, если дата раньше таблицы. */
    public static function rate_on($ymd)
    {
        $found = NULL;

        foreach (self::rates() as $d => $r)
        {
            if ($d > $ymd) break;
            $found = $r;
        }

        return $found;
    }

    /** Последняя запись таблицы: ['date' => ymd, 'rate' => %] */
    public static function last_rate()
    {
        $rates = self::rates();
        $date = key(array_slice($rates, -1, 1, TRUE));

        return ['date' => $date, 'rate' => $rates[$date] / 1000.0];
    }

    /** Таблица для страницы: [['date', 'human', 'rate'], …], новые сверху. */
    public static function rates_table($limit = 0)
    {
        $out = [];

        foreach (array_reverse(self::rates(), TRUE) as $d => $r)
        {
            $dt = Service_Nj508_Calendar::parse_date($d);
            $out[] = ['date' => $d, 'human' => Service_Nj508_Calendar::human($dt), 'rate' => $r / 1000.0];
        }

        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    // ----------------------------------------------------------- ядро

    /**
     * Начисление по дням.
     *
     * @param string $kind      'tax' | 'gk' | 'fixed'
     * @param int    $sum_kop   сумма долга
     * @param string $first     первый день просрочки Y-m-d
     * @param string $last      последний день включительно Y-m-d
     * @param array  $o         payer(org|person), moratorium(bool), fixed(тыс. доли % в день), mult(множитель ставки ЦБ, для gk)
     * @return array ['rows' => [...], 'total_kop', 'days', 'excluded']
     */
    public static function accrue($kind, $sum_kop, $first, $last, array $o = [])
    {
        $org = ($o['payer'] ?? 'org') === 'org';
        $moratorium = ! empty($o['moratorium']);
        $mult = (int) ($o['mult'] ?? 1);
        $fixed = (int) ($o['fixed'] ?? 0);
        $mor = (array) (self::_config()['moratoria'] ?? []);

        $d = new DateTimeImmutable($first, new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($last, new DateTimeZone('UTC'));
        $due = $d->modify('-1 day');

        $rows = [];
        $cur = NULL;
        $excluded = 0;
        $days = 0;

        for (; $d <= $end; $d = $d->modify('+1 day'))
        {
            $ymd = $d->format('Y-m-d');

            if ($moratorium AND $kind !== 'tax' AND self::_in_moratorium($ymd, $mor))
            {
                $excluded++;
                $cur = NULL; // строка прерывается
                continue;
            }

            if ($kind === 'fixed')
            {
                $r = $fixed; $den = 1;
            }
            else
            {
                $r = self::rate_on($ymd);

                if ($r === NULL)
                {
                    return NULL;
                }

                $r *= $mult;

                if ($kind === 'tax')
                {
                    $n = (int) $due->diff($d)->days; // номер дня просрочки: 1, 2, …
                    $den = ($org AND $n >= 31) ? 150 : 300;
                }
                else
                {
                    $den = (int) $d->format('L') === 1 ? 366 : 365;
                }
            }

            if ($cur !== NULL AND $cur['r'] === $r AND $cur['den'] === $den)
            {
                $rows[$cur['i']]['to'] = $ymd;
                $rows[$cur['i']]['days']++;
            }
            else
            {
                $rows[] = ['from' => $ymd, 'to' => $ymd, 'days' => 1, 'rate' => $r / 1000.0, 'r' => $r, 'den' => $den, 'kop' => 0];
                $cur = ['i' => count($rows) - 1, 'r' => $r, 'den' => $den];
            }

            $days++;
        }

        $total = 0;

        foreach ($rows as $i => $row)
        {
            // сумма × ставка(%/1000) × дни / (100 × 1000 × знаменатель), с округлением half-up
            $num = $sum_kop * $row['r'] * $row['days'];
            $div = 100000 * $row['den'];
            $kop = intdiv(2 * $num + $div, 2 * $div);

            $rows[$i]['kop'] = $kop;
            $rows[$i]['den_text'] = $kind === 'fixed' ? 'в день' : '1/'.$row['den'];
            unset($rows[$i]['r']);
            $total += $kop;
        }

        return ['rows' => $rows, 'total_kop' => $total, 'days' => $days, 'excluded' => $excluded];
    }

    protected static function _in_moratorium($ymd, array $mor)
    {
        foreach ($mor as $m)
        {
            if ($ymd >= $m['from'] AND $ymd <= $m['to']) return TRUE;
        }

        return FALSE;
    }

    // ----------------------------------------------------------- фасад

    /**
     * Расчёт для страницы и API.
     *
     * @param string      $mode   tax | gk395 | contract
     * @param mixed       $amount долг, ₽
     * @param string      $due    срок платежа (последний день без просрочки)
     * @param string|NULL $paid   дата уплаты (NULL — сегодня)
     * @param array       $o      payer, incl(bool=1), moratorium(bool=1), rate (% в день), cap (% от долга)
     * @param string|NULL $today  «сегодня» Y-m-d (для тестов и сверки с часовым поясом клиента)
     * @return array ['error' => код, 'message' => …] | результат
     */
    public static function calculate($mode, $amount, $due, $paid = NULL, array $o = [], $today = NULL)
    {
        if ( ! in_array($mode, [self::MODE_TAX, self::MODE_GK395, self::MODE_CONTRACT], TRUE))
        {
            return self::_err('bad_mode', 'Режим: tax | gk395 | contract.');
        }

        $kop = Service_Nj501_Vat::amount_to_kop($amount);

        if ($kop === NULL OR $kop <= 0)
        {
            return self::_err('bad_amount', 'Укажите сумму долга больше нуля.');
        }

        if ($kop > self::MAX_SUM * 100)
        {
            return self::_err('too_big', 'Сумма слишком велика: до '.number_format(self::MAX_SUM, 0, '', ' ').' ₽.');
        }

        $today = $today !== NULL ? (string) $today : gmdate('Y-m-d');
        $d_due = Service_Nj508_Calendar::parse_date((string) $due);
        $d_paid = ($paid === NULL OR $paid === '') ? Service_Nj508_Calendar::parse_date($today) : Service_Nj508_Calendar::parse_date((string) $paid);

        if ($d_due === NULL OR $d_paid === NULL)
        {
            return self::_err('bad_date', 'Дата указана неверно — формат ДД.ММ.ГГГГ.');
        }

        $cfg = self::_config();
        $min = $mode === self::MODE_TAX ? $cfg['min_date_tax'] : $cfg['min_date_gk395'];
        $incl = ( ! array_key_exists('incl', $o) OR self::_bool($o['incl']));
        $moratorium = ( ! array_key_exists('moratorium', $o) OR self::_bool($o['moratorium']));
        $payer = ($o['payer'] ?? 'org') === 'person' ? 'person' : 'org';

        // просрочка: со следующего дня после срока
        $first = $d_due->modify('+1 day');
        $last = $incl ? $d_paid : $d_paid->modify('-1 day');

        if ($first->format('Y-m-d') < $min)
        {
            $h = Service_Nj508_Calendar::human(Service_Nj508_Calendar::parse_date($min));
            return self::_err('too_early', $mode === self::MODE_TAX
                ? 'Пени считаем с '.$h.' (до этого действовала иная ставка).'
                : 'Проценты по ключевой ставке (ст. 395 ГК РФ) считаем с '.$h.' — до этого применялись средние ставки банков.');
        }

        if ($last < $first)
        {
            return self::_err('no_delay', 'Просрочки нет: дата уплаты не позже срока платежа'.($incl ? '.' : ' (с учётом того, что день уплаты не считается).'));
        }

        $fixed = 0; $cap = 0.0;

        if ($mode === self::MODE_CONTRACT)
        {
            $rate = (float) str_replace(',', '.', (string) ($o['rate'] ?? ''));

            if ($rate <= 0 OR $rate > 10)
            {
                return self::_err('bad_rate', 'Ставка неустойки — от 0,001 до 10 % в день.');
            }

            $fixed = (int) round($rate * 1000);
            $cap = max(0.0, min(1000.0, (float) str_replace(',', '.', (string) ($o['cap'] ?? 0))));
        }

        $kind = $mode === self::MODE_TAX ? 'tax' : ($mode === self::MODE_GK395 ? 'gk' : 'fixed');
        $acc = self::accrue($kind, $kop, $first->format('Y-m-d'), $last->format('Y-m-d'), [
            'payer' => $payer, 'moratorium' => $moratorium, 'fixed' => $fixed,
        ]);

        if ($acc === NULL)
        {
            return self::_err('no_rate', 'Для этого периода нет ключевой ставки.');
        }

        $total = $acc['total_kop'];
        $capped = FALSE;
        $cap_kop = NULL;

        if ($mode === self::MODE_CONTRACT AND $cap > 0)
        {
            $cap_kop = intdiv($kop * (int) round($cap * 100) + 5000, 10000);

            if ($total > $cap_kop)
            {
                $total = $cap_kop;
                $capped = TRUE;
            }
        }

        $refs = [];

        if ($mode === self::MODE_CONTRACT AND $first->format('Y-m-d') >= $cfg['min_date_gk395'])
        {
            $a = self::accrue('gk', $kop, $first->format('Y-m-d'), $last->format('Y-m-d'), ['moratorium' => $moratorium]);
            $b = self::accrue('gk', $kop, $first->format('Y-m-d'), $last->format('Y-m-d'), ['moratorium' => $moratorium, 'mult' => 2]);

            if ($a !== NULL AND $b !== NULL)
            {
                $refs = ['gk395_kop' => $a['total_kop'], 'double_key_kop' => $b['total_kop']];
            }
        }

        $warnings = [];
        $last_s = $last->format('Y-m-d');
        $lr = self::last_rate();

        if ($mode !== self::MODE_CONTRACT)
        {
            if ($last_s > $today)
            {
                $warnings[] = 'Дата уплаты в будущем: будущая ключевая ставка неизвестна, применена действующая — '.str_replace('.', ',', (string) $lr['rate']).' %.';
            }

            if ($today > $cfg['next_meeting'] AND $last_s > $cfg['as_of'])
            {
                $warnings[] = 'Таблица ключевой ставки проверена '.$cfg['as_of'].': после заседания Банка России '.$cfg['next_meeting'].' ставка могла измениться — сверьтесь с cbr.ru.';
            }
        }

        if ($mode === self::MODE_TAX)
        {
            $warnings[] = 'Срок уплаты укажите с учётом переноса с выходного дня на ближайший рабочий. Пени зависят от даты фактического зачисления платежа на единый налоговый счёт.';
        }

        if ($moratorium AND $acc['excluded'] > 0)
        {
            $warnings[] = 'Из расчёта исключено '.$acc['excluded'].' '.Service_Nj508_Calendar::plural($acc['excluded'], 'день', 'дня', 'дней')
                .' моратория (ПП РФ № 497: 01.04.2022–01.10.2022). Мораторий не действует, если должник от него отказался.';
        }

        if ($capped)
        {
            $warnings[] = 'Неустойка ограничена потолком — '.str_replace('.', ',', (string) $cap).' % от долга.';
        }

        $d_first = $first; $d_last = $last;

        return [
            'mode'        => $mode,
            'payer'       => $payer,
            'amount_kop'  => $kop,
            'due'         => $d_due->format('Y-m-d'),
            'due_human'   => Service_Nj508_Calendar::human($d_due),
            'paid'        => $d_paid->format('Y-m-d'),
            'paid_human'  => Service_Nj508_Calendar::human($d_paid),
            'first_human' => Service_Nj508_Calendar::human($d_first),
            'last_human'  => Service_Nj508_Calendar::human($d_last),
            'incl'        => $incl,
            'moratorium'  => $moratorium,
            'days_all'    => (int) $d_first->diff($d_last)->days + 1,
            'days'        => $acc['days'],
            'excluded'    => $acc['excluded'],
            'rows'        => $acc['rows'],
            'accrued_kop' => $acc['total_kop'],
            'total_kop'   => $total,
            'capped'      => $capped,
            'cap_kop'     => $cap_kop,
            'sum_with_kop'=> $kop + $total,
            'refs'        => $refs,
            'warnings'    => $warnings,
            'rates_as_of' => $cfg['as_of'],
            'text'        => [
                'penalty' => Service_Nj501_Vat::format_kop($total),
                'total'   => Service_Nj501_Vat::format_kop($kop + $total),
            ],
        ];
    }

    protected static function _bool($v)
    {
        return $v === TRUE OR in_array((string) $v, ['1', 'true', 'on', 'yes'], TRUE);
    }

    protected static function _err($code, $message)
    {
        return ['error' => $code, 'message' => $message];
    }
}
