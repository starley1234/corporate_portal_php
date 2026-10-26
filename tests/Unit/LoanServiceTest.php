<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj647_Loan (nj647_loan — кредит и ипотека).
 *
 * Запуск: php tests/mini_runner.php LoanService
 */
class LoanServiceTest extends TestCase {

    public function test_parse_number_understands_human_input()
    {
        $this->assertSame(1500000.5, Service_Nj647_Loan::parse_number('1 500 000,5'));
        $this->assertSame(1500000.0, Service_Nj647_Loan::parse_number("1\xC2\xA0500\xC2\xA0000"));
        $this->assertSame(18.0, Service_Nj647_Loan::parse_number(18));
        $this->assertSame(-7.0, Service_Nj647_Loan::parse_number("\xE2\x88\x927"));
        $this->assertNull(Service_Nj647_Loan::parse_number('примерно пять'));
        $this->assertNull(Service_Nj647_Loan::parse_number(''));
        $this->assertNull(Service_Nj647_Loan::parse_number(['1']));
    }

    public function test_annuity_payment_matches_the_textbook_formula()
    {
        // 1 000 000 под 12 % годовых на год — хрестоматийные 88 848,79
        $this->assertSame(88848.79, round(Service_Nj647_Loan::annuity_payment(1000000, 0.12 / 12, 12), 2));

        // Беспроцентная рассрочка: просто сумма, делённая на срок
        $this->assertSame(10000.0, round(Service_Nj647_Loan::annuity_payment(120000, 0, 12), 2));
    }

    public function test_schedule_closes_the_debt_exactly()
    {
        $r = Service_Nj647_Loan::calculate(['sum' => 1000000, 'rate' => 12, 'term' => 1, 'term_unit' => 'years']);

        $this->assertTrue($r['ok'], 'Корректные данные должны считаться');
        $this->assertSame(12, $r['months_real']);
        $this->assertSame(0.0, round($r['rows'][11]['balance'], 2), 'Последний платёж обязан закрыть долг в ноль');

        $principal = 0.0;

        foreach ($r['rows'] as $row)
        {
            $principal += $row['principal'] + $row['extra'];
        }

        $this->assertSame(1000000.0, round($principal, 2), 'Сумма всех «тел» равна телу кредита');
        $this->assertSame(round($r['sum'] + $r['interest'], 2), round($r['total'], 2));
    }

    public function test_differentiated_is_cheaper_but_starts_heavier()
    {
        $in = ['sum' => 3000000, 'rate' => 15, 'term' => 10, 'term_unit' => 'years'];

        $ann  = Service_Nj647_Loan::calculate(['type' => 'annuity'] + $in);
        $diff = Service_Nj647_Loan::calculate(['type' => 'diff'] + $in);

        $this->assertTrue($diff['interest'] < $ann['interest'],
            'Дифференцированный график всегда дешевле по процентам');
        $this->assertTrue($diff['payment_first'] > $ann['payment'],
            'Зато первый платёж у него больше — в этом весь компромисс');
        $this->assertTrue($diff['payment_last'] < $diff['payment_first'],
            'И платёж обязан убывать');
    }

    public function test_extra_payment_shortens_the_term()
    {
        $r = Service_Nj647_Loan::calculate([
            'sum' => 5000000, 'rate' => 18, 'term' => 20, 'term_unit' => 'years',
            'extra' => 10000, 'extra_mode' => 'term',
        ]);

        $this->assertTrue($r['months_real'] < 240, 'Срок обязан сократиться');
        $this->assertSame(240 - $r['months_real'], $r['saved']['months']);
        $this->assertTrue($r['saved']['interest'] > 0, 'Экономия на процентах положительная');

        // Платёж при сокращении срока не меняется (последний — закрывающий,
        // он гасит остаток и потому меньше)
        $this->assertSame(round($r['rows'][0]['payment'], 2), round($r['rows'][100]['payment'], 2));
        $this->assertTrue($r['payment_last'] <= $r['payment_first']);
    }

    public function test_extra_payment_can_lower_the_payment_instead()
    {
        $r = Service_Nj647_Loan::calculate([
            'sum' => 5000000, 'rate' => 18, 'term' => 20, 'term_unit' => 'years',
            'extra' => 10000, 'extra_mode' => 'payment',
        ]);

        // Срок сохраняется: платёж каждый месяц пересчитывается на оставшиеся
        // по ИСХОДНОМУ графику месяцы. Последний месяц может отпасть — в нём
        // досрочного платежа уже не требуется, долг закрыт.
        $this->assertTrue($r['months_real'] >= 239, 'Срок практически не меняется: получено '.$r['months_real']);
        $this->assertTrue($r['saved']['months'] <= 1);
        $this->assertTrue($r['payment_last'] < $r['payment_first'], 'Платёж уменьшается от месяца к месяцу');
        $this->assertTrue($r['rows'][100]['payment'] < $r['rows'][0]['payment']);
        $this->assertTrue($r['saved']['interest'] > 0, 'Переплата всё равно меньше, чем без досрочных');
    }

    public function test_bad_input_is_rejected_with_a_reason()
    {
        foreach ([
            ['sum' => 0, 'rate' => 10, 'term' => 5],
            ['sum' => 100000, 'rate' => 500, 'term' => 5],
            ['sum' => 100000, 'rate' => 10, 'term' => 0],
            ['sum' => 100000, 'rate' => 10, 'term' => 100, 'term_unit' => 'years'],   // 1200 месяцев
            ['sum' => 100000, 'rate' => 10, 'term' => 5, 'extra' => -100],
        ] as $in)
        {
            $r = Service_Nj647_Loan::calculate($in);

            $this->assertFalse($r['ok'], 'Такой ввод принимать нельзя: '.json_encode($in));
            $this->assertNotSame('', $r['error'], 'У отказа обязана быть человеческая причина');
        }
    }

    public function test_capacity_is_the_inverse_of_the_payment()
    {
        $payment = Service_Nj647_Loan::annuity_payment(2000000, 0.14 / 12, 60);

        $this->assertSame(2000000.0, round(Service_Nj647_Loan::capacity($payment, 14, 60), 2));
        $this->assertSame(600000.0, round(Service_Nj647_Loan::capacity(10000, 0, 60), 2));
        $this->assertNull(Service_Nj647_Loan::capacity(0, 14, 60));
    }

    public function test_payment_dates_do_not_jump_over_short_months()
    {
        // PHP сам из 31 января делает 3 марта — в графике платежей это ошибка
        $this->assertSame('2026-02-28', Service_Nj647_Loan::add_months('2026-01-31', 1));
        $this->assertSame('2028-02-29', Service_Nj647_Loan::add_months('2028-01-31', 1));
        $this->assertSame('2027-01-15', Service_Nj647_Loan::add_months('2026-10-15', 3));
    }

    public function test_term_words()
    {
        $this->assertSame('1 год 6 месяцев', Service_Nj647_Loan::term_words(18));
        $this->assertSame('20 лет', Service_Nj647_Loan::term_words(240));
        $this->assertSame('2 года 1 месяц', Service_Nj647_Loan::term_words(25));
        $this->assertSame('3 месяца', Service_Nj647_Loan::term_words(3));
    }
}
