<?php

use PHPUnit\Framework\TestCase;

/** Контракт ориентировочной формулы военной пенсии (NJ-644). */
class MilitaryPensionServiceTest extends TestCase {

    private function service_input(array $override = []): array
    {
        return array_replace([
            'method' => 'service', 'date' => '2026-10-06',
            'position_salary' => 50000, 'rank_salary' => 20000,
            'nuclear_multiplier' => 1, 'qualification_percent' => 0,
            'service_allowance_percent' => 30, 'other_allowance' => 0,
            'service_years' => 25, 'service_months' => 0,
            'combat_veteran' => FALSE, 'dependents' => 0, 'age80_or_group1' => FALSE,
            'disability_percent' => 0, 'special_monthly' => 0, 'district_coefficient' => 1,
        ], $override);
    }

    private function mixed_input(array $override = []): array
    {
        return array_replace($this->service_input([
            'method' => 'mixed', 'total_years' => 25, 'total_months' => 0,
            'service_years' => 12, 'service_months' => 6, 'age' => 45,
            'dismissal_reason' => 'health',
        ]), $override);
    }

    public function test_official_default_is_current_and_proposal_is_explicitly_separate(): void
    {
        $settings = Service_Nj644_Pension::settings();
        $this->assertSame('2026-10-06', $settings['reviewed_on']);
        $this->assertSame(93.59, $settings['defaults']['coefficient_percent']);
        $this->assertSame(9424.12, $settings['defaults']['rpp_rub']);
        $this->assertSame('official', $settings['periods']['2026-current']['status']);
        $this->assertSame(97.34, $settings['periods']['2026-proposal']['coefficient_percent']);
        $this->assertSame('proposal', $settings['periods']['2026-proposal']['status']);
        $this->assertSame(5, count($settings['sources']));
    }

    public function test_service_formula_produces_detailed_base_and_current_amount(): void
    {
        $result = Service_Nj644_Pension::calculate($this->service_input());
        $this->assertTrue($result['eligible']);
        $this->assertSame(91000.0, $result['base']);
        $this->assertSame(21000.0, $result['base_components']['service_allowance']);
        $this->assertSame(5, $result['extra_years']);
        $this->assertSame(65.0, $result['pension_percent']);
        $this->assertSame(59150.0, $result['before_coefficient']);
        $this->assertSame(55358.49, $result['main_amount']);
        $this->assertSame(55358.49, $result['amount']);
    }

    public function test_extended_allowances_and_district_coefficient_are_in_breakdown(): void
    {
        $result = Service_Nj644_Pension::calculate($this->service_input([
            'nuclear_multiplier' => 1.25,
            'qualification_percent' => 10,
            'service_allowance_percent' => 15,
            'other_allowance' => 500,
            'combat_veteran' => TRUE,
            'dependents' => 2,
            'age80_or_group1' => TRUE,
            'disability_percent' => 150,
            'special_monthly' => 1000,
            'district_coefficient' => 1.2,
        ]));
        $this->assertSame(62500.0, $result['base_components']['position_adjusted']);
        $this->assertSame(6250.0, $result['base_components']['qualification_allowance']);
        $this->assertSame(12375.0, $result['base_components']['service_allowance']);
        $this->assertSame(101625.0, $result['base']);
        $this->assertSame(3015.72, $result['additions']['combat_veteran']);
        $this->assertSame(6031.44, $result['additions']['dependents']);
        $this->assertSame(9424.12, $result['additions']['age80_or_group1']);
        $this->assertSame(14136.18, $result['additions']['disability']);
        $this->assertSame(33607.46, $result['additions_total']);
        $this->assertSame(95429.5, $result['before_district']);
        $this->assertSame(114515.4, $result['amount']);
    }

    public function test_service_threshold_cap_and_mixed_statutory_conditions(): void
    {
        $below = Service_Nj644_Pension::calculate($this->service_input(['service_years' => 19, 'service_months' => 11]));
        $this->assertFalse($below['eligible']);
        $this->assertStringContainsString('20 лет', $below['reasons'][0]);
        $this->assertSame(50.0, Service_Nj644_Pension::calculate($this->service_input(['service_years' => 20, 'service_months' => 11]))['pension_percent']);
        $this->assertSame(85.0, Service_Nj644_Pension::calculate($this->service_input(['service_years' => 40]))['pension_percent']);

        $minimum = Service_Nj644_Pension::calculate($this->mixed_input());
        $this->assertTrue($minimum['eligible']);
        $this->assertSame(50.0, $minimum['pension_percent']);
        $this->assertSame(42583.45, $minimum['amount']);
        $this->assertSame(95.0, Service_Nj644_Pension::calculate($this->mixed_input([
            'total_years' => 70, 'service_years' => 30, 'service_months' => 0,
        ]))['pension_percent']);
        foreach ([
            $this->mixed_input(['total_years' => 24, 'total_months' => 11]),
            $this->mixed_input(['service_years' => 12, 'service_months' => 5]),
            $this->mixed_input(['age' => 44]), $this->mixed_input(['dismissal_reason' => 'other']),
        ] as $input) $this->assertFalse(Service_Nj644_Pension::calculate($input)['eligible']);
    }

    public function test_proposal_can_be_modelled_only_when_explicitly_passed(): void
    {
        $parameters = [
            'coefficient_percent' => 97.34,
            'coefficient_from' => '2026-10-01',
            'coefficient_to' => '2026-12-31',
            'rpp_rub' => 10000,
        ];
        $result = Service_Nj644_Pension::calculate($this->service_input(), $parameters);
        $this->assertSame(97.34, $result['coefficient_percent']);
        $this->assertSame(57576.61, $result['amount']);
        $this->assertThrowsInvalidArgument(function () use ($parameters) {
            Service_Nj644_Pension::calculate($this->service_input(['date' => '2026-09-30']), $parameters);
        });
    }

    public function test_bad_values_are_rejected(): void
    {
        foreach ([
            $this->service_input(['position_salary' => -1]),
            $this->service_input(['service_months' => 12]),
            $this->service_input(['nuclear_multiplier' => 1.1]),
            $this->service_input(['disability_percent' => 100]),
            $this->service_input(['district_coefficient' => 2.1]),
            $this->service_input(['position_salary' => '1e2']),
            $this->mixed_input(['service_years' => 26]),
        ] as $input) $this->assertThrowsInvalidArgument(function () use ($input) {
            Service_Nj644_Pension::calculate($input);
        });
    }

    private function assertThrowsInvalidArgument(callable $callback): void
    {
        try { $callback(); }
        catch (InvalidArgumentException $error) { $this->assertNotEmpty($error->getMessage()); return; }
        self::fail('Ожидалось InvalidArgumentException.');
    }
}
