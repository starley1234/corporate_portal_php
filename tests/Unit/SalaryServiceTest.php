<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj503_Salary (nj503_salary).
 *
 * Запуск: php tests/mini_runner.php SalaryService
 * Эталоны посчитаны вручную (НК РФ, ст. 224/226/218/52; взносы 30 % / 15,1 %).
 */
class SalaryServiceTest extends TestCase {

    protected static function _r($kop)
    {
        return $kop / 100;
    }

    public function test_simple_month_2026()
    {
        $r = Service_Nj503_Salary::calculate('100000', 'gross', ['year' => 2026]);

        $this->assertSame(10000000, $r['gross_kop']);
        $this->assertSame(1300000, $r['ndfl_kop']);
        $this->assertSame(8700000, $r['net_kop']);
        $this->assertSame(3000000, $r['contrib_kop']);   // 30 %
        $this->assertSame(20000, $r['injury_kop']);      // 0,2 %
        $this->assertSame(13020000, $r['cost_kop']);
        $this->assertSame(13.0, $r['ndfl_rate']);
        $this->assertSame(120000000, $r['year_total']['gross']);
        $this->assertSame(15600000, $r['year_total']['ndfl']);
        $this->assertSame([], $r['rate_steps']);
    }

    public function test_ndfl_rounds_to_whole_rubles()
    {
        // 12 345,67 × 13 % = 1 604,9371 → 1 605 ₽
        $r = Service_Nj503_Salary::calculate('12345.67', 'gross', ['year' => 2026]);
        $this->assertSame(160500, $r['ndfl_kop']);
        $this->assertSame(1234567 - 160500, $r['net_kop']);

        // ровно 50 копеек вверх: 100,00 ₽ × 13 % = 13 ₽ ровно; 103,85 × 13 % = 13,5005 → 14
        $r = Service_Nj503_Salary::calculate('103.85', 'gross', ['year' => 2026]);
        $this->assertSame(1400, $r['ndfl_kop']);
        // 103,84 × 13 % = 13,4992 → 13
        $r = Service_Nj503_Salary::calculate('103.84', 'gross', ['year' => 2026]);
        $this->assertSame(1300, $r['ndfl_kop']);
    }

    public function test_progressive_scale_crosses_threshold_cumulatively()
    {
        // 250 000 ₽ в месяц: за год 3 млн ₽, порог 2,4 млн пересекается в октябре
        $r = Service_Nj503_Salary::calculate('250000', 'gross', ['year' => 2026]);
        $m = $r['months'];

        $this->assertSame(3250000, $m[0]['ndfl']);   // 250 000 × 13 % = 32 500 ₽
        $this->assertSame(3250000, $m[8]['ndfl']);   // сентябрь ещё 13 %
        $this->assertSame(3450000, $m[9]['ndfl']);   // октябрь: накоплено 2,5 млн: налог 327 000 − уже удержано 292 500 = 34 500 ₽
        $this->assertSame(3750000, $m[10]['ndfl']);  // ноябрь — полностью 15 %
        $this->assertSame(15.0, $m[10]['rate']);
        $this->assertSame([['m' => 10, 'name' => 'Окт', 'rate' => 15.0]], $r['rate_steps']);

        // за год: 2 400 000 × 13 % + 600 000 × 15 % = 402 000 ₽
        $this->assertSame(40200000, $r['year_total']['ndfl']);
        $this->assertSame(300000000 - 40200000, $r['year_total']['net']);
    }

    public function test_contribution_limit_switches_tariff()
    {
        // 250 000 ₽ × 11 мес = 2 750 000; в декабре под базу 229 000 (30 %), сверх 21 000 (15,1 %)
        $r = Service_Nj503_Salary::calculate('250000', 'gross', ['year' => 2026, 'injury' => 0]);

        $this->assertSame(7500000, $r['months'][10]['contrib']);
        $this->assertSame(6870000 + 317100, $r['months'][11]['contrib']);
        $this->assertSame(11 * 7500000 + 7187100, $r['year_total']['contrib']);
    }

    public function test_child_deduction_stops_after_limit()
    {
        // двое детей: 1 400 + 2 800 = 4 200 ₽; лимит 450 000 ₽ нарастающим итогом
        $r = Service_Nj503_Salary::calculate('50000', 'gross', ['year' => 2026, 'children' => 2]);

        $this->assertSame(420000, $r['deduction_kop']);
        $this->assertSame(595400, $r['ndfl_kop']);                  // (50 000 − 4 200) × 13 % = 5 954
        $this->assertSame(5000000 - 595400, $r['net_kop']);
        $this->assertSame(420000, $r['months'][8]['deduction']);    // сентябрь: 450 000 ≤ лимит — вычет есть
        $this->assertSame(0, $r['months'][9]['deduction']);         // октябрь: 500 000 > лимита
        $this->assertSame(650000, $r['months'][9]['ndfl']);         // полные 13 %
    }

    public function test_child_deduction_variants()
    {
        $o26 = Service_Nj503_Salary::options(['year' => 2026, 'children' => 3]);
        $this->assertSame((1400 + 2800 + 6000) * 100, Service_Nj503_Salary::child_deduction_kop($o26));

        $o = Service_Nj503_Salary::options(['year' => 2026, 'children' => 1, 'disabled' => 1]);
        $this->assertSame((1400 + 12000) * 100, Service_Nj503_Salary::child_deduction_kop($o));

        $o = Service_Nj503_Salary::options(['year' => 2026, 'children' => 2, 'single' => 1]);
        $this->assertSame((1400 + 2800) * 2 * 100, Service_Nj503_Salary::child_deduction_kop($o));

        // до 2025 года: 1 400 / 1 400 / 3 000
        $o24 = Service_Nj503_Salary::options(['year' => 2024, 'children' => 3]);
        $this->assertSame((1400 + 1400 + 3000) * 100, Service_Nj503_Salary::child_deduction_kop($o24));

        // инвалидов не больше, чем детей; нерезидент вычетов не имеет
        $o = Service_Nj503_Salary::options(['year' => 2026, 'children' => 1, 'disabled' => 5]);
        $this->assertSame(1, $o['disabled']);
        $o = Service_Nj503_Salary::options(['year' => 2026, 'children' => 2, 'resident' => 0]);
        $this->assertSame(0, Service_Nj503_Salary::child_deduction_kop($o));
    }

    public function test_2024_two_step_scale()
    {
        // 2024: 13 % до 5 млн, 15 % сверх. 500 000 × 12 = 6 млн: ровно 5 млн к концу октября — порог пройден в ноябре
        $r = Service_Nj503_Salary::calculate('500000', 'gross', ['year' => 2024]);
        // 5 000 000 × 13 % + 1 000 000 × 15 % = 800 000
        $this->assertSame(80000000, $r['year_total']['ndfl']);
        $this->assertSame(11, $r['rate_steps'][0]['m']);
    }

    public function test_non_resident_flat_30()
    {
        $r = Service_Nj503_Salary::calculate('100000', 'gross', ['year' => 2026, 'resident' => 0, 'children' => 2]);
        $this->assertSame(3000000, $r['ndfl_kop']);
        $this->assertSame(0, $r['deduction_kop']);
        $this->assertNotEmpty($r['warnings']);
    }

    public function test_sme_reduced_tariff_2026()
    {
        // МРОТ 27 093 ₽ × 1,5 = 40 639,50 ₽ по 30 %, остальное по 15 %
        $r = Service_Nj503_Salary::calculate('100000', 'gross', ['year' => 2026, 'sme' => 1, 'injury' => 0]);
        // 4 063 950 × 30 % = 1 219 185;  5 936 050 × 15 % = 890 407,5 → итог округлён до копейки: 2 109 592,5 → 2 109 593 (в копейках)
        $this->assertSame(intdiv(4063950 * 300 + 5936050 * 150 + 500, 1000), $r['contrib_kop']);
        $this->assertTrue($r['contrib_kop'] < 3000000, 'льготный тариф меньше 30 %');
    }

    public function test_net_to_gross_roundtrip_is_exact()
    {
        foreach ([1000, 12345.67, 30000, 87000, 100000.01, 250000, 999999.99] as $net)
        {
            foreach ([['year' => 2026], ['year' => 2026, 'children' => 2], ['year' => 2025, 'resident' => 0], ['year' => 2024]] as $o)
            {
                $r = Service_Nj503_Salary::calculate((string) $net, 'net', $o);
                $this->assertNotNull($r);
                $want = (int) round($net * 100);
                $this->assertSame($want, $r['net_kop'], "на руки $net → gross {$r['gross_kop']}");
                $this->assertTrue($r['exact']);

                // и обратно: из найденного gross снова получаем те же «на руки»
                $back = Service_Nj503_Salary::calculate(sprintf('%.2f', $r['gross_kop'] / 100), 'gross', $o);
                $this->assertSame($want, $back['net_kop']);

                // gross — минимальный: на копейку меньше «на руки» не получить
                $this->assertTrue(Service_Nj503_Salary::net_first_month($r['gross_kop'] - 1, Service_Nj503_Salary::options($o)) !== $want || $r['gross_kop'] <= $want);
            }
        }
    }

    public function test_net_to_gross_known_value()
    {
        // 87 000 ₽ на руки при 13 % → 100 000 ₽ до налога
        $r = Service_Nj503_Salary::calculate('87000', 'net', ['year' => 2026]);
        $this->assertSame(10000000, $r['gross_kop']);
    }

    public function test_shares_add_up()
    {
        $r = Service_Nj503_Salary::calculate('100000', 'gross', ['year' => 2026]);
        $this->assertEqualsWithDelta(100.0, $r['share_net'] + $r['share_ndfl'] + $r['share_contrib'], 0.2);
    }

    public function test_bad_input()
    {
        $this->assertNull(Service_Nj503_Salary::calculate('abc', 'gross'));
        $this->assertNull(Service_Nj503_Salary::calculate('0', 'gross'));
        $this->assertNull(Service_Nj503_Salary::calculate('-5', 'gross'));
        $this->assertNull(Service_Nj503_Salary::calculate('100000', 'oops'));
        $this->assertNull(Service_Nj503_Salary::calculate('100000', 'gross', ['year' => 1999]));
        $this->assertNull(Service_Nj503_Salary::calculate('999999999999', 'gross'));
    }

    public function test_years_table_is_consistent()
    {
        $this->assertContains(2026, Service_Nj503_Salary::years());

        foreach (Service_Nj503_Salary::all_params() as $y => $p)
        {
            $this->assertSame(0, $p['ndfl_scale'][0][0], "год $y: шкала начинается с нуля");
            $prev = -1;
            foreach ($p['ndfl_scale'] as $band)
            {
                $this->assertGreaterThan($prev, $band[0], "год $y: пороги растут");
                $prev = $band[0];
            }
            $this->assertCount(3, $p['child']);
            $this->assertNotEmpty($p['source']);
        }
    }
}
