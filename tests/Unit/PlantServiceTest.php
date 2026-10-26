<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj652_Plant (nj652_plant — растение по фото).
 *
 * Главное здесь — календарь ухода: он строится на сервере, зимой растягивает
 * полив и не назначает подкормки. Остальное — защита от мусора в ответе
 * модели.
 *
 * Запуск: php tests/mini_runner.php PlantService
 */
class PlantServiceTest extends TestCase {

    protected function _care(array $over = [])
    {
        return $over + ['water_summer' => 5, 'water_winter' => 10, 'feed' => 21];
    }

    public function test_shape_fills_defaults_for_missing_care()
    {
        $data = Service_Nj652_Plant::shape([
            'plant' => ['name_ru' => 'Фикус'],
            'care'  => [],
        ]);

        $this->assertSame('Фикус', $data['plant']['name_ru']);
        $this->assertSame(5, $data['care']['water_summer'], 'Пустой интервал заменяется разумным');
        $this->assertSame(10, $data['care']['water_winter']);
        $this->assertSame(21, $data['care']['feed']);
        $this->assertFalse($data['care']['toxic_pets']);
    }

    public function test_shape_rejects_unknown_state()
    {
        $data = Service_Nj652_Plant::shape([
            'plant'     => ['name_ru' => 'Монстера'],
            'care'      => [],
            'condition' => ['state' => 'почти умерло', 'severity' => 9],
        ]);

        $this->assertSame('unknown', $data['condition']['state']);
        $this->assertSame(3, $data['condition']['severity'], 'Срочность зажата сверху');
    }

    public function test_shape_names_unknown_plant()
    {
        $data = Service_Nj652_Plant::shape(['plant' => [], 'care' => []]);

        $this->assertSame('Растение не определено', $data['plant']['name_ru']);
        $this->assertSame('Растение не определено', $data['title']);
    }

    public function test_shape_drops_duplicate_causes()
    {
        $data = Service_Nj652_Plant::shape([
            'plant'     => ['name_ru' => 'Орхидея'],
            'care'      => [],
            'condition' => ['causes' => ['Перелив', 'Перелив', 'Сквозняк', '']],
        ]);

        $this->assertSame(['Перелив', 'Сквозняк'], $data['condition']['causes']);
    }

    public function test_absurd_interval_falls_back_to_default()
    {
        // «Поливать раз в год» — это не уход, а сбой разбора.
        $data = Service_Nj652_Plant::shape([
            'plant' => ['name_ru' => 'Кактус'],
            'care'  => ['water_summer_days' => 365, 'feed_days' => 0],
        ]);

        $this->assertSame(5, $data['care']['water_summer']);
        $this->assertSame(21, $data['care']['feed']);
    }

    public function test_summer_schedule_uses_short_step()
    {
        $plan = Service_Nj652_Plant::schedule($this->_care(), '2026-06-01', 2);

        $water = array_values(array_filter($plan, function ($i) {
            return $i['what'] === 'water';
        }));

        $this->assertSame('2026-06-06', $water[0]['date']);
        $this->assertSame('2026-06-11', $water[1]['date']);
        $this->assertCount(2, $water, 'Две недели по пять дней — два полива');
    }

    public function test_winter_schedule_is_rarer()
    {
        $summer = Service_Nj652_Plant::schedule($this->_care(), '2026-06-01', 4);
        $winter = Service_Nj652_Plant::schedule($this->_care(), '2026-12-01', 4);

        $count = function (array $plan, $what) {
            return count(array_filter($plan, function ($i) use ($what) {
                return $i['what'] === $what;
            }));
        };

        $this->assertGreaterThan($count($winter, 'water'), $count($summer, 'water'),
            'Зимой поливов меньше');
    }

    public function test_no_feeding_in_winter()
    {
        $plan = Service_Nj652_Plant::schedule($this->_care(), '2026-12-01', 8);

        $this->assertSame([], array_values(array_filter($plan, function ($i) {
            return $i['what'] === 'feed';
        })), 'Зимой подкормки не назначаются вовсе');
    }

    public function test_schedule_is_sorted_by_date()
    {
        $plan  = Service_Nj652_Plant::schedule($this->_care(), '2026-05-01', 6);
        $dates = array_column($plan, 'date');
        $sorted = $dates;
        sort($sorted);

        $this->assertSame($sorted, $dates);
        $this->assertNotSame([], $plan);
    }

    public function test_schedule_rows_have_russian_weekday()
    {
        $plan = Service_Nj652_Plant::schedule($this->_care(), '2026-06-01', 1);

        // 6 июня 2026 — суббота.
        $this->assertSame('сб', $plan[0]['dow']);
    }

    public function test_is_winter_covers_november_to_february()
    {
        $this->assertTrue(Service_Nj652_Plant::is_winter(strtotime('2026-12-15')));
        $this->assertTrue(Service_Nj652_Plant::is_winter(strtotime('2026-02-15')));
        $this->assertFalse(Service_Nj652_Plant::is_winter(strtotime('2026-03-15')));
    }

    public function test_confidence_label_asks_to_double_check()
    {
        $this->assertStringContainsString('уверена', Service_Nj652_Plant::confidence_label(90));
        $this->assertStringContainsString('справочник', Service_Nj652_Plant::confidence_label(60));
        $this->assertStringContainsString('под вопросом', Service_Nj652_Plant::confidence_label(20));
    }

    public function test_state_and_severity_labels()
    {
        $this->assertSame('Выглядит здоровым', Service_Nj652_Plant::state_label('ok')[0]);
        $this->assertSame('Понять по фото не удалось', Service_Nj652_Plant::state_label('ерунда')[0]);
        $this->assertSame('Спасать прямо сейчас', Service_Nj652_Plant::severity_label(3));
        $this->assertSame('Спасать прямо сейчас', Service_Nj652_Plant::severity_label(99));
    }
}
