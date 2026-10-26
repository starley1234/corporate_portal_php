<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj504_Vacation (nj504_vacation).
 *
 * Запуск: php tests/mini_runner.php VacationService
 */
class VacationServiceTest extends TestCase {

    protected static function _d($s)
    {
        return Service_Nj508_Calendar::parse_date($s);
    }

    public function test_holiday_rule_is_fixed_by_article_112()
    {
        foreach (['2026-01-01', '2026-01-07', '2026-01-08', '2026-02-23', '2026-03-08', '2026-05-01', '2026-05-09', '2026-06-12', '2026-11-04'] as $h)
        {
            $this->assertTrue(Service_Nj504_Vacation::is_holiday(self::_d($h)), $h);
        }

        // перенесённые выходные — НЕ праздники: 9 января и 9 марта 2026, 31 декабря
        foreach (['2026-01-09', '2026-01-12', '2026-03-09', '2026-05-11', '2026-12-31', '2026-11-03'] as $n)
        {
            $this->assertFalse(Service_Nj504_Vacation::is_holiday(self::_d($n)), $n);
        }
    }

    public function test_pay_by_hand()
    {
        // 1 200 000 / (12 × 29,3) = 3 412,9693 → 3 412,97; × 14 = 47 781,58; НДФЛ 13 % = 6 211,65 → 6 212
        $r = Service_Nj504_Vacation::pay('1200000', 12, 14, 13);

        $this->assertSame(341297, $r['avg_daily_kop']);
        $this->assertSame(4778158, $r['gross_kop']);
        $this->assertSame(621200, $r['ndfl_kop']);
        $this->assertSame(4778158 - 621200, $r['net_kop']);
        $this->assertSame(14, $r['table'][1]['days']);
        $this->assertSame(4778158, $r['table'][1]['gross_kop']);
    }

    public function test_pay_short_period_and_rates()
    {
        // 6 месяцев по 50 000 = 300 000 → 300 000 / (6 × 29,3) = 1 706,48 ₽/день
        $r = Service_Nj504_Vacation::pay('300000', 6, 10, 15);

        $this->assertSame(170648, $r['avg_daily_kop']);
        $this->assertSame(1706480, $r['gross_kop']);
        $this->assertSame(256000, $r['ndfl_kop']);   // 15 % от 17 064,80 = 2 559,72 → 2 560 ₽
    }

    public function test_pay_validation()
    {
        $this->assertSame('bad_income', Service_Nj504_Vacation::pay('', 12, 14)['error']);
        $this->assertSame('bad_income', Service_Nj504_Vacation::pay('-1', 12, 14)['error']);
        $this->assertSame('bad_months', Service_Nj504_Vacation::pay('1000', 13, 14)['error']);
        $this->assertSame('bad_months', Service_Nj504_Vacation::pay('1000', 0, 14)['error']);
        $this->assertSame('bad_days', Service_Nj504_Vacation::pay('1000', 12, 0)['error']);
        $this->assertSame('bad_rate', Service_Nj504_Vacation::pay('1000', 12, 14, 14)['error']);
    }

    public function test_dates_holidays_extend_vacation()
    {
        // 27.04.2026 + 14 дней: 1 и 9 мая — праздники, не входят → конец 12 мая, выход 13 мая
        $r = Service_Nj504_Vacation::dates('2026-04-27', 14);

        $this->assertSame('2026-05-12', $r['end']);
        $this->assertSame('2026-05-13', $r['return']);
        $this->assertSame(16, $r['span']);
        $this->assertSame(['2026-05-01', '2026-05-09'], array_column($r['holidays'], 'date'));
        $this->assertSame('2026-04-24', $r['pay_before']);   // за 3 календарных дня до начала
        $this->assertSame('2026-04-25', $r['rest']['from']); // слитный отдых с субботы
        $this->assertSame('2026-05-12', $r['rest']['to']);
    }

    public function test_dates_without_holidays_and_weekend_return()
    {
        // 13.07.2026 (пн) + 14 дней → 26.07 (вс); выход — в понедельник 27.07
        $r = Service_Nj504_Vacation::dates('13.07.2026', 14);

        $this->assertSame('2026-07-26', $r['end']);
        $this->assertSame('2026-07-27', $r['return']);
        $this->assertSame(14, $r['span']);
        $this->assertSame([], $r['holidays']);
        $this->assertSame(10, $r['work_skipped']);
    }

    public function test_dates_new_year_chain_spans_years()
    {
        // 28.12.2026 на 14 дней: 1–8 января 2027 (8 праздников) отпуск продлевают
        $r = Service_Nj504_Vacation::dates('2026-12-28', 14);

        $this->assertCount(8, $r['holidays']);
        $this->assertSame('2027-01-18', $r['end']);
        $this->assertSame('2027-01-19', $r['return']);
        $this->assertSame(22, $r['span']);
    }

    public function test_dates_errors()
    {
        $this->assertSame('bad_date', Service_Nj504_Vacation::dates('нет', 14)['error']);
        $this->assertSame('bad_days', Service_Nj504_Vacation::dates('2026-05-01', 0)['error']);
        $this->assertSame('bad_days', Service_Nj504_Vacation::dates('2026-05-01', 401)['error']);
    }

    public function test_worked_months_rounding_rule()
    {
        // 01.03.2025 – 15.06.2026 включительно: 15 мес. и 15 дн. → 16 (остаток ≥ 15 дней округляется вверх)
        $w = Service_Nj504_Vacation::worked_months(self::_d('2025-03-01'), self::_d('2026-06-15'));
        $this->assertSame(15, $w['months']);
        $this->assertSame(15, $w['extra_days']);
        $this->assertSame(16, $w['rounded']);

        // 01.01–14.01.2026 — 14 дней < 15 → 0 месяцев
        $w = Service_Nj504_Vacation::worked_months(self::_d('2026-01-01'), self::_d('2026-01-14'));
        $this->assertSame(0, $w['rounded']);

        // ровно месяц: 01.01–31.01
        $w = Service_Nj504_Vacation::worked_months(self::_d('2026-01-01'), self::_d('2026-01-31'));
        $this->assertSame(1, $w['rounded']);
    }

    public function test_compensation_by_hand()
    {
        // 16 мес. × 28/12 = 37,33 дня; использовано 14 → 23,33 → 24 дня; средний 3 412,97 → 81 911,28
        $r = Service_Nj504_Vacation::compensation('2025-03-01', '2026-06-15', 28, 14, '1200000', 12, 13);

        $this->assertSame(16, $r['months_rounded']);
        $this->assertSame(37.33, $r['earned_days']);
        $this->assertSame(23.33, $r['unused_days']);
        $this->assertSame(24, $r['paid_days']);
        $this->assertSame(341297 * 24, $r['gross_kop']);
        $this->assertSame($r['gross_kop'] - $r['ndfl_kop'], $r['net_kop']);
    }

    public function test_compensation_nothing_to_pay_and_errors()
    {
        $r = Service_Nj504_Vacation::compensation('2026-01-01', '2026-06-30', 28, 28, '600000', 6);
        $this->assertSame(0, $r['paid_days']);
        $this->assertSame(0, $r['gross_kop']);
        $this->assertNotEmpty($r['warnings']);

        $this->assertSame('bad_period', Service_Nj504_Vacation::compensation('2026-06-01', '2026-01-01', 28, 0, '1000', 12)['error']);
        $this->assertSame('bad_date', Service_Nj504_Vacation::compensation('x', '2026-01-01', 28, 0, '1000', 12)['error']);
        $this->assertSame('bad_annual', Service_Nj504_Vacation::compensation('2026-01-01', '2026-06-01', 0, 0, '1000', 12)['error']);
        $this->assertSame('bad_income', Service_Nj504_Vacation::compensation('2026-01-01', '2026-06-01', 28, 0, '', 12)['error']);
    }

    public function test_plan_finds_new_year_and_may_windows_2026()
    {
        $r = Service_Nj504_Vacation::plan(2026, 14);

        $this->assertSame(14, $r['days']);
        $this->assertTrue(count($r['top']) >= 3);

        // лучшее окно: сразу после новогодних праздников (слитный отдых с 31.12.2025)
        $best = $r['top'][0];
        $this->assertSame('2026-01-12', $best['start']);
        $this->assertSame(26, $best['rest']['len']);
        $this->assertSame(12, $best['bonus']);

        // бонус не бывает меньше нуля, лучший не хуже остальных
        foreach ($r['top'] as $w)
        {
            $this->assertTrue($w['bonus'] >= 0);
            $this->assertTrue($w['bonus'] <= $best['bonus']);
        }

        // по месяцам — не больше одного окна на месяц, по возрастанию
        $this->assertCount(12, $r['by_month']);
        $this->assertSame(1, $r['by_month'][0]['month']);

        // отпуск, перекрывающий майские, длиннее обычных 14 дней календарного отдыха
        $may = $r['by_month'][3]; // апрель: 24.04–08.05
        $this->assertSame('2026-04-24', $may['start']);
        $this->assertSame(18, $may['rest']['len']);
    }

    public function test_plan_errors()
    {
        $this->assertSame('no_calendar', Service_Nj504_Vacation::plan(1999, 14)['error']);
        $this->assertSame('bad_days', Service_Nj504_Vacation::plan(2026, 0)['error']);
        $this->assertSame('bad_days', Service_Nj504_Vacation::plan(2026, 43)['error']);
    }
}
