<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Кредит и ипотека (модуль NJ-647) — чистые функции, БД и воркеры не нужны.
 *
 * Считает график платежей помесячно: аннуитет (равные платежи) и
 * дифференцированные (убывающие), с досрочными погашениями — либо сокращая
 * срок, либо уменьшая платёж. Одна точка истины для страницы, API и тестов.
 *
 * ВАЖНО о точности. Банк начисляет проценты ПО ДНЯМ (остаток × ставка × дни /
 * дней в году), здесь же месяц считается равным 1/12 года. Отсюда расхождение
 * с графиком банка в пределах сотен рублей на месяц — для выбора срока и
 * сравнения предложений это несущественно, для сверки с банком — нет. Так и
 * написано на странице: сервис не притворяется банковской выпиской.
 */
class Service_Nj647_Loan {

    /** Потолок суммы кредита (защита от абсурда и переполнения) */
    const MAX_SUM = 1.0E+11;

    /** Потолок ставки, % годовых */
    const MAX_RATE = 200.0;

    /** Потолок срока в месяцах (50 лет) */
    const MAX_MONTHS = 600;

    /**
     * Типы платежа для UI.
     *
     * @return array code => [title, hint]
     */
    public static function types()
    {
        return [
            'annuity' => [
                'title' => 'Аннуитетный',
                'hint'  => 'Равный платёж весь срок. Так работает почти вся ипотека и потребкредиты.',
            ],
            'diff' => [
                'title' => 'Дифференцированный',
                'hint'  => 'Долг гасится равными долями, платёж убывает. Переплата меньше, но первые платежи тяжелее.',
            ],
        ];
    }

    /**
     * Что делать с досрочными погашениями.
     *
     * @return array code => title
     */
    public static function extra_modes()
    {
        return [
            'term'    => 'Сокращать срок',
            'payment' => 'Уменьшать платёж',
        ];
    }

    /**
     * Число из пользовательского ввода: «1 500 000,50», «1500000.5», «−7».
     *
     * @param  mixed $value
     * @return float|NULL NULL — если это не число
     */
    public static function parse_number($value)
    {
        if (is_int($value) OR is_float($value))
        {
            return is_finite((float) $value) ? (float) $value : NULL;
        }

        if ( ! is_string($value))
        {
            return NULL;
        }

        $s = trim($value);

        if ($s === '')
        {
            return NULL;
        }

        // пробелы-разделители разрядов (включая неразрывные) и минусы-юникоды
        $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' ', "'"], '', $s);
        $s = str_replace(["\xE2\x88\x92", "\xE2\x80\x93", "\xE2\x80\x94"], '-', $s);
        $s = str_replace(',', '.', $s);

        if ( ! preg_match('/^-?(?:\d+(?:\.\d+)?|\.\d+)$/', $s))
        {
            return NULL;
        }

        $n = (float) $s;

        return is_finite($n) ? $n : NULL;
    }

    /**
     * Деньги по-русски: 1 234 567,89 (неразрывные пробелы).
     *
     * @param  float|NULL $value
     * @param  int        $decimals
     * @return string
     */
    public static function fmt($value, $decimals = 2)
    {
        if ($value === NULL OR ! is_finite((float) $value))
        {
            return '—';
        }

        $s = number_format((float) $value, (int) $decimals, ',', "\xC2\xA0");

        return str_replace('-', "\xE2\x88\x92", $s);
    }

    /**
     * Срок словами: 18 месяцев → «1 год 6 месяцев».
     *
     * @param  int $months
     * @return string
     */
    public static function term_words($months)
    {
        $months = max(0, (int) $months);
        $y = intdiv($months, 12);
        $m = $months % 12;

        $parts = [];

        if ($y > 0)
        {
            $parts[] = $y.' '.self::_plural($y, 'год', 'года', 'лет');
        }

        if ($m > 0 OR $y === 0)
        {
            $parts[] = $m.' '.self::_plural($m, 'месяц', 'месяца', 'месяцев');
        }

        return implode(' ', $parts);
    }

    /**
     * «133 платежа», «240 платежей» — склонение для подписи под сроком.
     *
     * @param  int $n
     * @return string
     */
    public static function payments_word($n)
    {
        return self::_plural($n, 'платёж', 'платежа', 'платежей');
    }

    /**
     * Аннуитетный платёж: S·i/(1−(1+i)^−n). При нулевой ставке — просто S/n.
     *
     * @param  float $balance остаток долга
     * @param  float $i       месячная ставка (доля, не проценты)
     * @param  int   $months  платежей впереди
     * @return float
     */
    public static function annuity_payment($balance, $i, $months)
    {
        $months = (int) $months;

        if ($months < 1)
        {
            return (float) $balance;
        }

        if ($i <= 0)
        {
            return $balance / $months;
        }

        return $balance * $i / (1 - pow(1 + $i, -$months));
    }

    /**
     * Обратный расчёт: какой кредит потянет заданный ежемесячный платёж.
     *
     * @param  float $payment
     * @param  float $rate    % годовых
     * @param  int   $months
     * @return float|NULL
     */
    public static function capacity($payment, $rate, $months)
    {
        $payment = (float) $payment;
        $months  = (int) $months;

        if ($payment <= 0 OR $months < 1 OR $rate < 0)
        {
            return NULL;
        }

        $i = $rate / 100 / 12;

        return $i <= 0 ? $payment * $months : $payment * (1 - pow(1 + $i, -$months)) / $i;
    }

    /**
     * Главный расчёт.
     *
     * Вход: sum, rate, term (+ term_unit = years|months) ИЛИ months, type,
     * extra, extra_mode, start (дата выдачи, Y-m-d).
     *
     * @param  array $in
     * @return array ['ok'=>bool, 'error'=>string, ...расчёт]
     */
    public static function calculate(array $in)
    {
        $sum  = self::parse_number(Arr::get($in, 'sum', ''));
        $rate = self::parse_number(Arr::get($in, 'rate', ''));

        if ($sum === NULL OR $sum <= 0 OR $sum > self::MAX_SUM)
        {
            return self::_error('Укажите сумму кредита — положительное число не больше '.self::fmt(self::MAX_SUM, 0).'.');
        }

        if ($rate === NULL OR $rate < 0 OR $rate > self::MAX_RATE)
        {
            return self::_error('Ставка — от 0 до '.self::MAX_RATE.' % годовых.');
        }

        $months = self::_months($in);

        if ($months === NULL)
        {
            return self::_error('Срок — от 1 месяца до '.self::MAX_MONTHS.' месяцев (50 лет).');
        }

        $type = (string) Arr::get($in, 'type', 'annuity');
        $type = isset(self::types()[$type]) ? $type : 'annuity';

        $extra = self::parse_number(Arr::get($in, 'extra', '0'));
        $extra = ($extra === NULL) ? 0.0 : $extra;

        if ($extra < 0 OR $extra > self::MAX_SUM)
        {
            return self::_error('Досрочное погашение — неотрицательное число.');
        }

        $extra_mode = (string) Arr::get($in, 'extra_mode', 'term');
        $extra_mode = isset(self::extra_modes()[$extra_mode]) ? $extra_mode : 'term';

        $start = self::_date(Arr::get($in, 'start', ''));

        $plain = self::_simulate($sum, $rate, $months, $type, 0.0, $extra_mode, $start);

        if ($plain === NULL)
        {
            return self::_error('При такой ставке и сроке платёж не покрывает проценты — проверьте данные.');
        }

        $run = ($extra > 0) ? self::_simulate($sum, $rate, $months, $type, $extra, $extra_mode, $start) : $plain;

        if ($run === NULL)
        {
            return self::_error('Расчёт не сошёлся: проверьте сумму, ставку и срок.');
        }

        $rows  = $run['rows'];
        $first = $rows[0];
        $last  = $rows[count($rows) - 1];

        $result = [
            'ok'         => TRUE,
            'error'      => '',
            'type'       => $type,
            'sum'        => $sum,
            'rate'       => $rate,
            'months'     => $months,
            'extra'      => $extra,
            'extra_mode' => $extra_mode,
            'start'      => $start,
            // Платёж: у аннуитета он один на весь срок, у дифференцированного — убывает
            'payment'       => $first['payment'],
            'payment_first' => $first['payment'],
            'payment_last'  => $last['payment'],
            'total'         => $run['total'],
            'interest'      => $run['interest'],
            // Переплата к телу кредита: 100 % значит «вернули две суммы»
            'interest_pct'  => $sum > 0 ? $run['interest'] / $sum * 100 : 0.0,
            'months_real'   => count($rows),
            'end'           => $last['date'],
            'turn_month'    => $run['turn'],
            'rows'          => $rows,
            'years'         => self::_by_years($rows),
            'capacity'      => self::capacity($first['payment'], $rate, $months),
        ];

        // Что дало досрочное погашение — считаем от того же кредита без него
        $result['saved'] = ($extra > 0) ? [
            'months'   => count($plain['rows']) - count($rows),
            'interest' => $plain['interest'] - $run['interest'],
            'total'    => $plain['total'],
            'end'      => $plain['rows'][count($plain['rows']) - 1]['date'],
        ] : NULL;

        return $result;
    }

    /**
     * Помесячный прогон графика.
     *
     * @return array|NULL NULL — платёж не покрывает проценты (долг не гасится)
     */
    protected static function _simulate($sum, $rate, $months, $type, $extra, $extra_mode, $start)
    {
        $i       = $rate / 100 / 12;
        $balance = (float) $sum;
        $rows    = [];
        $total   = 0.0;
        $paid_i  = 0.0;
        $turn    = NULL;

        $fixed_payment   = ($type === 'annuity') ? self::annuity_payment($sum, $i, $months) : 0.0;
        $fixed_principal = $sum / $months;

        for ($n = 1; $n <= $months AND $balance > 0.005; $n++)
        {
            $left     = $months - $n + 1;   // платежей по ИСХОДНОМУ сроку
            $interest = $balance * $i;

            if ($type === 'annuity')
            {
                // «Уменьшать платёж» — пересчёт аннуитета от нового остатка
                $payment = ($extra > 0 AND $extra_mode === 'payment')
                    ? self::annuity_payment($balance, $i, $left)
                    : $fixed_payment;

                $principal = $payment - $interest;
            }
            else
            {
                $principal = ($extra > 0 AND $extra_mode === 'payment')
                    ? $balance / $left
                    : $fixed_principal;

                $payment = $principal + $interest;
            }

            if ($principal <= 0)
            {
                return NULL;   // долг не уменьшается — считать нечего
            }

            $extra_now = (float) $extra;

            // Последний платёж: гасим ровно остаток, лишнего не берём
            if ($principal >= $balance)
            {
                $principal = $balance;
                $extra_now = 0.0;
                $payment   = $principal + $interest;
            }
            elseif ($principal + $extra_now > $balance)
            {
                $extra_now = $balance - $principal;
            }

            $balance -= ($principal + $extra_now);
            $total   += ($payment + $extra_now);
            $paid_i  += $interest;

            if ($turn === NULL AND $principal >= $interest)
            {
                $turn = $n;   // месяц, с которого тело долга гасится быстрее процентов
            }

            $rows[] = [
                'n'         => $n,
                'date'      => self::add_months($start, $n),
                'payment'   => $payment,
                'interest'  => $interest,
                'principal' => $principal,
                'extra'     => $extra_now,
                'balance'   => $balance < 0.005 ? 0.0 : $balance,
            ];
        }

        if ( ! $rows)
        {
            return NULL;
        }

        return [
            'rows'     => $rows,
            'total'    => $total,
            'interest' => $paid_i,
            'turn'     => $turn,
        ];
    }

    /**
     * Свод по календарным годам — по нему видно, куда уходят деньги.
     *
     * @param  array $rows
     * @return array
     */
    protected static function _by_years(array $rows)
    {
        $years = [];

        foreach ($rows as $row)
        {
            $y = (int) substr($row['date'], 0, 4);

            if ( ! isset($years[$y]))
            {
                $years[$y] = [
                    'year'      => $y,
                    'payment'   => 0.0,
                    'interest'  => 0.0,
                    'principal' => 0.0,
                    'months'    => 0,
                    'balance'   => 0.0,
                    'rows'      => [],
                ];
            }

            $years[$y]['payment']   += $row['payment'] + $row['extra'];
            $years[$y]['interest']  += $row['interest'];
            $years[$y]['principal'] += $row['principal'] + $row['extra'];
            $years[$y]['months']++;
            $years[$y]['balance'] = $row['balance'];
            $years[$y]['rows'][]  = $row;
        }

        return array_values($years);
    }

    /**
     * Дата + N месяцев с прижатием к последнему дню месяца.
     *
     * PHP сам по себе из 31 января делает 3 марта («+1 month»), в графике
     * платежей это выглядит ошибкой — поэтому день обрезается до конца месяца.
     *
     * @param  string $date Y-m-d
     * @param  int    $add
     * @return string Y-m-d
     */
    public static function add_months($date, $add)
    {
        $t = strtotime($date.' 12:00:00');

        if ($t === FALSE)
        {
            return $date;
        }

        $day   = (int) date('j', $t);
        $month = (int) date('n', $t) + (int) $add;
        $year  = (int) date('Y', $t) + intdiv($month - 1, 12);
        $month = ($month - 1) % 12 + 1;

        if ($month < 1)
        {
            $month += 12;
            $year--;
        }

        $last = (int) date('t', mktime(12, 0, 0, $month, 1, $year));

        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $last));
    }

    /**
     * Срок в месяцах из term/term_unit или months.
     *
     * @return int|NULL
     */
    protected static function _months(array $in)
    {
        $months = self::parse_number(Arr::get($in, 'months', ''));

        if ($months === NULL)
        {
            $term = self::parse_number(Arr::get($in, 'term', ''));
            $unit = (string) Arr::get($in, 'term_unit', 'years');

            if ($term === NULL)
            {
                return NULL;
            }

            $months = ($unit === 'months') ? $term : $term * 12;
        }

        $months = (int) round($months);

        return ($months >= 1 AND $months <= self::MAX_MONTHS) ? $months : NULL;
    }

    /** Дата выдачи: корректная Y-m-d или сегодня. */
    protected static function _date($value)
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))
        {
            $t = strtotime($value.' 12:00:00');

            if ($t !== FALSE AND date('Y-m-d', $t) === $value)
            {
                return $value;
            }
        }

        return date('Y-m-d');
    }

    protected static function _error($message)
    {
        return ['ok' => FALSE, 'error' => (string) $message];
    }

    protected static function _plural($n, $one, $few, $many)
    {
        $n = abs((int) $n) % 100;
        $n1 = $n % 10;

        if ($n > 10 AND $n < 20)
        {
            return $many;
        }

        if ($n1 > 1 AND $n1 < 5)
        {
            return $few;
        }

        return $n1 === 1 ? $one : $many;
    }
}
