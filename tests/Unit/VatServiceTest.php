<?php

use PHPUnit\Framework\TestCase;
/**
 * Юнит-тесты Service_Nj501_Vat (nj501_vat).
 *
 * Запуск (на машине с PHP): phpunit --filter VatService
 * Эталонные значения сверены вручную (см. docs/RESEARCH-N2J-SERVICES.md §7).
 */
class VatServiceTest extends TestCase {

    protected function _assert_kop($expected, $amount, $rate, $mode, $field)
    {
        $r = Service_Nj501_Vat::calculate($amount, $rate, $mode);
        $this->assertNotNull($r, "$amount / $rate / $mode должен считаться");
        $this->assertSame($expected, $r[$field.'_kop'],
            "«{$field}» для [$amount, $rate%, $mode]");
    }

    // ---------- парсинг сумм ----------

    public function test_amount_parsing()
    {
        $this->assertSame(123456,    Service_Nj501_Vat::amount_to_kop('1 234,56'));
        $this->assertSame(123456,    Service_Nj501_Vat::amount_to_kop("1\xC2\xA0234.56")); // nbsp
        $this->assertSame(100000,    Service_Nj501_Vat::amount_to_kop('1000'));
        $this->assertSame(100000,    Service_Nj501_Vat::amount_to_kop(1000));
        $this->assertSame(5000,      Service_Nj501_Vat::amount_to_kop('50'));
        $this->assertSame(1050,      Service_Nj501_Vat::amount_to_kop('10,5'));
        $this->assertSame(NULL,      Service_Nj501_Vat::amount_to_kop(''));
        $this->assertSame(NULL,      Service_Nj501_Vat::amount_to_kop('abc'));
        $this->assertSame(NULL,      Service_Nj501_Vat::amount_to_kop('-100'));
        $this->assertSame(NULL,      Service_Nj501_Vat::amount_to_kop('10.123')); // 3 знака
        $this->assertSame(NULL,      Service_Nj501_Vat::amount_to_kop(str_repeat('9', 16)));
    }

    // ---------- ставки ----------

    public function test_rates()
    {
        $this->assertSame(22, Service_Nj501_Vat::normalize_rate(22));
        $this->assertSame(0,  Service_Nj501_Vat::normalize_rate('0'));
        $this->assertSame(NULL, Service_Nj501_Vat::normalize_rate(18)); // старая ставка — не принимается
        $this->assertSame(NULL, Service_Nj501_Vat::normalize_rate(200));

        $rates = Service_Nj501_Vat::rates();
        $this->assertSame([22, 20, 10, 7, 5, 0], Arr::pluck($rates, 'rate'));
    }

    // ---------- выделение (extract) ----------

    public function test_extract_20()
    {
        // 120 ₽ при 20%: НДС = 20,00; без НДС = 100,00
        $this->_assert_kop(2000,  120,   20, 'extract', 'vat');
        $this->_assert_kop(10000, 120,   20, 'extract', 'net');
        $this->_assert_kop(12000, 120,   20, 'extract', 'total');
        // 118 при 20%: 11800×20/120 = 1966,67 → НДС 19,67 (классика 118→18 была для ставки 18%)
        $this->_assert_kop(1967,  118,   20, 'extract', 'vat');
        $this->_assert_kop(9833,  118,   20, 'extract', 'net');
    }

    public function test_extract_22()
    {
        // 10 000 ₽ при 22%: НДС = round(1000000 × 22/122) = 180 327,87 → 180 328 kop = 1 803,28
        $this->_assert_kop(180328, '10 000', 22, 'extract', 'vat');
        $this->_assert_kop(819672, '10 000', 22, 'extract', 'net');
        $this->_assert_kop(1000000, '10 000', 22, 'extract', 'total');
    }

    public function test_extract_usn()
    {
        // УСН 5%: из 105 000 → НДС 5 000
        $this->_assert_kop(500000, '105 000', 5, 'extract', 'vat');
        // УСН 7%: из 107 → НДС 7
        $this->_assert_kop(700, 107, 7, 'extract', 'vat');
    }

    public function test_rounding_half_up_extract()
    {
        // 100,01 при 20%: 10001×20/120 = 1666,833… → 1667 kop (16,67), net = 8334
        $this->_assert_kop(1667, '100,01', 20, 'extract', 'vat');
        $this->_assert_kop(8334, '100,01', 20, 'extract', 'net');
    }

    // ---------- начисление (add) ----------

    public function test_add()
    {
        // 1000 + 22% = 1220 (vat = 220,00 ₽ = 22000 kop)
        $this->_assert_kop(22000,  1000, 22, 'add', 'vat');
        $this->_assert_kop(100000, 1000, 22, 'add', 'net');
        $this->_assert_kop(122000, 1000, 22, 'add', 'total');
        // 100 + 5% = 105
        $this->_assert_kop(500, 100, 5, 'add', 'vat');
        // 100 + 7% = 107
        $this->_assert_kop(700, 100, 7, 'add', 'vat');
        // 100 + 20% = 120
        $this->_assert_kop(2000, 100, 20, 'add', 'vat');
    }

    public function test_rounding_half_up_add()
    {
        // 0,05 при 22%: 5×22/100 = 1,1 → 1 kop
        $this->_assert_kop(1, '0.05', 22, 'add', 'vat');
        $this->_assert_kop(6, '0.05', 22, 'add', 'total');
    }

    // ---------- 0% ----------

    public function test_zero_rate()
    {
        $r = Service_Nj501_Vat::calculate('5 000', 0, 'extract');
        $this->assertSame(0, $r['vat_kop']);
        $this->assertSame(500000, $r['net_kop']);
        $this->assertSame('без НДС', $r['formula']);

        $r = Service_Nj501_Vat::calculate('5 000', 0, 'add');
        $this->assertSame(0, $r['vat_kop']);
    }

    // ---------- некорректные данные ----------

    public function test_invalid()
    {
        $this->assertSame(NULL, Service_Nj501_Vat::calculate('abc', 22, 'extract'));
        $this->assertSame(NULL, Service_Nj501_Vat::calculate(100, 33, 'extract'));
        $this->assertSame(NULL, Service_Nj501_Vat::calculate(100, 22, 'nope'));
    }

    // ---------- инвариант: net + vat = total в обоих режимах ----------

    public function test_invariants()
    {
        foreach ([0, 5, 7, 10, 20, 22] as $rate)
        {
            foreach (['100', '1 234,56', '999 999,99'] as $amount)
            {
                foreach ([Service_Nj501_Vat::MODE_EXTRACT, Service_Nj501_Vat::MODE_ADD] as $mode)
                {
                    $r = Service_Nj501_Vat::calculate($amount, $rate, $mode);
                    $this->assertSame(
                        $r['net_kop'] + $r['vat_kop'],
                        $r['total_kop'],
                        "net+vat=total [$amount, $rate%, $mode]"
                    );
                    // в режиме extract total = введённая сумма
                    if ($mode === Service_Nj501_Vat::MODE_EXTRACT)
                    {
                        $this->assertSame($r['amount_kop'], $r['total_kop']);
                    }
                    else
                    {
                        $this->assertSame($r['amount_kop'], $r['net_kop']);
                    }
                }
            }
        }
    }

    // ---------- формат ----------

    public function test_format()
    {
        $this->assertSame('1 234 567,89 ₽', Service_Nj501_Vat::format_kop(123456789));
        $this->assertSame('10 000,00 ₽',    Service_Nj501_Vat::format_kop(1000000));
        $this->assertSame('0,05 ₽',         Service_Nj501_Vat::format_kop(5));
        $this->assertSame('10 000,00',      Service_Nj501_Vat::format_kop(1000000, FALSE));
    }
}
