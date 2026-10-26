<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj505_Penalty (nj505_penalty).
 *
 * Запуск: php tests/mini_runner.php PenaltyService
 * Эталоны посчитаны вручную: сумма × ставка × дни / знаменатель.
 */
class PenaltyServiceTest extends TestCase {

    const TODAY = '2026-10-01';

    protected static function _c($mode, $amount, $due, $paid, array $o = [])
    {
        return Service_Nj505_Penalty::calculate($mode, $amount, $due, $paid, $o, self::TODAY);
    }

    public function test_key_rate_lookup()
    {
        $this->assertSame(18000, Service_Nj505_Penalty::rate_on('2024-08-01'));
        $this->assertSame(18000, Service_Nj505_Penalty::rate_on('2024-09-15'));
        $this->assertSame(19000, Service_Nj505_Penalty::rate_on('2024-09-16'));
        $this->assertSame(14000, Service_Nj505_Penalty::rate_on('2026-09-30'));
        $this->assertNull(Service_Nj505_Penalty::rate_on('2015-12-31'));
        $this->assertSame(20000, Service_Nj505_Penalty::rate_on('2022-03-01'));
        $this->assertSame(7500, Service_Nj505_Penalty::rate_on('2023-01-01'));
    }

    public function test_key_rate_table_is_sorted_and_sane()
    {
        $prev = '';
        foreach (Service_Nj505_Penalty::rates() as $d => $r)
        {
            $this->assertTrue($d > $prev, "даты по возрастанию: $d");
            $this->assertTrue($r > 0 AND $r <= 25000, "ставка $d правдоподобна");
            $prev = $d;
        }
        $this->assertSame(14.0, Service_Nj505_Penalty::last_rate()['rate']);
    }

    public function test_tax_org_up_to_30_days_is_one_300th()
    {
        // 100 000 ₽ × 18 % / 300 × 30 дней = 1 800 ₽
        $r = self::_c('tax', '100000', '2024-08-01', '2024-08-31', ['payer' => 'org']);

        $this->assertSame(30, $r['days']);
        $this->assertSame(180000, $r['total_kop']);
        $this->assertCount(1, $r['rows']);
        $this->assertSame('1/300', $r['rows'][0]['den_text']);
    }

    public function test_tax_org_from_31st_day_is_one_150th()
    {
        // 40 дней: 30 × 60 ₽ + 10 × 120 ₽ = 3 000 ₽ (ставка 18 % до 15.09.2024)
        $r = self::_c('tax', '100000', '2024-08-01', '2024-09-10', ['payer' => 'org']);

        $this->assertSame(40, $r['days']);
        $this->assertSame(300000, $r['total_kop']);
        $this->assertCount(2, $r['rows']);
        $this->assertSame(30, $r['rows'][0]['days']);
        $this->assertSame('1/150', $r['rows'][1]['den_text']);
    }

    public function test_tax_person_is_always_300th()
    {
        $r = self::_c('tax', '100000', '2024-08-01', '2024-09-10', ['payer' => 'person']);
        $this->assertSame(240000, $r['total_kop']); // 40 × 60 ₽
        $this->assertCount(1, $r['rows']);
    }

    public function test_tax_rate_change_splits_rows()
    {
        // 11–15 сентября (5 дней по 18 %): 5 × 60 = 300 ₽; 16–20 (5 дней по 19 %): 5 × 63,3333 = 316,67 ₽
        $r = self::_c('tax', '100000', '2024-09-10', '2024-09-20', ['payer' => 'person']);

        $this->assertCount(2, $r['rows']);
        $this->assertSame(30000, $r['rows'][0]['kop']);
        $this->assertSame(31667, $r['rows'][1]['kop']);
        $this->assertSame(61667, $r['total_kop']);
        $this->assertSame('2024-09-16', $r['rows'][1]['from']);
    }

    public function test_incl_flag_excludes_payment_day()
    {
        $with = self::_c('tax', '100000', '2024-08-01', '2024-08-31', ['payer' => 'person']);
        $without = self::_c('tax', '100000', '2024-08-01', '2024-08-31', ['payer' => 'person', 'incl' => 0]);

        $this->assertSame(30, $with['days']);
        $this->assertSame(29, $without['days']);

        // флаги из формы приходят строками: '1' — включено, '0' — выключено
        $this->assertSame(30, self::_c('tax', '100000', '2024-08-01', '2024-08-31', ['incl' => '1'])['days']);
        $this->assertSame(29, self::_c('tax', '100000', '2024-08-01', '2024-08-31', ['incl' => '0'])['days']);
        $this->assertSame(184, self::_c('gk395', '100000', '2022-03-15', '2022-12-15', ['moratorium' => '1'])['excluded']);
        $this->assertSame(0, self::_c('gk395', '100000', '2022-03-15', '2022-12-15', ['moratorium' => '0'])['excluded']);
        $this->assertSame(174000, $without['total_kop']);
    }

    public function test_gk395_leap_year_divisor()
    {
        // 2024 високосный: 100 000 × 18 % × 30 / 366 = 1 475,4098 → 1 475,41
        $r = self::_c('gk395', '100000', '2024-08-01', '2024-08-31', ['moratorium' => 0]);
        $this->assertSame(147541, $r['total_kop']);
        $this->assertSame('1/366', $r['rows'][0]['den_text']);
    }

    public function test_gk395_non_leap_year()
    {
        // 2023: 100 000 × 13 % × 20 / 365 = 712,3288 → 712,33
        $r = self::_c('gk395', '100000', '2023-09-30', '2023-10-20');
        $this->assertSame(71233, $r['total_kop']);
    }

    public function test_gk395_new_year_splits_by_divisor_and_rate()
    {
        // 25.12.2023 – 10.01.2024 (на 25.12.2023 ставка 16 %): 31.12 → 2023 (365), с 1.01 → 2024 (366)
        $r = self::_c('gk395', '100000', '2023-12-24', '2024-01-10');

        $this->assertCount(2, $r['rows']);
        $this->assertSame(7, $r['rows'][0]['days']);    // 25–31 декабря
        $this->assertSame(10, $r['rows'][1]['days']);   // 1–10 января
        $this->assertSame('1/365', $r['rows'][0]['den_text']);
        $this->assertSame('1/366', $r['rows'][1]['den_text']);
        $this->assertSame(30685, $r['rows'][0]['kop']); // 100 000 × 16 % × 7 / 365 = 306,85
        $this->assertSame(43716, $r['rows'][1]['kop']); // 100 000 × 16 % × 10 / 366 = 437,16
        $this->assertSame(74401, $r['total_kop']);
    }

    public function test_moratorium_2022_is_excluded_for_gk395_not_for_tax()
    {
        $with = self::_c('gk395', '100000', '2022-03-15', '2022-12-15');
        $without = self::_c('gk395', '100000', '2022-03-15', '2022-12-15', ['moratorium' => 0]);

        $this->assertSame(184, $with['excluded']);  // 01.04–01.10.2022 включительно
        $this->assertSame(0, $without['excluded']);
        $this->assertTrue($with['total_kop'] < $without['total_kop']);
        $this->assertSame($with['days'] + 184, $without['days']);

        $tax = self::_c('tax', '100000', '2022-03-15', '2022-12-15', ['payer' => 'person']);
        $this->assertSame(0, $tax['excluded']);
    }

    public function test_contract_penalty_and_cap()
    {
        // 0,1 % в день × 30 дней = 3 000 ₽
        $r = self::_c('contract', '100000', '2024-08-01', '2024-08-31', ['rate' => '0,1']);
        $this->assertSame(300000, $r['total_kop']);
        $this->assertFalse($r['capped']);

        // потолок 2 % долга = 2 000 ₽
        $r = self::_c('contract', '100000', '2024-08-01', '2024-08-31', ['rate' => '0.1', 'cap' => '2']);
        $this->assertSame(200000, $r['total_kop']);
        $this->assertTrue($r['capped']);
        $this->assertSame(300000, $r['accrued_kop']);
        $this->assertSame(10200000, $r['sum_with_kop']);
    }

    public function test_contract_has_reference_amounts()
    {
        $r = self::_c('contract', '100000', '2024-08-01', '2024-08-31', ['rate' => '0.1']);

        $this->assertSame(147541, $r['refs']['gk395_kop']);
        $this->assertSame(295082, $r['refs']['double_key_kop']);   // 2 × 18 % × 30 / 366 × 100 000
    }

    public function test_errors()
    {
        $this->assertSame('bad_mode', self::_c('x', '100', '2024-01-01', '2024-02-01')['error']);
        $this->assertSame('bad_amount', self::_c('tax', '0', '2024-01-01', '2024-02-01')['error']);
        $this->assertSame('bad_amount', self::_c('tax', 'abc', '2024-01-01', '2024-02-01')['error']);
        $this->assertSame('bad_date', self::_c('tax', '100', '31.02.2024', '2024-02-01')['error']);
        $this->assertSame('no_delay', self::_c('tax', '100', '2024-02-01', '2024-02-01')['error']);
        $this->assertSame('no_delay', self::_c('tax', '100', '2024-02-01', '2024-01-01')['error']);
        $this->assertSame('too_early', self::_c('tax', '100', '2015-05-01', '2016-02-01')['error']);
        $this->assertSame('too_early', self::_c('gk395', '100', '2016-03-01', '2017-02-01')['error']);
        $this->assertSame('bad_rate', self::_c('contract', '100', '2024-01-01', '2024-02-01', ['rate' => '0'])['error']);
        $this->assertSame('bad_rate', self::_c('contract', '100', '2024-01-01', '2024-02-01', ['rate' => '50'])['error']);
        $this->assertSame('too_big', self::_c('tax', '999999999', '2024-01-01', '2024-02-01')['error']);
    }

    public function test_future_date_warns_and_uses_last_rate()
    {
        $r = self::_c('gk395', '100000', '2026-09-30', '2026-12-31');

        $this->assertNotEmpty($r['warnings']);
        $this->assertSame(14.0, $r['rows'][0]['rate']);
    }

    public function test_default_paid_date_is_today()
    {
        $r = self::_c('tax', '100000', '2026-09-01', NULL, ['payer' => 'person']);
        $this->assertSame(self::TODAY, $r['paid']);
        $this->assertSame(30, $r['days']);   // 02.09 – 01.10 включительно
    }

    public function test_parse_dates_dd_mm_yyyy()
    {
        $r = self::_c('tax', '100 000,50', '01.08.2024', '31.08.2024', ['payer' => 'person']);
        $this->assertSame(10000050, $r['amount_kop']);
        $this->assertSame('2024-08-01', $r['due']);
    }
}
