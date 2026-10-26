<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj508_Calendar (nj508_calendar).
 *
 * Запуск: php tests/mini_runner.php CalendarService
 * Эталоны — официальные календари: по каждому году сверены число рабочих дней,
 * норма часов по месяцам (40 ч) и число сокращённых дней.
 */
class CalendarServiceTest extends TestCase {

    /** год => [рабочих, норма 40 ч за год, сокращённых, [рабочих по месяцам], [часы по месяцам]] */
    protected static $_golden = [
        2024 => [248, 1979.0, 5],
        2025 => [247, 1972.0, 4],
        2026 => [247, 1972.0, 4, [15, 19, 21, 22, 19, 21, 23, 21, 22, 22, 20, 22],
                                [120.0, 152.0, 168.0, 175.0, 151.0, 167.0, 184.0, 168.0, 176.0, 176.0, 159.0, 176.0]],
        2027 => [247, 1972.0, 4],
    ];

    public function test_golden_totals_per_year()
    {
        foreach (self::$_golden as $year => $g)
        {
            $t = Service_Nj508_Calendar::year_table($year);
            $this->assertNotNull($t, "год $year должен быть в таблице");
            $this->assertSame($g[0], $t['totals']['work'], "рабочих дней $year");
            $this->assertSame($g[1], $t['totals']['h40'], "норма 40 ч $year");
            $this->assertSame($g[2], $t['totals']['short'], "сокращённых $year");
            $this->assertTrue(isset($t['source']) AND $t['source'] !== '', "источник $year");
        }
    }

    public function test_golden_months_2026()
    {
        $t = Service_Nj508_Calendar::year_table(2026);
        $work = []; $h40 = [];
        foreach ($t['months'] as $m)
        {
            $work[] = $m['work'];
            $h40[] = $m['h40'];
        }
        $this->assertSame(self::$_golden[2026][3], $work);
        $this->assertSame(self::$_golden[2026][4], $h40);
        // 36- и 24-часовая неделя: апрель 2026
        $this->assertSame(157.4, $t['months'][4]['h36']);
        $this->assertSame(104.6, $t['months'][4]['h24']);
    }

    public function test_holiday_transfer_and_short_day()
    {
        // праздник
        $d = Service_Nj508_Calendar::day_info(Service_Nj508_Calendar::parse_date('2026-06-12'));
        $this->assertSame('holiday', $d['type']);
        // перенос: 9 января 2026 (пятница) — выходной, хотя будний
        $d = Service_Nj508_Calendar::day_info(Service_Nj508_Calendar::parse_date('2026-01-09'));
        $this->assertSame('holiday', $d['type']);
        // сокращённый день
        $d = Service_Nj508_Calendar::day_info(Service_Nj508_Calendar::parse_date('2026-04-30'));
        $this->assertSame('work', $d['type']);
        $this->assertSame(7, $d['hours']);
        $this->assertTrue($d['short']);
        // рабочая суббота 2027
        $d = Service_Nj508_Calendar::day_info(Service_Nj508_Calendar::parse_date('2027-02-20'));
        $this->assertSame('work', $d['type']);
        $this->assertSame(7, $d['hours']);
        // обычное воскресенье
        $d = Service_Nj508_Calendar::day_info(Service_Nj508_Calendar::parse_date('2026-03-15'));
        $this->assertSame('weekend', $d['type']);
    }

    public function test_between_may_2026()
    {
        // май 2026: 31 день, рабочих 19, норма 151 ч
        $r = Service_Nj508_Calendar::between('2026-05-01', '2026-05-31');
        $this->assertSame(31, $r['calendar']);
        $this->assertSame(19, $r['work']);
        $this->assertSame(151, $r['hours']);
        $this->assertSame(12, $r['off']);
        $this->assertFalse($r['approx']);
    }

    public function test_between_inclusive_flag_and_swap()
    {
        $inc = Service_Nj508_Calendar::between('2026-09-01', '2026-09-07', TRUE);
        $exc = Service_Nj508_Calendar::between('2026-09-01', '2026-09-07', FALSE);
        $this->assertSame(7, $inc['calendar']);
        $this->assertSame(6, $exc['calendar']);
        $sw = Service_Nj508_Calendar::between('2026-09-07', '2026-09-01', TRUE);
        $this->assertTrue($sw['swapped']);
        $this->assertSame(7, $sw['calendar']);
    }

    public function test_missing_year_is_honest()
    {
        $r = Service_Nj508_Calendar::between('2030-01-01', '2030-01-31');
        $this->assertSame('no_calendar', $r['error']);
        $this->assertSame([2030], $r['missing_years']);

        // явное разрешение: по сб/вс, с пометкой
        $a = Service_Nj508_Calendar::between('2030-01-01', '2030-01-31', TRUE, TRUE);
        $this->assertTrue($a['approx']);
        $this->assertSame(23, $a['work']); // 31 день − 8 выходных (4 сб + 4 вс)

        // диапазон через границу известного и неизвестного года
        $c = Service_Nj508_Calendar::between('2027-12-20', '2028-01-10');
        $this->assertSame([2028], $c['missing_years']);
        $this->assertNull(Service_Nj508_Calendar::year_table(2030));
    }

    public function test_bad_dates()
    {
        $this->assertSame('bad_date', Service_Nj508_Calendar::between('2026-02-30', '2026-03-01')['error']);
        $this->assertSame('bad_date', Service_Nj508_Calendar::between('', '')['error']);
        $this->assertSame('bad_date', Service_Nj508_Calendar::age('1850-01-01', '2026-01-01')['error']);
        $this->assertSame('future', Service_Nj508_Calendar::age('2030-01-01', '2026-01-01')['error']);
    }

    public function test_parse_date_formats()
    {
        $this->assertSame('2026-03-05', Service_Nj508_Calendar::parse_date('05.03.2026')->format('Y-m-d'));
        $this->assertSame('2026-03-05', Service_Nj508_Calendar::parse_date('5.3.2026')->format('Y-m-d'));
        $this->assertNull(Service_Nj508_Calendar::parse_date('31.04.2026'));
        $this->assertNull(Service_Nj508_Calendar::parse_date('abc'));
        $this->assertNull(Service_Nj508_Calendar::parse_date(NULL));
    }

    public function test_age_exact()
    {
        $r = Service_Nj508_Calendar::age('1990-05-17', '2026-10-01');
        $this->assertSame(36, $r['years']);
        $this->assertSame(4, $r['months']);
        $this->assertSame(14, $r['rest_days']);
        $this->assertSame('36 лет 4 месяца 14 дней', $r['text']);
        $this->assertSame('2027-05-17', $r['next_birthday']);
        $this->assertSame(37, $r['turns']);
        $this->assertFalse($r['is_birthday']);
        $this->assertSame('четверг', $r['birth_weekday']);
    }

    public function test_age_birthday_today_and_leap_day()
    {
        $r = Service_Nj508_Calendar::age('2000-02-29', '2026-02-28');
        // ст. 192 ГК: в невисокосный год срок истекает 28 февраля → ровно 26 лет
        $this->assertSame(26, $r['years']);
        $this->assertSame(0, $r['months']);
        $this->assertSame(0, $r['rest_days']);
        $this->assertTrue($r['is_birthday']);

        $day_before = Service_Nj508_Calendar::age('2000-02-29', '2026-02-27');
        $this->assertSame(25, $day_before['years']);
        $this->assertSame('2026-02-28', $day_before['next_birthday']);

        $today = Service_Nj508_Calendar::age('1990-10-01', '2026-10-01');
        $this->assertTrue($today['is_birthday']);
        $this->assertSame(36, $today['years']);
        $this->assertSame(0, $today['days_to_birthday']);
    }

    public function test_age_inclusive_for_seniority()
    {
        // стаж включительно: с 01.09.2020 по 31.08.2021 — ровно 1 год
        $r = Service_Nj508_Calendar::age('2020-09-01', '2021-08-31', TRUE);
        $this->assertSame(1, $r['years']);
        $this->assertSame(0, $r['months']);
        $this->assertSame(0, $r['rest_days']);
        $this->assertSame(365, $r['days_total']);
    }

    public function test_period_month_clamping()
    {
        // 31 января → 28 февраля 2026 = 28 дней (не «1 месяц»), а до 1 марта — 1 месяц 1 день
        $a = Service_Nj508_Calendar::parse_date('2026-01-31');
        $p = Service_Nj508_Calendar::period($a, Service_Nj508_Calendar::parse_date('2026-02-27'));
        $this->assertSame([0, 0, 27], [$p['years'], $p['months'], $p['days']]);
        $p = Service_Nj508_Calendar::period($a, Service_Nj508_Calendar::parse_date('2026-02-28'));
        $this->assertSame([0, 1, 0], [$p['years'], $p['months'], $p['days']]);
    }

    public function test_until()
    {
        $r = Service_Nj508_Calendar::until('2026-12-31', '2026-10-01');
        $this->assertSame(91, $r['days']);
        $this->assertFalse($r['past']);
        $this->assertSame('четверг', $r['weekday']);
        // 31 декабря 2026 — выходной (перенос), рабочие дни: с 2 окт по 30 дек
        $this->assertSame(63, $r['work']);

        $past = Service_Nj508_Calendar::until('2026-09-01', '2026-10-01');
        $this->assertTrue($past['past']);
        $this->assertSame(30, $past['abs_days']);

        $today = Service_Nj508_Calendar::until('2026-10-01', '2026-10-01');
        $this->assertTrue($today['is_today']);
    }

    public function test_add_calendar_and_work_days()
    {
        $c = Service_Nj508_Calendar::add('2026-12-25', 10, 'calendar');
        $this->assertSame('2027-01-04', $c['result']);

        // +3 рабочих дня от 29.04.2026: 30.04 (сокр.), 01.05 — праздник, 04.05, 05.05 → 05.05
        $w = Service_Nj508_Calendar::add('2026-04-29', 3, 'work');
        $this->assertSame('2026-05-05', $w['result']);

        // назад: −1 рабочий день от понедельника 2026-02-23 (праздник) → пятница 20 февраля
        $b = Service_Nj508_Calendar::add('2026-02-24', -1, 'work');
        $this->assertSame('2026-02-20', $b['result']);

        // за границу таблицы без разрешения — честная ошибка
        $e = Service_Nj508_Calendar::add('2027-12-29', 5, 'work');
        $this->assertSame('no_calendar', $e['error']);
        $ok = Service_Nj508_Calendar::add('2027-12-29', 5, 'work', TRUE);
        $this->assertTrue($ok['approx']);

        $this->assertSame('bad_count', Service_Nj508_Calendar::add('2026-01-01', 9999, 'calendar')['error']);
    }

    public function test_plural_and_period_text()
    {
        $this->assertSame('год', Service_Nj508_Calendar::plural(21, 'год', 'года', 'лет'));
        $this->assertSame('года', Service_Nj508_Calendar::plural(3, 'год', 'года', 'лет'));
        $this->assertSame('лет', Service_Nj508_Calendar::plural(11, 'год', 'года', 'лет'));
        $this->assertSame('лет', Service_Nj508_Calendar::plural(112, 'год', 'года', 'лет'));
        $this->assertSame('0 дней', Service_Nj508_Calendar::period_text(['years' => 0, 'months' => 0, 'days' => 0]));
    }

    public function test_known_years_cover_current_plan()
    {
        $known = Service_Nj508_Calendar::known_years();
        foreach ([2024, 2025, 2026, 2027] as $y)
        {
            $this->assertTrue(in_array($y, $known, TRUE), "календарь $y заполнен");
        }
    }
}
