<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Зарплата: «на руки» ⇄ «гросс», НДФЛ и взносы работодателя (модуль NJ-503).
 *
 * Чистые функции, БД не нужна. Деньги — в КОПЕЙКАХ (целые), как в nj501_vat.
 * Ставки и вычеты — таблицей по годам, config/nj503_salary.php.
 *
 * Что считает project():
 *  - годовую проекцию при постоянной зарплате и НДФЛ нарастающим итогом
 *    (ст. 226 НК: налог с начала года по прогрессивной шкале минус уже
 *    удержанное) — поэтому в месяце, где доход пересекает порог шкалы,
 *    «на руки» заметно падает; простой калькулятор «×0,87» этого не видит;
 *  - НДФЛ в целых рублях (ст. 52 НК: 50 копеек и больше — вверх);
 *  - стандартный вычет на детей, пока доход с начала года ≤ лимита
 *    (в месяце превышения вычет уже не даётся);
 *  - страховые взносы работодателя: 30 % до предельной базы, 15,1 % сверх,
 *    плюс «травматизм»; опция МСП: льготная часть выше порога;
 *  - полную стоимость сотрудника = зарплата + взносы.
 *
 * Обратная задача net → gross: ищем зарплату, при которой «на руки» в
 * ПЕРВЫЙ месяц равна заданной (в первые месяцы действует нижняя ставка);
 * дальше годовая проекция показывает, как изменятся следующие месяцы.
 * Округление налога до рубля делает net(gross) не строго монотонной
 * (±1 ₽), поэтому ищем бинарным поиском окрестность и точным сканом
 * находим минимальную зарплату с нужным результатом.
 */
class Service_Nj503_Salary {

    const MODE_GROSS = 'gross'; // дано «до вычета налога», ищем «на руки»
    const MODE_NET   = 'net';   // дано «на руки», ищем «до вычета»

    /** Предел месячной суммы, ₽ (защита от абсурда) */
    const MAX_MONTHLY = 100000000;

    protected static $_months = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];

    // ----------------------------------------------------------- параметры

    /** @return array год => параметры */
    public static function all_params()
    {
        $cfg = Kohana::$config->load('nj503_salary')->as_array();
        return isset($cfg['years']) && is_array($cfg['years']) ? $cfg['years'] : [];
    }

    public static function years()
    {
        $y = array_keys(self::all_params());
        sort($y);
        return $y;
    }

    public static function default_year()
    {
        $cfg = Kohana::$config->load('nj503_salary')->as_array();
        $d = (int) ($cfg['default_year'] ?? 0);
        return isset(self::all_params()[$d]) ? $d : (int) max(self::years());
    }

    /** Параметры года или NULL. */
    public static function params($year)
    {
        $all = self::all_params();
        return isset($all[(int) $year]) ? $all[(int) $year] : NULL;
    }

    /**
     * Нормализация опций расчёта.
     *
     * @return array|NULL NULL — год неизвестен
     */
    public static function options(array $o)
    {
        $year = (int) ($o['year'] ?? self::default_year());
        $p = self::params($year);

        if ($p === NULL)
        {
            return NULL;
        }

        $children = max(0, min(10, (int) ($o['children'] ?? 0)));
        $disabled = max(0, min($children, (int) ($o['disabled'] ?? 0)));
        $injury = (float) str_replace(',', '.', (string) ($o['injury'] ?? $p['injury_default']));
        $injury = max(0.0, min(8.5, $injury));

        return [
            'year'     => $year,
            'p'        => $p,
            'resident' => ! isset($o['resident']) OR self::_bool($o['resident']),
            'children' => $children,
            'disabled' => $disabled,
            'single'   => isset($o['single']) AND self::_bool($o['single']),
            'sme'      => isset($o['sme']) AND self::_bool($o['sme']),
            'injury'   => round($injury, 1),
        ];
    }

    protected static function _bool($v)
    {
        return $v === TRUE OR in_array((string) $v, ['1', 'true', 'on', 'yes'], TRUE);
    }

    /** Вычет на детей, копеек в месяц (на одного родителя). */
    public static function child_deduction_kop(array $opt)
    {
        if ( ! $opt['resident'] OR $opt['children'] === 0)
        {
            return 0;
        }

        $p = $opt['p'];
        $sum = 0;

        for ($i = 1; $i <= $opt['children']; $i++)
        {
            $sum += (int) $p['child'][min($i, count($p['child'])) - 1];
        }

        $sum += $opt['disabled'] * (int) $p['child_disabled'];

        if ($opt['single'])
        {
            $sum *= 2;
        }

        return $sum * 100;
    }

    // ---------------------------------------------------------------- НДФЛ

    /**
     * НДФЛ с налоговой базы нарастающим итогом, копеек (кратно 100: целые рубли).
     */
    public static function ndfl_on($base_kop, array $opt)
    {
        $base_kop = max(0, (int) $base_kop);

        if ( ! $opt['resident'])
        {
            return self::_round_rub($base_kop * (int) round($opt['p']['ndfl_nonresident'] * 100));
        }

        $scale = $opt['p']['ndfl_scale'];
        $acc = 0; // копейки × (ставка в сотых процента)

        foreach ($scale as $i => $band)
        {
            $from = (int) $band[0] * 100;
            $to = isset($scale[$i + 1]) ? (int) $scale[$i + 1][0] * 100 : PHP_INT_MAX;

            if ($base_kop <= $from) break;

            $acc += (min($base_kop, $to) - $from) * (int) round($band[1] * 100);
        }

        return self::_round_rub($acc);
    }

    /** acc = копейки × (процент × 100); результат — копейки, кратные 100, округление до рубля half-up. */
    protected static function _round_rub($acc)
    {
        // копейки = acc / 10000; в рубли с округлением 0,5 вверх: (acc/10000 + 50) / 100
        return intdiv($acc + 500000, 1000000) * 100;
    }

    /** Предельная ставка НДФЛ на базе (для подсказок), %. */
    public static function marginal_rate($base_kop, array $opt)
    {
        if ( ! $opt['resident']) return (float) $opt['p']['ndfl_nonresident'];

        $rate = (float) $opt['p']['ndfl_scale'][0][1];
        foreach ($opt['p']['ndfl_scale'] as $band)
        {
            if ($base_kop > (int) $band[0] * 100) $rate = (float) $band[1];
        }

        return $rate;
    }

    // ------------------------------------------------------------- проекция

    /**
     * Годовая проекция при постоянной зарплате.
     *
     * @param int   $gross_kop зарплата до вычета НДФЛ, копеек в месяц
     * @param array $opt       options()
     * @param int   $months    сколько месяцев считать (1–12)
     * @return array ['months' => [...], 'total' => [...]]
     */
    public static function project($gross_kop, array $opt, $months = 12)
    {
        $p = $opt['p'];
        $limit = (int) $p['contrib_limit'] * 100;
        $child_limit = (int) $p['child_limit'] * 100;
        $ded_month = self::child_deduction_kop($opt);

        $pm_main = (int) round($p['contrib_rate'] * 10);
        $pm_over = (int) round($p['contrib_over'] * 10);
        $pm_sme  = (int) round($p['sme_rate'] * 10);
        $pm_inj  = (int) round($opt['injury'] * 10);
        $sme_thr = (int) round($p['sme_threshold'] * $p['mrot'] * 100);

        $cum_gross = 0; $cum_ded = 0; $withheld = 0; $cum_base = 0;
        $rows = [];
        $tot = ['gross' => 0, 'ndfl' => 0, 'net' => 0, 'contrib' => 0, 'injury' => 0, 'cost' => 0, 'deduction' => 0];

        for ($k = 1; $k <= $months; $k++)
        {
            $cum_gross += $gross_kop;

            // Вычет — пока доход нарастающим итогом (включая этот месяц) не превысил лимит
            $ded = ($ded_month > 0 AND $cum_gross <= $child_limit) ? $ded_month : 0;
            $cum_ded += $ded;

            $taxable = max(0, $cum_gross - $cum_ded);
            $tax_cum = self::ndfl_on($taxable, $opt);
            $ndfl = max(0, $tax_cum - $withheld);
            $withheld += $ndfl;

            // Взносы
            $part1 = $opt['sme'] ? min($gross_kop, $sme_thr) : $gross_kop;
            $part2 = $gross_kop - $part1;
            $under = max(0, min($part1, $limit - $cum_base));
            $over  = $part1 - $under;
            $contrib = intdiv($under * $pm_main + $over * $pm_over + $part2 * $pm_sme + 500, 1000);
            $cum_base += $gross_kop;

            $injury = intdiv($gross_kop * $pm_inj + 500, 1000);
            $net = $gross_kop - $ndfl;
            $cost = $gross_kop + $contrib + $injury;

            $rows[] = [
                'm'         => $k,
                'name'      => self::$_months[$k - 1],
                'gross'     => $gross_kop,
                'deduction' => $ded,
                'ndfl'      => $ndfl,
                'net'       => $net,
                'contrib'   => $contrib,
                'injury'    => $injury,
                'cost'      => $cost,
                'rate'      => self::marginal_rate($taxable, $opt),
            ];

            $tot['gross']   += $gross_kop;
            $tot['ndfl']    += $ndfl;
            $tot['net']     += $net;
            $tot['contrib'] += $contrib;
            $tot['injury']  += $injury;
            $tot['cost']    += $cost;
            $tot['deduction'] += $ded;
        }

        return ['months' => $rows, 'total' => $tot];
    }

    /** «На руки» за первый месяц при зарплате $gross_kop. */
    public static function net_first_month($gross_kop, array $opt)
    {
        $r = self::project($gross_kop, $opt, 1);
        return $r['months'][0]['net'];
    }

    /**
     * Минимальная зарплата («до налога»), при которой «на руки» в первый месяц
     * равна $net_kop (если такой точной суммы нет из-за округления налога —
     * ближайшая сверху).
     *
     * @return int копеек
     */
    public static function gross_for_net($net_kop, array $opt)
    {
        $net_kop = max(0, (int) $net_kop);

        // 1) бинарный поиск окрестности по «почти монотонной» функции
        $lo = $net_kop;
        $hi = $net_kop * 5 + 1000000;

        while ($lo < $hi)
        {
            $mid = intdiv($lo + $hi, 2);

            if (self::net_first_month($mid, $opt) >= $net_kop) $hi = $mid;
            else $lo = $mid + 1;
        }

        // 2) точный скан ±1,5 ₽ (округление НДФЛ до рубля — ступеньки до ±100 коп.)
        $from = max($net_kop, $lo - 150);
        $best = NULL;

        for ($g = $from; $g <= $lo + 150; $g++)
        {
            $n = self::net_first_month($g, $opt);

            if ($n === $net_kop)
            {
                return $g;
            }

            if ($n > $net_kop AND ($best === NULL OR $n < $best[1]))
            {
                $best = [$g, $n];
            }
        }

        return $best !== NULL ? $best[0] : $lo;
    }

    // ------------------------------------------------------------- фасад

    /**
     * Полный расчёт для страницы и API.
     *
     * @param  mixed  $amount сумма в рублях (строка/число)
     * @param  string $mode   gross | net
     * @param  array  $o      year, resident, children, disabled, single, sme, injury
     * @return array|NULL     NULL — данные некорректны
     */
    public static function calculate($amount, $mode, array $o = [])
    {
        if ($mode !== self::MODE_GROSS AND $mode !== self::MODE_NET)
        {
            return NULL;
        }

        $kop = Service_Nj501_Vat::amount_to_kop($amount);
        $opt = self::options($o);

        if ($kop === NULL OR $kop <= 0 OR $kop > self::MAX_MONTHLY * 100 OR $opt === NULL)
        {
            return NULL;
        }

        $gross = $mode === self::MODE_GROSS ? $kop : self::gross_for_net($kop, $opt);
        $proj = self::project($gross, $opt, 12);
        $first = $proj['months'][0];
        $tot = $proj['total'];

        // Месяцы, где ставка НДФЛ растёт
        $steps = [];
        $prev = NULL;
        foreach ($proj['months'] as $m)
        {
            if ($prev !== NULL AND $m['rate'] > $prev) $steps[] = ['m' => $m['m'], 'name' => $m['name'], 'rate' => $m['rate']];
            $prev = $m['rate'];
        }

        $warnings = [];
        $p = $opt['p'];

        if ( ! $opt['resident'])
        {
            $warnings[] = 'Для нерезидента применяется ставка '.$p['ndfl_nonresident'].' %, стандартные вычеты не предоставляются.';
        }
        if ($gross * 12 > (int) $p['contrib_limit'] * 100 AND $opt['resident'] AND $first['gross'] * 12 > $p['contrib_limit'] * 100)
        {
            $warnings[] = 'С какого-то месяца доход превысит предельную базу взносов ('.Service_Nj501_Vat::format_kop($p['contrib_limit'] * 100).') — тариф взносов снизится до '.$p['contrib_over'].' %.';
        }
        if ($opt['sme'])
        {
            $warnings[] = 'Тариф МСП применяется только организациям из реестра МСП, подходящим по условиям (с 2026 года — по перечню видов деятельности); проверьте право на льготу.';
        }

        $cost_year = max(1, $tot['cost']);

        return [
            'year'     => $opt['year'],
            'mode'     => $mode,
            'input_kop'=> $kop,
            'resident' => $opt['resident'],
            'options'  => [
                'children' => $opt['children'], 'disabled' => $opt['disabled'], 'single' => $opt['single'],
                'sme' => $opt['sme'], 'injury' => $opt['injury'],
            ],
            // первый месяц — «главные» цифры
            'gross_kop'     => $gross,
            'ndfl_kop'      => $first['ndfl'],
            'net_kop'       => $first['net'],
            'contrib_kop'   => $first['contrib'],
            'injury_kop'    => $first['injury'],
            'cost_kop'      => $first['cost'],
            'deduction_kop' => self::child_deduction_kop($opt),
            'ndfl_rate'     => $first['rate'],
            'exact'         => $mode === self::MODE_GROSS OR $first['net'] === $kop,
            'months'        => $proj['months'],
            'year_total'    => $tot,
            'net_avg_kop'   => intdiv($tot['net'], 12),
            'rate_steps'    => $steps,
            'share_net'     => round($tot['net'] / $cost_year * 100, 1),
            'share_ndfl'    => round($tot['ndfl'] / $cost_year * 100, 1),
            'share_contrib' => round(($tot['contrib'] + $tot['injury']) / $cost_year * 100, 1),
            'ndfl_effective'=> $tot['gross'] > 0 ? round($tot['ndfl'] / $tot['gross'] * 100, 2) : 0,
            'source'        => $p['source'],
            'warnings'      => $warnings,
            'text'          => [
                'gross' => Service_Nj501_Vat::format_kop($gross),
                'net'   => Service_Nj501_Vat::format_kop($first['net']),
                'cost'  => Service_Nj501_Vat::format_kop($first['cost']),
            ],
        ];
    }
}
