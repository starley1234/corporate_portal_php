<?php

use PHPUnit\Framework\TestCase;

/** NJ-656: арифметика исторических показаний без базы. */
class MetersServiceTest extends TestCase
{
    public function test_device_and_reading_input_are_strictly_normalized(): void
    {
        $device = Service_Nj656_Meters::normalize_device([
            'title' => '  Холодная   вода ', 'unit' => 'm3', 'tariff' => '58,72', 'budget_rub' => '1 000',
        ]);
        $this->assertTrue($device['ok']);
        $this->assertSame('Холодная вода', $device['data']['title']);
        $this->assertSame(58.72, $device['data']['tariff']);
        $this->assertSame(1000.0, $device['data']['budget_rub']);
        $this->assertFalse(Service_Nj656_Meters::normalize_device(['title' => '', 'unit' => 'm3', 'tariff' => 1])['ok']);
        $this->assertFalse(Service_Nj656_Meters::normalize_device(['title' => 'Вода', 'unit' => 'm3', 'tariff' => '1e3'])['ok']);
        $this->assertFalse(Service_Nj656_Meters::normalize_reading(['reading_on' => '2026-02-30', 'value' => 1])['ok']);
        $this->assertFalse(Service_Nj656_Meters::normalize_reading(['reading_on' => '2099-02-01', 'value' => 1])['ok']);
        $this->assertFalse(Service_Nj656_Meters::normalize_reading(['reading_on' => '2026-02-01', 'value' => -1])['ok']);
    }

    public function test_dashboard_counts_consumption_using_actual_days_and_historical_tariff(): void
    {
        $dashboard = Service_Nj656_Meters::dashboard(['budget_rub' => 0], [
            ['reading_on' => '2026-01-01', 'value' => 100, 'tariff' => 10],
            ['reading_on' => '2026-01-11', 'value' => 110, 'tariff' => 12],
        ]);

        $this->assertSame(2, $dashboard['count']);
        $this->assertSame(10.0, $dashboard['last']['usage']);
        $this->assertSame(10, $dashboard['last']['days']);
        $this->assertSame(1.0, $dashboard['last']['daily']);
        $this->assertSame(120.0, $dashboard['last']['cost']);
        $this->assertSame(30.438, $dashboard['forecast_usage']);
        $this->assertSame(365.26, $dashboard['forecast_cost']);
    }

    public function test_jump_is_compared_to_three_prior_intervals_and_budget_is_honoured(): void
    {
        $dashboard = Service_Nj656_Meters::dashboard(['budget_rub' => 500], [
            ['reading_on' => '2026-01-01', 'value' => 100, 'tariff' => 10],
            ['reading_on' => '2026-01-11', 'value' => 110, 'tariff' => 10],
            ['reading_on' => '2026-01-21', 'value' => 120, 'tariff' => 10],
            ['reading_on' => '2026-01-31', 'value' => 130, 'tariff' => 10],
            ['reading_on' => '2026-02-10', 'value' => 150, 'tariff' => 10],
        ]);

        $this->assertSame(1.0, $dashboard['baseline_daily']);
        $this->assertSame(100.0, $dashboard['change_percent']);
        $this->assertSame(608.75, $dashboard['forecast_cost']);
        $this->assertSame(-108.75, $dashboard['budget_delta']);
        $insight = Service_Nj656_Meters::insight($dashboard);
        $this->assertSame('warn', $insight['level']);
        $this->assertStringContainsString('100', $insight['text']);
    }

    public function test_first_reading_does_not_invent_consumption_and_csv_has_bom(): void
    {
        $dashboard = Service_Nj656_Meters::dashboard(['budget_rub' => 0], [
            ['reading_on' => '2026-10-06', 'value' => 7.5, 'tariff' => 42],
        ]);
        $this->assertNull($dashboard['last']);
        $this->assertSame('neutral', Service_Nj656_Meters::insight($dashboard)['level']);

        $csv = Service_Nj656_Meters::csv(['title' => 'Вода', 'unit' => 'm3'], [
            ['reading_on' => '2026-10-06', 'value' => 7.5, 'tariff' => 42],
        ]);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Вода', $csv);
        $this->assertStringContainsString('2026-10-06', $csv);
    }
}
