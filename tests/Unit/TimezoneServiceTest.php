<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj648_Timezone (nj648_timezone — созвон по часовым поясам).
 *
 * Запуск: php tests/mini_runner.php TimezoneService
 */
class TimezoneServiceTest extends TestCase {

    public function test_resolve_city_code_or_iana_zone()
    {
        $moscow = Service_Nj648_Timezone::resolve('moscow');
        $this->assertSame('Europe/Moscow', $moscow['tz']);
        $this->assertSame('Москва', $moscow['city']);

        $tokyo = Service_Nj648_Timezone::resolve('Asia/Tokyo');
        $this->assertSame('Asia/Tokyo', $tokyo['tz'], 'Зону IANA принимаем как есть');

        $this->assertNull(Service_Nj648_Timezone::resolve('Нью-Васюки'));
        $this->assertNull(Service_Nj648_Timezone::resolve('Mars/Olympus'));
        $this->assertNull(Service_Nj648_Timezone::resolve(''));
    }

    public function test_people_drop_duplicates_and_fall_back_to_defaults()
    {
        $people = Service_Nj648_Timezone::people(['moscow', 'moscow', 'нет-такого', 'berlin']);
        $this->assertSame(['moscow', 'berlin'], array_column($people, 'code'));

        $default = Service_Nj648_Timezone::people([]);
        $this->assertTrue(count($default) >= 2, 'Пустой список заменяется городами по умолчанию');

        $many = Service_Nj648_Timezone::people(array_keys(Service_Nj648_Timezone::cities()));
        $this->assertSame(Service_Nj648_Timezone::max_people(), count($many),
            'Участников больше потолка в сетку не пускаем');
    }

    public function test_offset_and_difference_are_human_readable()
    {
        $this->assertSame('UTC+3', Service_Nj648_Timezone::offset_text(10800));
        $this->assertSame("UTC\xE2\x88\x923:30", Service_Nj648_Timezone::offset_text(-12600));
        $this->assertSame('UTC+5:30', Service_Nj648_Timezone::offset_text(19800));

        $this->assertSame('тот же час', Service_Nj648_Timezone::diff_text(10800, 10800));
        $this->assertSame('+7 ч', Service_Nj648_Timezone::diff_text(36000, 10800));
        $this->assertSame("\xE2\x88\x927 ч", Service_Nj648_Timezone::diff_text(10800, 36000));
    }

    public function test_common_working_window_for_moscow_and_vladivostok()
    {
        $people = Service_Nj648_Timezone::people(['moscow', 'vladivostok']);
        $grid = Service_Nj648_Timezone::grid($people, '2026-10-05', 9, 18);

        $this->assertSame(24, count($grid['hours']), 'Сетка — это сутки опорного города');
        $this->assertFalse($grid['none']);

        // Владивосток на 7 часов впереди Москвы: пересечение 9:00–11:00 по Москве
        $this->assertSame([['from' => '09:00', 'to' => '11:00', 'hours' => 2, 'index' => 9]], $grid['best']);
    }

    public function test_no_window_when_zones_are_too_far_apart()
    {
        $people = Service_Nj648_Timezone::people(['moscow', 'losangeles']);
        $grid = Service_Nj648_Timezone::grid($people, '2026-10-05', 9, 18);

        $this->assertTrue($grid['none'], 'С Лос-Анджелесом общего рабочего часа нет');
        $this->assertSame([], $grid['best']);
    }

    public function test_daylight_saving_is_taken_from_the_tz_database()
    {
        $berlin = Service_Nj648_Timezone::resolve('berlin');

        // Летом Берлин отстаёт от Москвы на час, зимой — на два
        $summer = Service_Nj648_Timezone::now($berlin, strtotime('2026-07-15 12:00:00 UTC'));
        $winter = Service_Nj648_Timezone::now($berlin, strtotime('2026-01-15 12:00:00 UTC'));

        $this->assertSame(7200, $summer['offset']);
        $this->assertTrue($summer['dst'], 'В июле в Берлине летнее время');
        $this->assertSame(3600, $winter['offset']);
        $this->assertFalse($winter['dst']);

        // В России перевода стрелок нет — смещение одинаковое круглый год
        $moscow = Service_Nj648_Timezone::resolve('moscow');
        $this->assertSame(10800, Service_Nj648_Timezone::now($moscow, strtotime('2026-07-15 12:00:00 UTC'))['offset']);
        $this->assertSame(10800, Service_Nj648_Timezone::now($moscow, strtotime('2026-01-15 12:00:00 UTC'))['offset']);
    }

    public function test_cells_know_when_it_is_already_another_day()
    {
        $people = Service_Nj648_Timezone::people(['moscow', 'kamchatka']);
        $grid = Service_Nj648_Timezone::grid($people, '2026-10-05', 9, 18);

        $late = $grid['hours'][23];   // 23:00 по Москве
        $this->assertSame(1, $late['cells'][1]['shift'], 'На Камчатке это уже следующие сутки');
        $this->assertSame(0, $grid['hours'][0]['cells'][0]['shift'], 'У опорного города сдвига нет никогда');
    }

    public function test_input_guards()
    {
        $this->assertSame(9, Service_Nj648_Timezone::hour('', 9));
        $this->assertSame(9, Service_Nj648_Timezone::hour('вечером', 9));
        $this->assertSame(9, Service_Nj648_Timezone::hour(42, 9));
        $this->assertSame(7, Service_Nj648_Timezone::hour('7', 9));

        $this->assertSame('2026-10-05', Service_Nj648_Timezone::date('2026-10-05'));
        $this->assertSame(date('Y-m-d'), Service_Nj648_Timezone::date('2026-02-31'), 'Несуществующая дата → сегодня');
        $this->assertSame(date('Y-m-d'), Service_Nj648_Timezone::date('завтра'));

        $this->assertTrue(Service_Nj648_Timezone::is_weekend('2026-10-04'));    // воскресенье
        $this->assertFalse(Service_Nj648_Timezone::is_weekend('2026-10-05'));   // понедельник
    }

    public function test_working_day_cannot_be_inverted()
    {
        $people = Service_Nj648_Timezone::people(['moscow']);
        $grid = Service_Nj648_Timezone::grid($people, '2026-10-05', 20, 6);

        $this->assertTrue($grid['to'] > $grid['from'], 'Рабочий день через полночь не поддержан — приводим к часу');
        $this->assertSame(21, $grid['to']);
    }
}
