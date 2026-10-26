<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj518_Percent (nj518_percent).
 *
 * Запуск: php tests/mini_runner.php PercentService
 * Паритет с JS-зеркалом: node tests/Js/percent_parity.mjs
 */
class PercentServiceTest extends TestCase {

    protected function _calc($mode, $a, $b)
    {
        $r = Service_Nj518_Percent::calculate($mode, $a, $b);
        $this->assertNotNull($r, "$mode($a; $b) должен считаться");
        return $r;
    }

    public function test_parse_number()
    {
        $this->assertSame(1234.5, Service_Nj518_Percent::parse_number('1 234,5'));
        $this->assertSame(-7.0,   Service_Nj518_Percent::parse_number('−7'));
        $this->assertSame(0.5,    Service_Nj518_Percent::parse_number('.5'));
        $this->assertSame(12.0,   Service_Nj518_Percent::parse_number(12));
        $this->assertNull(Service_Nj518_Percent::parse_number('abc'));
        $this->assertNull(Service_Nj518_Percent::parse_number('1,2,3'));
        $this->assertNull(Service_Nj518_Percent::parse_number(''));
        $this->assertNull(Service_Nj518_Percent::parse_number(str_repeat('9', 16)));
        $this->assertNull(Service_Nj518_Percent::parse_number(['1']));
    }

    public function test_format()
    {
        $nb = "\xC2\xA0";
        $this->assertSame('1'.$nb.'234,56', Service_Nj518_Percent::fmt(1234.56));
        $this->assertSame('300', Service_Nj518_Percent::fmt(300.0));
        $this->assertSame('−20', Service_Nj518_Percent::fmt(-20.0));
        $this->assertSame('42,8571', Service_Nj518_Percent::fmt(42.857142857));
        $this->assertSame('0', Service_Nj518_Percent::fmt(0.00001));
        $this->assertSame('—', Service_Nj518_Percent::fmt(NULL));
        $this->assertSame('1'.$nb.'000', Service_Nj518_Percent::fmt(1000.0));
    }

    public function test_of_share_whole()
    {
        $this->assertSame(300.0, $this->_calc('of', 15, 2000)['value']);
        $this->assertSame(25.0,  $this->_calc('share', 30, 120)['value']);
        $this->assertSame(300.0, $this->_calc('whole', 45, 15)['value']);
        $this->assertNull(Service_Nj518_Percent::calculate('share', 5, 0));
        $this->assertNull(Service_Nj518_Percent::calculate('whole', 5, 0));
    }

    public function test_change_is_asymmetric()
    {
        // 80 → 100 = +25 %, а 100 → 80 = −20 %
        $up = $this->_calc('change', 80, 100);
        $this->assertSame(25.0, $up['value']);
        $this->assertSame('Прирост 25 %', $up['headline']);

        $down = $this->_calc('change', 100, 80);
        $this->assertSame(-20.0, $down['value']);
        $this->assertSame('Убыль 20 %', $down['headline']);

        $this->assertNull(Service_Nj518_Percent::calculate('change', 0, 5));
        // от отрицательного: −10 → −5 — это рост на 50 %
        $this->assertSame(50.0, $this->_calc('change', -10, -5)['value']);
    }

    public function test_add_sub_discount()
    {
        $this->assertSame(6000.0, $this->_calc('add', 20, 5000)['value']);
        $this->assertSame(87000.0, $this->_calc('sub', 13, 100000)['value']);
        $d = $this->_calc('discount', 4990, 25);
        $this->assertSame(3742.5, $d['value']);
        $this->assertSame('1'."\xC2\xA0".'247,5', $d['rows'][0][1]);
    }

    public function test_markup_vs_margin()
    {
        // себестоимость 700, наценка 40 % → цена 980, маржа 28,5714 %
        $m = $this->_calc('markup', 700, 40);
        $this->assertSame(980.0, $m['value']);
        $this->assertSame('28,5714 %', $m['rows'][1][1]);

        // цена 1000 при себестоимости 700: наценка 42,8571 %, маржа 30 %
        $p = $this->_calc('price', 700, 1000);
        $this->assertSame(42.8571, $p['value']);
        $this->assertSame('30 %', $p['rows'][0][1]);

        // нужна маржа 30 % при 700: цена 1000, наценка 42,8571 %
        $g = $this->_calc('margin', 700, 30);
        $this->assertSame(1000.0, $g['value']);
        $this->assertSame('42,8571 %', $g['rows'][1][1]);

        $this->assertNull(Service_Nj518_Percent::calculate('margin', 700, 100));
        $this->assertNull(Service_Nj518_Percent::calculate('price', 0, 100));
    }

    public function test_bad_input()
    {
        $this->assertNull(Service_Nj518_Percent::calculate('nope', 1, 2));
        $this->assertNull(Service_Nj518_Percent::calculate('of', 'x', 2));
        $this->assertNull(Service_Nj518_Percent::calculate('of', 1, NULL));
    }

    public function test_every_mode_has_example_that_works()
    {
        foreach (Service_Nj518_Percent::modes() as $code => $m)
        {
            $this->assertNotNull(Service_Nj518_Percent::calculate($code, $m['ex'][0], $m['ex'][1]), "пример режима $code");
        }
    }
}
