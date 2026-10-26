<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj651_Meal (nj651_meal — калории по фото тарелки).
 *
 * Проверяем то, что сервис считает сам: калорийность по БЖУ, итог тарелки,
 * суточную норму (Миффлин — Сан-Жеор) и долю тарелки от неё.
 *
 * Запуск: php tests/mini_runner.php MealService
 */
class MealServiceTest extends TestCase {

    public function test_kcal_are_recalculated_from_macros()
    {
        // Модель назвала круглые «500 ккал», хотя БЖУ дают 200.
        $data = Service_Nj651_Meal::shape(['dishes' => [
            ['name' => 'Творог', 'grams' => 200, 'kcal' => 500,
             'protein' => 36, 'fat' => 4, 'carbs' => 5],
        ]]);

        $this->assertSame(200, $data['dishes'][0]['kcal'],
            '36*4 + 4*9 + 5*4 = 200: верим макронутриентам, а не «на глаз»');
    }

    public function test_small_disagreement_keeps_model_value()
    {
        // Расхождение меньше трети — это нормальная погрешность, не врём.
        $data = Service_Nj651_Meal::shape(['dishes' => [
            ['name' => 'Суп', 'grams' => 300, 'kcal' => 220,
             'protein' => 10, 'fat' => 10, 'carbs' => 20],
        ]]);

        $this->assertSame(220, $data['dishes'][0]['kcal']);
    }

    public function test_dish_without_any_numbers_is_dropped()
    {
        $data = Service_Nj651_Meal::shape(['dishes' => [
            ['name' => 'Непонятное'],
            ['name' => '', 'kcal' => 100],
            ['name' => 'Хлеб', 'grams' => 40, 'kcal' => 90],
        ]]);

        $this->assertCount(1, $data['dishes']);
        $this->assertSame('Хлеб', $data['dishes'][0]['name']);
    }

    public function test_total_sums_every_dish()
    {
        $data = Service_Nj651_Meal::shape(['dishes' => [
            ['name' => 'Курица', 'grams' => 180, 'kcal' => 297, 'protein' => 56, 'fat' => 7, 'carbs' => 0],
            ['name' => 'Рис',    'grams' => 150, 'kcal' => 195, 'protein' => 4,  'fat' => 0.5, 'carbs' => 42],
        ]]);

        $this->assertSame(330, $data['total']['grams']);
        $this->assertSame(60.0, $data['total']['protein']);
        $this->assertSame(7.5, $data['total']['fat']);
        $this->assertGreaterThan(400, $data['total']['kcal']);
    }

    public function test_balance_is_counted_in_calories_not_grams()
    {
        // По весу жира меньше, по калориям — ровно столько же.
        $balance = Service_Nj651_Meal::balance(9, 4, 0);

        $this->assertSame(50.0, $balance['protein']);
        $this->assertSame(50.0, $balance['fat']);
        $this->assertSame(0.0, $balance['carbs']);
    }

    public function test_balance_of_empty_plate_is_zero()
    {
        $this->assertSame(
            ['protein' => 0.0, 'fat' => 0.0, 'carbs' => 0.0],
            Service_Nj651_Meal::balance(0, 0, 0));
    }

    public function test_profile_defaults_and_clamps()
    {
        $this->assertSame(Service_Nj651_Meal::DEFAULT_PROFILE,
            Service_Nj651_Meal::profile([]));

        $p = Service_Nj651_Meal::profile([
            'sex' => 'M', 'weight' => '1000', 'height' => '10',
            'age' => '0', 'activity' => 'космос', 'goal' => 'loss',
        ]);

        $this->assertSame('m', $p['sex']);
        $this->assertSame(250, $p['weight'], 'Вес зажат верхней границей');
        $this->assertSame(120, $p['height'], 'Рост зажат нижней границей');
        $this->assertSame(35, $p['age'], 'Ноль — не возраст, берём значение по умолчанию');
        $this->assertSame('light', $p['activity'], 'Неизвестная активность игнорируется');
        $this->assertSame('loss', $p['goal']);
    }

    public function test_norm_matches_mifflin_formula()
    {
        // Мужчина 80 кг, 180 см, 30 лет: 10*80 + 6.25*180 - 5*30 + 5 = 1780.
        $norm = Service_Nj651_Meal::norm([
            'sex' => 'm', 'weight' => 80, 'height' => 180, 'age' => 30,
            'activity' => 'medium', 'goal' => 'keep',
        ]);

        $this->assertSame(1780, $norm['bmr']);
        $this->assertSame((int) round(1780 * 1.55), $norm['kcal']);
        $this->assertSame(128, $norm['protein'], 'Белок 1,6 г на кг веса');
    }

    public function test_goal_changes_the_norm()
    {
        $base = ['sex' => 'f', 'weight' => 60, 'height' => 165, 'age' => 30, 'activity' => 'low'];

        $loss = Service_Nj651_Meal::norm($base + ['goal' => 'loss']);
        $keep = Service_Nj651_Meal::norm($base + ['goal' => 'keep']);
        $gain = Service_Nj651_Meal::norm($base + ['goal' => 'gain']);

        $this->assertLessThan($keep['kcal'], $loss['kcal']);
        $this->assertGreaterThan($keep['kcal'], $gain['kcal']);
    }

    public function test_share_of_daily_norm()
    {
        $this->assertSame(25.0, Service_Nj651_Meal::share(500, 2000));
        $this->assertSame(0.0, Service_Nj651_Meal::share(500, 0), 'Нет нормы — нет доли');
    }

    public function test_verdict_speaks_about_big_plate()
    {
        $total = ['kcal' => 1400, 'protein' => 30, 'fat' => 90, 'carbs' => 100];
        $norm  = ['kcal' => 2000, 'protein' => 100, 'fat' => 60, 'carbs' => 250];

        $lines = Service_Nj651_Meal::verdict($total, $norm);

        $this->assertStringContainsString('больше половины', $lines[0]);
        $this->assertStringContainsString('жиры', implode(' ', $lines));
    }

    public function test_num_reads_strings_from_model()
    {
        $this->assertSame(180.0, Service_Nj651_Meal::num('180 г'));
        $this->assertSame(12.5, Service_Nj651_Meal::num('12,5'));
        $this->assertSame(0.0, Service_Nj651_Meal::num('—'));
    }

    public function test_dishes_word_declension()
    {
        $this->assertSame('блюдо', Service_Nj651_Meal::dishes_word(1));
        $this->assertSame('блюда', Service_Nj651_Meal::dishes_word(3));
        $this->assertSame('блюд', Service_Nj651_Meal::dishes_word(5));
        $this->assertSame('блюд', Service_Nj651_Meal::dishes_word(11));
        $this->assertSame('блюдо', Service_Nj651_Meal::dishes_word(21));
    }
}
