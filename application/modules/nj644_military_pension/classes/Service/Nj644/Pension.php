<?php defined('SYSPATH') or die('No direct script access.');

/** Чистая расчётная логика пенсии за выслугу лет (модуль NJ-644). */
class Service_Nj644_Pension {

    /** Единственный источник нормативных параметров и ссылок на источники. */
    public static function settings(): array
    {
        return (array) Kohana::$config->load('nj644_military_pension')->as_array();
    }

    /**
     * Справочный расчёт двух оснований статьи 14 Закона РФ №4468-1.
     * Личные данные передаются только этому вызову и не сохраняются сервисом.
     *
     * @param array $input      значения формы;
     * @param array $parameters локальные переопределения параметров;
     * @return array            breakdown, eligibility, pension percentage and amount;
     */
    public static function calculate(array $input, array $parameters = []): array
    {
        $config = self::settings();
        $defaults = (array) ($config['defaults'] ?? []);
        $limits = (array) ($config['limits'] ?? []);
        $p = self::_parameters($parameters, $defaults, $limits);
        $max_years = (int) ($limits['max_years'] ?? 100);
        $max_monthly = (float) ($limits['max_monthly_rub'] ?? 100000000);
        $max_age = (int) ($limits['max_age'] ?? 120);

        $method = $input['method'] ?? NULL;
        if ( ! is_string($method) OR ! in_array($method, ['service', 'mixed'], TRUE))
        {
            throw new InvalidArgumentException('Выберите вариант расчёта.');
        }

        $date = self::_date($input['date'] ?? date('Y-m-d'), 'дату расчёта');
        if ($date < $p['coefficient_from'] OR $date > $p['coefficient_to'])
        {
            throw new InvalidArgumentException(
                'Понижающая доля '.$p['coefficient_percent'].'% по настройкам действует с '
                .$p['coefficient_from'].' по '.$p['coefficient_to'].'. Проверьте период или обновите настройки.'
            );
        }

        $position = self::_number($input['position_salary'] ?? NULL, 'оклад по должности', 0, $max_monthly);
        $rank = self::_number($input['rank_salary'] ?? NULL, 'оклад по званию', 0, $max_monthly);
        $nuclear_multiplier = self::_number($input['nuclear_multiplier'] ?? 1, 'коэффициент за работу с ядерным оружием', 1, 1.25);
        if ( ! in_array($nuclear_multiplier, [1.0, 1.25], TRUE))
        {
            throw new InvalidArgumentException('Коэффициент за работу с ядерным оружием может быть только 1 или 1,25.');
        }
        $qualification_percent = self::_number($input['qualification_percent'] ?? 0, 'надбавку за квалификационную категорию', 0, 30);
        $service_allowance_percent = self::_number($input['service_allowance_percent'] ?? 0, 'надбавку за выслугу', 0, 100);
        $other_allowance = self::_number($input['other_allowance'] ?? 0, 'прочие учитываемые надбавки', 0, $max_monthly);

        $position_adjusted = self::_money($position * $nuclear_multiplier);
        $ods = self::_money($position_adjusted + $rank);
        $qualification_allowance = self::_money($position_adjusted * $qualification_percent / 100);
        $service_allowance = self::_money($ods * $service_allowance_percent / 100);
        $base = self::_money($ods + $qualification_allowance + $service_allowance + $other_allowance);
        if ($base <= 0)
        {
            throw new InvalidArgumentException('Сумма окладов и учитываемых надбавок должна быть больше нуля.');
        }

        $reasons = [];
        if ($method === 'service')
        {
            $years = self::_integer($input['service_years'] ?? NULL, 'полные годы выслуги', 0, $max_years);
            $months = self::_integer($input['service_months'] ?? 0, 'месяцы выслуги', 0, 11);
            $total_months = $years * 12 + $months;
            $minimum_months = $p['service_min_years'] * 12;
            if ($total_months < $minimum_months)
            {
                $reasons[] = 'Для варианта по выслуге нужно не менее '.$p['service_min_years'].' лет службы.';
            }
            if ($reasons)
            {
                return self::_ineligible($method, $base, $p, $date, $reasons);
            }
            $full_years = intdiv($total_months, 12);
            $extra_years = max(0, $full_years - $p['service_min_years']);
            $percent = min($p['service_max_percent'],
                $p['service_base_percent'] + $extra_years * $p['service_percent_per_year']);
        }
        else
        {
            $total_years = self::_integer($input['total_years'] ?? NULL, 'полные годы общего стажа', 0, $max_years);
            $total_remainder = self::_integer($input['total_months'] ?? 0, 'месяцы общего стажа', 0, 11);
            $service_years = self::_integer($input['service_years'] ?? NULL, 'полные годы службы', 0, $max_years);
            $service_remainder = self::_integer($input['service_months'] ?? 0, 'месяцы службы', 0, 11);
            $age = self::_integer($input['age'] ?? NULL, 'возраст на день увольнения', 0, $max_age);
            $total_months = $total_years * 12 + $total_remainder;
            $service_months = $service_years * 12 + $service_remainder;
            if ($service_months > $total_months)
            {
                throw new InvalidArgumentException('Служба не может быть дольше общего трудового стажа.');
            }
            if ($total_months < $p['mixed_min_total_years'] * 12)
            {
                $reasons[] = 'Нужно не менее '.$p['mixed_min_total_years'].' календарных лет общего трудового стажа.';
            }
            if ($service_months < $p['mixed_min_service_months'])
            {
                $reasons[] = 'В составе общего стажа нужно не менее '.self::_months_label($p['mixed_min_service_months']).' соответствующей службы.';
            }
            if ($age < $p['mixed_min_age'])
            {
                $reasons[] = 'На день увольнения должно быть не менее '.$p['mixed_min_age'].' лет.';
            }
            $reason = $input['dismissal_reason'] ?? '';
            if ( ! is_string($reason) OR ! in_array($reason, ['age_limit', 'health', 'staffing'], TRUE))
            {
                $reasons[] = 'Для смешанного варианта укажите предусмотренное законом основание увольнения.';
            }
            if ($reasons)
            {
                return self::_ineligible($method, $base, $p, $date, $reasons);
            }
            $full_years = intdiv($total_months, 12);
            $extra_years = max(0, $full_years - $p['mixed_min_total_years']);
            // В подпункте «б» статьи 14 отдельный предел 85% не установлен.
            $percent = $p['mixed_base_percent'] + $extra_years * $p['mixed_percent_per_year'];
        }

        $before_coefficient = self::_money($base * $percent / 100);
        $main_amount = self::_money($before_coefficient * $p['coefficient_percent'] / 100);

        $combat_veteran = self::_flag($input['combat_veteran'] ?? FALSE, 'статус ветерана боевых действий');
        $dependents = self::_integer($input['dependents'] ?? 0, 'число иждивенцев', 0, 3);
        $age80_or_group1 = self::_flag($input['age80_or_group1'] ?? FALSE, 'признак 80 лет или инвалидности I группы');
        $disability_percent = self::_number($input['disability_percent'] ?? 0, 'увеличение по инвалидности', 0, 300);
        if ( ! in_array($disability_percent, [0.0, 150.0, 175.0, 200.0, 250.0, 300.0], TRUE))
        {
            throw new InvalidArgumentException('Выберите одно из предусмотренных увеличений по инвалидности.');
        }
        $special_monthly = self::_number($input['special_monthly'] ?? 0, 'дополнительное ежемесячное обеспечение', 0, $max_monthly);
        $district_coefficient = self::_number($input['district_coefficient'] ?? 1, 'районный коэффициент', 1, 2);

        $additions = [
            'combat_veteran' => $combat_veteran ? self::_money($p['rpp_rub'] * .32) : 0.0,
            'dependents' => self::_money($p['rpp_rub'] * ($dependents === 3 ? 1 : $dependents * .32)),
            'age80_or_group1' => $age80_or_group1 ? $p['rpp_rub'] : 0.0,
            'disability' => self::_money($p['rpp_rub'] * $disability_percent / 100),
            'special_monthly' => $special_monthly,
        ];
        $additions_total = self::_money(array_sum($additions));
        $before_district = self::_money($main_amount + $additions_total);
        $amount = self::_money($before_district * $district_coefficient);

        return [
            'eligible' => TRUE,
            'method' => $method,
            'date' => $date,
            'base' => $base,
            'base_components' => [
                'position_salary' => $position,
                'position_adjusted' => $position_adjusted,
                'rank_salary' => $rank,
                'nuclear_multiplier' => $nuclear_multiplier,
                'qualification_allowance' => $qualification_allowance,
                'service_allowance_percent' => $service_allowance_percent,
                'service_allowance' => $service_allowance,
                'other_allowance' => $other_allowance,
            ],
            'full_years' => $full_years,
            'extra_years' => $extra_years,
            'pension_percent' => $percent,
            'before_coefficient' => $before_coefficient,
            'coefficient_percent' => $p['coefficient_percent'],
            'main_amount' => $main_amount,
            'rpp_rub' => $p['rpp_rub'],
            'additions' => $additions,
            'additions_total' => $additions_total,
            'district_coefficient' => $district_coefficient,
            'before_district' => $before_district,
            'amount' => $amount,
            'reasons' => [],
        ];
    }

    /** Параметры после локального переопределения; неизвестные поля отбрасываются. */
    protected static function _parameters(array $input, array $defaults, array $limits): array
    {
        $p = array_intersect_key($input, $defaults);
        $p = array_replace($defaults, $p);
        $max_years = (int) ($limits['max_years'] ?? 100);
        $max_monthly = (float) ($limits['max_monthly_rub'] ?? 100000000);

        $p['coefficient_percent'] = self::_number($p['coefficient_percent'] ?? NULL, 'понижающую долю', 0, 100);
        $p['coefficient_from'] = self::_date($p['coefficient_from'] ?? NULL, 'дату начала действия доли');
        $p['coefficient_to'] = self::_date($p['coefficient_to'] ?? NULL, 'дату окончания действия доли');
        $p['rpp_rub'] = self::_number($p['rpp_rub'] ?? NULL, 'расчётный размер пенсии', 0, $max_monthly);
        if ($p['coefficient_from'] > $p['coefficient_to'])
        {
            throw new InvalidArgumentException('Дата окончания действия доли не может быть раньше даты начала.');
        }

        $p['service_min_years'] = self::_integer($p['service_min_years'] ?? NULL, 'порог выслуги', 1, $max_years);
        $p['service_base_percent'] = self::_number($p['service_base_percent'] ?? NULL, 'базовый процент выслуги', 0, 100);
        $p['service_percent_per_year'] = self::_number($p['service_percent_per_year'] ?? NULL, 'процент за год выслуги', 0, 100);
        $p['service_max_percent'] = self::_number($p['service_max_percent'] ?? NULL, 'максимальный процент выслуги', 0, 100);
        if ($p['service_max_percent'] < $p['service_base_percent'])
        {
            throw new InvalidArgumentException('Предел обычного варианта не может быть меньше базового процента.');
        }

        $p['mixed_min_total_years'] = self::_integer($p['mixed_min_total_years'] ?? NULL, 'минимум общего стажа', 0, $max_years);
        $p['mixed_min_service_months'] = self::_integer($p['mixed_min_service_months'] ?? NULL, 'минимум службы в месяцах', 0, $max_years * 12);
        $p['mixed_min_age'] = self::_integer($p['mixed_min_age'] ?? NULL, 'минимальный возраст', 0, (int) ($limits['max_age'] ?? 120));
        $p['mixed_base_percent'] = self::_number($p['mixed_base_percent'] ?? NULL, 'базовый процент смешанного варианта', 0, 100);
        $p['mixed_percent_per_year'] = self::_number($p['mixed_percent_per_year'] ?? NULL, 'процент за год смешанного стажа', 0, 100);

        return $p;
    }

    protected static function _ineligible(string $method, float $base, array $parameters, string $date, array $reasons): array
    {
        return [
            'eligible' => FALSE,
            'method' => $method,
            'date' => $date,
            'base' => $base,
            'coefficient_percent' => $parameters['coefficient_percent'],
            'amount' => NULL,
            'reasons' => $reasons,
        ];
    }

    protected static function _flag($raw, string $label): bool
    {
        if (is_bool($raw)) return $raw;
        if (is_int($raw) AND ($raw === 0 OR $raw === 1)) return (bool) $raw;
        if (is_string($raw))
        {
            $value = mb_strtolower(trim($raw));
            if (in_array($value, ['0', 'false', 'no', 'нет', ''], TRUE)) return FALSE;
            if (in_array($value, ['1', 'true', 'yes', 'да'], TRUE)) return TRUE;
        }
        throw new InvalidArgumentException('Проверьте поле «'.$label.'».');
    }

    protected static function _money(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }

    protected static function _number($raw, string $label, float $min, float $max): float
    {
        if (is_int($raw) OR is_float($raw))
        {
            $number = (float) $raw;
        }
        elseif (is_string($raw))
        {
            $value = trim(str_replace(',', '.', str_replace(' ', '', $raw)));
            if ($value === '' OR ! preg_match('/^\d+(?:\.\d+)?$/', $value))
            {
                throw new InvalidArgumentException('Проверьте значение: '.$label.'.');
            }
            $number = (float) $value;
        }
        else
        {
            throw new InvalidArgumentException('Проверьте значение: '.$label.'.');
        }
        if ( ! is_finite($number) OR $number < $min OR $number > $max)
        {
            throw new InvalidArgumentException('Значение поля «'.$label.'» должно быть от '.$min.' до '.$max.'.');
        }
        return $number;
    }

    protected static function _integer($raw, string $label, int $min, int $max): int
    {
        $number = self::_number($raw, $label, $min, $max);
        if (floor($number) !== $number)
        {
            throw new InvalidArgumentException('Поле «'.$label.'» должно быть целым числом.');
        }
        return (int) $number;
    }

    protected static function _date($raw, string $label): string
    {
        if ( ! is_string($raw) OR ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw))
        {
            throw new InvalidArgumentException('Проверьте значение: '.$label.'.');
        }
        [$year, $month, $day] = array_map('intval', explode('-', $raw));
        if ( ! checkdate($month, $day, $year))
        {
            throw new InvalidArgumentException('Проверьте значение: '.$label.'.');
        }
        return $raw;
    }

    protected static function _months_label(int $months): string
    {
        $years = intdiv($months, 12);
        $remainder = $months % 12;
        if ($remainder === 0) return $years.' лет';
        return $years.' лет '.$remainder.' мес.';
    }
}
