<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Производственный календарь РФ и даты (модуль NJ-508) — чистые функции.
 *
 * Данные по годам — config/nj508_calendar.php (таблица, не формула).
 * Принципы:
 *  - Год, которого нет в таблице, молча НЕ считается «как прошлый»: методы
 *    возвращают error=no_calendar с перечнем годов. Явное разрешение
 *    $approx=TRUE считает по обычным выходным (сб/вс) и помечает результат
 *    approx=TRUE — пользователь видит оговорку; администратору уходит
 *    предупреждение в журнал (один раз за запрос на год).
 *  - Даты — строки «YYYY-MM-DD», арифметика в UTC (без перехода на летнее время).
 *  - Возраст/срок в годах и месяцах — по ст. 192 ГК РФ: если в последнем месяце
 *    нет соответствующего числа, срок истекает в последний день месяца
 *    (29 февраля → 28 февраля в невисокосный год).
 */
class Service_Nj508_Calendar {

    /** Допустимые границы дат (защита от абсурда и бесконечных циклов) */
    const MIN_YEAR = 1900;
    const MAX_YEAR = 2100;

    /** @var array кэш конфига */
    protected static $_years = NULL;

    /** @var array годы, о которых уже предупредили в этом запросе */
    protected static $_warned = [];

    protected static $_months = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
        7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];

    protected static $_months_gen = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    protected static $_weekdays = [
        1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг',
        5 => 'пятница', 6 => 'суббота', 7 => 'воскресенье',
    ];

    // ------------------------------------------------------------------ данные

    /** @return array год => данные года */
    public static function years_data()
    {
        if (self::$_years === NULL)
        {
            $cfg = Kohana::$config->load('nj508_calendar')->as_array();
            self::$_years = isset($cfg['years']) && is_array($cfg['years']) ? $cfg['years'] : [];
            ksort(self::$_years);
        }

        return self::$_years;
    }

    /** @return int[] годы, для которых календарь заполнен */
    public static function known_years()
    {
        return array_keys(self::years_data());
    }

    public static function has_year($year)
    {
        return isset(self::years_data()[(int) $year]);
    }

    /** Последний заполненный год (или NULL). */
    public static function last_year()
    {
        $y = self::known_years();
        return $y ? max($y) : NULL;
    }

    /** Подсказка администратору: календарь на год вперёд ещё не заполнен? */
    public static function next_year_missing()
    {
        $last = self::last_year();
        return $last === NULL OR $last < (int) date('Y') + 1;
    }

    public static function month_name($m, $genitive = FALSE)
    {
        $m = (int) $m;
        return $genitive ? (self::$_months_gen[$m] ?? '') : (self::$_months[$m] ?? '');
    }

    public static function weekday_name($n)
    {
        return self::$_weekdays[(int) $n] ?? '';
    }

    // ------------------------------------------------------------------- даты

    /**
     * 'YYYY-MM-DD' | 'DD.MM.YYYY' → DateTimeImmutable (UTC, 00:00) или NULL.
     */
    public static function parse_date($s)
    {
        if ($s instanceof DateTimeImmutable)
        {
            return $s;
        }

        if ( ! is_string($s))
        {
            return NULL;
        }

        $s = trim($s);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m))
        {
            $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
        }
        elseif (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $s, $m))
        {
            $y = (int) $m[3]; $mo = (int) $m[2]; $d = (int) $m[1];
        }
        else
        {
            return NULL;
        }

        if ($y < self::MIN_YEAR OR $y > self::MAX_YEAR OR ! checkdate($mo, $d, $y))
        {
            return NULL;
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $mo, $d), new DateTimeZone('UTC'));
    }

    /** «5 марта 2026 г.» */
    public static function human(DateTimeImmutable $d, $with_year = TRUE)
    {
        return (int) $d->format('j').' '.self::month_name((int) $d->format('n'), TRUE)
            .($with_year ? ' '.$d->format('Y').' г.' : '');
    }

    /** «пятница» */
    public static function weekday(DateTimeImmutable $d)
    {
        return self::weekday_name((int) $d->format('N'));
    }

    /** Разница в календарных днях b − a (может быть < 0). */
    public static function days_diff(DateTimeImmutable $a, DateTimeImmutable $b)
    {
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /** Дата Y-m-d с днём, обрезанным до конца месяца (ст. 192 ГК РФ). */
    protected static function _clamp($y, $m, $d)
    {
        $last = (int) cal_days_in_month(CAL_GREGORIAN, $m, $y);
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, min($d, $last)), new DateTimeZone('UTC'));
    }

    /** Дата + N месяцев с обрезкой дня по концу месяца. */
    public static function add_months(DateTimeImmutable $d, $n)
    {
        $total = ((int) $d->format('Y')) * 12 + ((int) $d->format('n') - 1) + (int) $n;
        return self::_clamp(intdiv($total, 12), $total % 12 + 1, (int) $d->format('j'));
    }

    // -------------------------------------------------------------- тип дня

    /**
     * Описание дня по календарю.
     *
     * @return array|NULL  NULL — года нет в таблице.
     *   ['type' => work|weekend|holiday, 'hours' => 8|7|0, 'name' => string, 'short' => bool]
     */
    public static function day_info(DateTimeImmutable $d)
    {
        $years = self::years_data();
        $y = (int) $d->format('Y');

        if ( ! isset($years[$y]))
        {
            return NULL;
        }

        return self::_day($d, $years[$y]);
    }

    protected static function _day(DateTimeImmutable $d, array $data)
    {
        $key = $d->format('Y-m-d');
        $short = in_array($key, $data['short'] ?? [], TRUE);

        if (in_array($key, $data['work'] ?? [], TRUE))
        {
            return ['type' => 'work', 'hours' => $short ? 7 : 8, 'name' => 'Рабочий день (перенос)', 'short' => $short];
        }

        if (isset($data['off'][$key]))
        {
            return ['type' => 'holiday', 'hours' => 0, 'name' => $data['off'][$key], 'short' => FALSE];
        }

        if ((int) $d->format('N') >= 6)
        {
            return ['type' => 'weekend', 'hours' => 0, 'name' => 'Выходной', 'short' => FALSE];
        }

        return ['type' => 'work', 'hours' => $short ? 7 : 8, 'name' => $short ? 'Сокращённый день' : 'Рабочий день', 'short' => $short];
    }

    /** Приближённая классификация (года нет в таблице): только сб/вс. */
    protected static function _day_approx(DateTimeImmutable $d)
    {
        return (int) $d->format('N') >= 6
            ? ['type' => 'weekend', 'hours' => 0, 'name' => 'Выходной', 'short' => FALSE]
            : ['type' => 'work', 'hours' => 8, 'name' => 'Рабочий день', 'short' => FALSE];
    }

    /** Предупредить администратора о годе без календаря (один раз за запрос). */
    protected static function _warn_missing(array $years)
    {
        foreach ($years as $y)
        {
            if (isset(self::$_warned[$y])) continue;
            self::$_warned[$y] = TRUE;

            if (class_exists('Kohana') AND Kohana::$log)
            {
                Kohana::$log->add(Log::WARNING, 'nj508: нет производственного календаря на :y год — добавьте его в config/nj508_calendar.php', [':y' => $y]);
            }
        }
    }

    /** Годы диапазона [a; b], для которых календаря нет. */
    protected static function _missing_years(DateTimeImmutable $a, DateTimeImmutable $b)
    {
        $miss = [];
        $known = self::years_data();

        for ($y = (int) $a->format('Y'); $y <= (int) $b->format('Y'); $y++)
        {
            if ( ! isset($known[$y])) $miss[] = $y;
        }

        return $miss;
    }

    protected static function _no_calendar(array $miss)
    {
        self::_warn_missing($miss);

        return [
            'error'   => 'no_calendar',
            'message' => 'Производственного календаря на '.implode(', ', $miss).' год ещё нет: '
                .'переносы выходных утверждаются Правительством отдельным постановлением. '
                .'Можно посчитать приблизительно — считая выходными только субботу и воскресенье.',
            'missing_years' => $miss,
            'known_years'   => self::known_years(),
        ];
    }

    // ------------------------------------------------------------ расчёты

    /**
     * Дни между датами.
     *
     * @param  bool $inclusive считать и первый, и последний день
     * @param  bool $approx    разрешить расчёт по сб/вс для годов вне таблицы
     * @return array  результат или ['error' => ...]
     */
    public static function between($from, $to, $inclusive = TRUE, $approx = FALSE)
    {
        $a = self::parse_date($from);
        $b = self::parse_date($to);

        if ($a === NULL OR $b === NULL)
        {
            return ['error' => 'bad_date', 'message' => 'Укажите даты в формате ДД.ММ.ГГГГ (годы 1900–2100).'];
        }

        $swapped = FALSE;
        if ($b < $a)
        {
            [$a, $b] = [$b, $a];
            $swapped = TRUE;
        }

        $miss = self::_missing_years($a, $b);

        if ($miss AND ! $approx)
        {
            return self::_no_calendar($miss);
        }

        if ($miss)
        {
            self::_warn_missing($miss);
        }

        $end = $inclusive ? $b : $b->modify('-1 day');
        $start = $a;

        $total = 0; $work = 0; $weekend = 0; $holiday = 0; $hours = 0; $short = 0;
        $years = self::years_data();

        if ($end >= $start)
        {
            for ($d = $start; $d <= $end; $d = $d->modify('+1 day'))
            {
                $y = (int) $d->format('Y');
                $info = isset($years[$y]) ? self::_day($d, $years[$y]) : self::_day_approx($d);
                $total++;

                if ($info['type'] === 'work')
                {
                    $work++; $hours += $info['hours']; if ($info['short']) $short++;
                }
                elseif ($info['type'] === 'holiday') { $holiday++; }
                else { $weekend++; }
            }
        }

        $days_gap = self::days_diff($a, $b);

        return [
            'from'        => $a->format('Y-m-d'),
            'to'          => $b->format('Y-m-d'),
            'swapped'     => $swapped,
            'inclusive'   => (bool) $inclusive,
            'calendar'    => $total,
            'work'        => $work,
            'weekend'     => $weekend,
            'holiday'     => $holiday,
            'off'         => $weekend + $holiday,
            'short'       => $short,
            'hours'       => $hours,
            'weeks'       => intdiv($total, 7),
            'weeks_days'  => $total % 7,
            'gap'         => $days_gap,
            'approx'      => (bool) $miss,
            'missing_years' => $miss,
        ];
    }

    /**
     * Сколько до даты (или прошло с даты).
     *
     * @param string $target дата-цель
     * @param string $today  «сегодня» (передаёт браузер — чтобы учесть его часовой пояс)
     */
    public static function until($target, $today = NULL, $approx = FALSE)
    {
        $t = self::parse_date($target);
        $n = $today === NULL ? self::parse_date(date('Y-m-d')) : self::parse_date($today);

        if ($t === NULL OR $n === NULL)
        {
            return ['error' => 'bad_date', 'message' => 'Укажите дату в формате ДД.ММ.ГГГГ (годы 1900–2100).'];
        }

        $diff = self::days_diff($n, $t);
        $r = [
            'today'     => $n->format('Y-m-d'),
            'target'    => $t->format('Y-m-d'),
            'target_human' => self::human($t),
            'weekday'   => self::weekday($t),
            'days'      => $diff,
            'abs_days'  => abs($diff),
            'past'      => $diff < 0,
            'is_today'  => $diff === 0,
            'weeks'     => intdiv(abs($diff), 7),
            'weeks_days'=> abs($diff) % 7,
        ];

        // Календарная разница «N мес. M дн.»
        [$lo, $hi] = $diff >= 0 ? [$n, $t] : [$t, $n];
        $p = self::period($lo, $hi);
        $r['years']  = $p['years'];
        $r['months'] = $p['months'];
        $r['rest_days'] = $p['days'];
        $r['period_text'] = self::period_text($p);

        if ($diff !== 0)
        {
            // Рабочие дни между сегодня и целью (не включая «сегодня», включая цель)
            $a = $diff > 0 ? $n->modify('+1 day') : $t;
            $b = $diff > 0 ? $t : $n->modify('-1 day');
            $w = self::between($a->format('Y-m-d'), $b->format('Y-m-d'), TRUE, $approx);

            if (isset($w['error']))
            {
                $r['work_error'] = $w;
            }
            else
            {
                $r['work'] = $w['work'];
                $r['hours'] = $w['hours'];
                $r['off'] = $w['off'];
                $r['approx'] = $w['approx'];
            }
        }

        return $r;
    }

    /**
     * Интервал в годах/месяцах/днях между $a и $b (a ≤ b), по ст. 192 ГК РФ.
     *
     * @return array ['years','months','days']
     */
    public static function period(DateTimeImmutable $a, DateTimeImmutable $b)
    {
        $y1 = (int) $a->format('Y'); $m1 = (int) $a->format('n'); $d1 = (int) $a->format('j');

        $years = (int) $b->format('Y') - $y1;
        if (self::_clamp($y1 + $years, $m1, $d1) > $b)
        {
            $years--;
        }
        $years = max(0, $years);

        $start = self::_clamp($y1 + $years, $m1, $d1);
        $months = 0;

        while ($months < 12 AND self::add_months_from($a, $years * 12 + $months + 1) <= $b)
        {
            $months++;
        }

        $anchor = self::add_months_from($a, $years * 12 + $months);
        $days = max(0, self::days_diff($anchor, $b));

        return ['years' => $years, 'months' => $months, 'days' => $days];
    }

    /** a + n месяцев с обрезкой от ИСХОДНОГО дня (не накапливая обрезку). */
    public static function add_months_from(DateTimeImmutable $a, $n)
    {
        return self::add_months($a, $n);
    }

    /** «1 год 2 месяца 3 дня» */
    public static function period_text(array $p)
    {
        $parts = [];

        if ($p['years'])  $parts[] = $p['years'].' '.self::plural($p['years'], 'год', 'года', 'лет');
        if ($p['months']) $parts[] = $p['months'].' '.self::plural($p['months'], 'месяц', 'месяца', 'месяцев');
        if ($p['days'] OR ! $parts) $parts[] = $p['days'].' '.self::plural($p['days'], 'день', 'дня', 'дней');

        return implode(' ', $parts);
    }

    /** Склонение числительных: 1 год, 2 года, 5 лет. */
    public static function plural($n, $one, $few, $many)
    {
        $n = abs((int) $n);
        $m10 = $n % 10; $m100 = $n % 100;

        if ($m10 === 1 AND $m100 !== 11) return $one;
        if ($m10 >= 2 AND $m10 <= 4 AND ($m100 < 12 OR $m100 > 14)) return $few;

        return $many;
    }

    /**
     * Точный возраст (или стаж, если $inclusive — считать последний день).
     *
     * @param string $birth дата рождения / начала
     * @param string $on    на какую дату (по умолчанию сегодня)
     */
    public static function age($birth, $on = NULL, $inclusive = FALSE)
    {
        $b = self::parse_date($birth);
        $n = $on === NULL ? self::parse_date(date('Y-m-d')) : self::parse_date($on);

        if ($b === NULL OR $n === NULL)
        {
            return ['error' => 'bad_date', 'message' => 'Укажите даты в формате ДД.ММ.ГГГГ (годы 1900–2100).'];
        }

        if ($n < $b)
        {
            return ['error' => 'future', 'message' => 'Дата рождения позже даты расчёта — проверьте порядок дат.'];
        }

        $end = $inclusive ? $n->modify('+1 day') : $n;
        $p = self::period($b, $end);
        $days = self::days_diff($b, $end);

        // Ближайший день рождения: сегодня или позже
        $ny = (int) $n->format('Y');
        $next = self::_clamp($ny, (int) $b->format('n'), (int) $b->format('j'));
        if ($next < $n)
        {
            $next = self::_clamp($ny + 1, (int) $b->format('n'), (int) $b->format('j'));
        }
        $is_birthday = ($next == $n AND $ny > (int) $b->format('Y'));

        // Ближайший «круглый» день жизни (кратный 1000)
        $milestone = (intdiv($days, 1000) + 1) * 1000;
        $mdate = $b->modify('+'.$milestone.' days');

        return [
            'birth'        => $b->format('Y-m-d'),
            'on'           => $n->format('Y-m-d'),
            'on_human'     => self::human($n),
            'birth_human'  => self::human($b),
            'birth_weekday'=> self::weekday($b),
            'years'        => $p['years'],
            'months'       => $p['months'],
            'rest_days'    => $p['days'],
            'text'         => self::period_text($p),
            'days_total'   => $days,
            'weeks_total'  => intdiv($days, 7),
            'months_total' => $p['years'] * 12 + $p['months'],
            'hours_total'  => $days * 24,
            'next_birthday'      => $next->format('Y-m-d'),
            'next_birthday_human'=> self::human($next),
            'next_birthday_weekday' => self::weekday($next),
            'days_to_birthday'   => self::days_diff($n, $next),
            'is_birthday'  => $is_birthday,
            'turns'        => (int) $next->format('Y') - (int) $b->format('Y'),
            'milestone'    => $milestone,
            'milestone_date'  => $mdate->format('Y-m-d'),
            'milestone_human' => self::human($mdate),
            'inclusive'    => (bool) $inclusive,
        ];
    }

    /**
     * Прибавить (или вычесть) дни к дате.
     *
     * @param int    $n     количество, может быть отрицательным
     * @param string $kind  calendar | work
     */
    public static function add($from, $n, $kind = 'calendar', $approx = FALSE)
    {
        $a = self::parse_date($from);
        $n = (int) $n;

        if ($a === NULL)
        {
            return ['error' => 'bad_date', 'message' => 'Укажите дату в формате ДД.ММ.ГГГГ (годы 1900–2100).'];
        }

        if (abs($n) > 5000)
        {
            return ['error' => 'bad_count', 'message' => 'Количество дней — не больше 5000 в любую сторону.'];
        }

        if ($kind !== 'work')
        {
            $r = $a->modify(($n >= 0 ? '+' : '').$n.' days');
            if ((int) $r->format('Y') < self::MIN_YEAR OR (int) $r->format('Y') > self::MAX_YEAR)
            {
                return ['error' => 'bad_date', 'message' => 'Результат вне диапазона 1900–2100.'];
            }

            return self::_add_result($a, $r, $n, 'calendar', FALSE);
        }

        // рабочие дни: идём по одному дню, считая только рабочие
        $years = self::years_data();
        $step = $n >= 0 ? '+1 day' : '-1 day';
        $left = abs($n);
        $d = $a;
        $used_approx = FALSE;
        $guard = 0;

        while ($left > 0)
        {
            $d = $d->modify($step);
            $y = (int) $d->format('Y');

            if ($y < self::MIN_YEAR OR $y > self::MAX_YEAR OR ++$guard > 20000)
            {
                return ['error' => 'bad_date', 'message' => 'Результат вне диапазона 1900–2100.'];
            }

            if (isset($years[$y]))
            {
                $info = self::_day($d, $years[$y]);
            }
            elseif ($approx)
            {
                $info = self::_day_approx($d);
                $used_approx = TRUE;
                self::_warn_missing([$y]);
            }
            else
            {
                return self::_no_calendar([$y]);
            }

            if ($info['type'] === 'work') $left--;
        }

        return self::_add_result($a, $d, $n, 'work', $used_approx);
    }

    protected static function _add_result(DateTimeImmutable $a, DateTimeImmutable $r, $n, $kind, $approx)
    {
        $info = self::day_info($r);

        return [
            'from'     => $a->format('Y-m-d'),
            'n'        => $n,
            'kind'     => $kind,
            'result'   => $r->format('Y-m-d'),
            'human'    => self::human($r),
            'weekday'  => self::weekday($r),
            'day_type' => $info ? $info['type'] : NULL,
            'day_name' => $info ? $info['name'] : NULL,
            'calendar_days' => abs(self::days_diff($a, $r)),
            'approx'   => (bool) $approx,
        ];
    }

    // --------------------------------------------------------- календарь года

    /**
     * Календарь года: месяцы с раскраской дней, нормы часов, праздники.
     *
     * @return array|NULL  NULL — года нет в таблице
     */
    public static function year_table($year)
    {
        $year = (int) $year;
        $years = self::years_data();

        if ( ! isset($years[$year]))
        {
            return NULL;
        }

        $data = $years[$year];
        $months = [];
        $tot = ['calendar' => 0, 'work' => 0, 'off' => 0, 'short' => 0, 'h40' => 0.0, 'h36' => 0.0, 'h24' => 0.0];

        for ($m = 1; $m <= 12; $m++)
        {
            $n = (int) cal_days_in_month(CAL_GREGORIAN, $m, $year);
            $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m), new DateTimeZone('UTC'));
            $days = [];
            $work = 0; $short = 0;

            for ($d = 1; $d <= $n; $d++)
            {
                $dt = $first->modify('+'.($d - 1).' days');
                $info = self::_day($dt, $data);
                $days[$d] = ['type' => $info['type'], 'short' => $info['short'], 'name' => $info['name'], 'wd' => (int) $dt->format('N')];
                if ($info['type'] === 'work') { $work++; if ($info['short']) $short++; }
            }

            $row = [
                'month'    => $m,
                'name'     => self::$_months[$m],
                'first_wd' => (int) $first->format('N'),
                'days'     => $days,
                'calendar' => $n,
                'work'     => $work,
                'off'      => $n - $work,
                'short'    => $short,
                'h40'      => round($work * 8 - $short, 1),
                'h36'      => round($work * 7.2 - $short, 1),
                'h24'      => round($work * 4.8 - $short, 1),
            ];

            $months[$m] = $row;
            $tot['calendar'] += $n; $tot['work'] += $work; $tot['off'] += $n - $work; $tot['short'] += $short;
            $tot['h40'] += $row['h40']; $tot['h36'] += $row['h36']; $tot['h24'] += $row['h24'];
        }

        foreach (['h40', 'h36', 'h24'] as $k) $tot[$k] = round($tot[$k], 1);

        // Список праздников и переносов (по возрастанию дат)
        $off = $data['off'];
        ksort($off);
        $list = [];
        foreach ($off as $date => $name)
        {
            $dt = new DateTimeImmutable($date, new DateTimeZone('UTC'));
            $list[] = ['date' => $date, 'human' => self::human($dt, FALSE), 'weekday' => self::weekday($dt), 'name' => $name];
        }

        $shorts = [];
        foreach ($data['short'] as $date)
        {
            $dt = new DateTimeImmutable($date, new DateTimeZone('UTC'));
            $shorts[] = self::human($dt, FALSE).' ('.self::weekday($dt).')';
        }

        return [
            'year'     => $year,
            'source'   => $data['source'] ?? '',
            'months'   => $months,
            'totals'   => $tot,
            'holidays' => $list,
            'shorts'   => $shorts,
            'works'    => $data['work'] ?? [],
        ];
    }

    /**
     * Сводка по месяцу для других модулей (например, отпускные):
     * рабочих дней, часов при 40-часовой неделе. NULL — года нет.
     */
    public static function month_summary($year, $month)
    {
        $t = self::year_table($year);
        return $t !== NULL && isset($t['months'][(int) $month]) ? $t['months'][(int) $month] : NULL;
    }
}
