<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj650_Receipt (nj650_receipt — чек по фото).
 *
 * Проверяем ровно то, за что сервис отвечает вместо модели: числа из грязного
 * ответа, сверку с итогом чека и дележ без потерянных копеек.
 *
 * Запуск: php tests/mini_runner.php ReceiptService
 */
class ReceiptServiceTest extends TestCase {

    protected function _raw(array $over = [])
    {
        return $over + [
            'merchant' => 'Пятёрочка',
            'date'     => '03.10.2026',
            'currency' => 'RUB',
            'total'    => 544.8,
            'items'    => [
                ['name' => 'Молоко 3,2%', 'qty' => 2, 'price' => 89.9, 'sum' => 179.8, 'category' => 'продукты'],
                ['name' => 'Хлеб',        'qty' => 1, 'price' => 45,   'sum' => 45,    'category' => 'продукты'],
                ['name' => 'Кофе',        'qty' => 1, 'price' => 320,  'sum' => 320,   'category' => 'напитки'],
            ],
        ];
    }

    public function test_number_reads_money_written_by_model()
    {
        // Модель регулярно присылает деньги строкой — с валютой и пробелами.
        $this->assertSame(1199.5, Service_Nj650_Receipt::number('1 199,50 ₽'));
        $this->assertSame(89.9, Service_Nj650_Receipt::number('89.90'));
        $this->assertSame(1199.5, Service_Nj650_Receipt::number('1.199.50'));
        $this->assertSame(0.0, Service_Nj650_Receipt::number('—'));
        $this->assertSame(-50.0, Service_Nj650_Receipt::number('-50'));
    }

    public function test_shape_restores_missing_sum_and_price()
    {
        $data = Service_Nj650_Receipt::shape(['items' => [
            ['name' => 'Сыр', 'qty' => 2, 'price' => 150],          // нет суммы
            ['name' => 'Вес', 'qty' => 0.5, 'sum' => 120],          // нет цены
        ]]);

        $this->assertSame(300.0, $data['items'][0]['sum']);
        $this->assertSame(240.0, $data['items'][1]['price']);
        $this->assertSame(420.0, $data['items_total']);
    }

    public function test_shape_drops_garbage_rows()
    {
        $data = Service_Nj650_Receipt::shape(['items' => [
            ['name' => '',    'sum' => 100],   // без названия
            ['name' => 'ИНН', 'sum' => 0],     // нулевая сумма
            ['name' => 'Чай', 'sum' => 90],
            'не массив',
        ]]);

        $this->assertCount(1, $data['items']);
        $this->assertSame('Чай', $data['items'][0]['name']);
    }

    public function test_check_accepts_kopeck_rounding()
    {
        $ok = Service_Nj650_Receipt::check(544.80, 545.00);

        $this->assertTrue($ok['ok'], 'Расхождение в пределах рубля — это округление кассы');
    }

    public function test_check_reports_missing_line()
    {
        $bad = Service_Nj650_Receipt::check(400.0, 544.8);

        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('меньше итога', $bad['message']);
    }

    public function test_check_without_total_falls_back_to_items()
    {
        $none = Service_Nj650_Receipt::check(544.8, 0);

        $this->assertTrue($none['ok']);
        $this->assertSame(0.0, $none['delta']);
    }

    public function test_unknown_category_becomes_other()
    {
        $this->assertSame('прочее', Service_Nj650_Receipt::category('фейерверки'));
        $this->assertSame('напитки', Service_Nj650_Receipt::category('Напитки'));
    }

    public function test_categories_are_sorted_by_money()
    {
        $data = Service_Nj650_Receipt::shape($this->_raw());
        $cats = $data['categories'];

        $this->assertSame('напитки', $cats[0]['key'], 'Первой идёт самая дорогая категория');
        $this->assertSame(58.7, $cats[0]['share']);
        $this->assertSame(224.8, $cats[1]['sum']);
    }

    public function test_split_keeps_every_kopeck()
    {
        // 100,00 ₽ на троих — классическая потеря копейки.
        $split = Service_Nj650_Receipt::split([['name' => 'Пицца', 'sum' => 100.0]], 3);

        $this->assertSame(100.0, round(array_sum($split['shares']), 2));
        $this->assertSame([33.34, 33.33, 33.33], array_values($split['shares']));
    }

    public function test_split_adds_tip_to_total()
    {
        $split = Service_Nj650_Receipt::split([['name' => 'Ужин', 'sum' => 1000.0]], 2, [], 10);

        $this->assertSame(100.0, $split['tip_sum']);
        $this->assertSame(1100.0, $split['total']);
        $this->assertSame(1100.0, round(array_sum($split['shares']), 2));
    }

    public function test_split_by_who_took_what()
    {
        // Бутылку вина брали двое из четверых — она не делится на всех.
        $items = [
            ['name' => 'Паста', 'sum' => 400.0],
            ['name' => 'Вино',  'sum' => 200.0],
        ];
        $split = Service_Nj650_Receipt::split($items, 4, [1 => [1, 2]]);

        $this->assertSame(200.0, $split['shares'][1]);
        $this->assertSame(200.0, $split['shares'][2]);
        $this->assertSame(100.0, $split['shares'][3]);
        $this->assertSame(600.0, round(array_sum($split['shares']), 2));
    }

    public function test_split_clamps_people()
    {
        $this->assertSame(1, Service_Nj650_Receipt::split([], 0)['people']);
        $this->assertSame(20, Service_Nj650_Receipt::split([], 99)['people']);
    }

    public function test_date_is_normalised()
    {
        $this->assertSame('2026-10-03', Service_Nj650_Receipt::date('03.10.2026'));
        $this->assertSame('2026-10-03', Service_Nj650_Receipt::date('2026-10-03'));
        $this->assertSame('', Service_Nj650_Receipt::date('позавчера'));
    }

    public function test_shape_uses_items_total_when_receipt_total_lost()
    {
        $data = Service_Nj650_Receipt::shape($this->_raw(['total' => 0]));

        $this->assertSame(544.8, $data['total']);
        $this->assertTrue($data['check']['ok']);
    }
}
