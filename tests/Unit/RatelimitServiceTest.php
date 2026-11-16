<?php

use PHPUnit\Framework\TestCase;
/**
 * Юнит-тесты Service_Prl100_Ratelimit (prl100_platform).
 *
 * Чистые функции allowed()/limit_text() и входная валидация имен таблиц/колонок
 * (бросает исключение ДО запроса к БД — база для этих ассертов не нужна).
 */
class RatelimitServiceTest extends TestCase {

    // ---------- решение по окну ----------

    public function test_allowed_under_limits()
    {
        $this->assertTrue(Service_Prl100_Ratelimit::allowed(['minute' => 0, 'hour' => 0], 5, 60));
        $this->assertTrue(Service_Prl100_Ratelimit::allowed(['minute' => 4, 'hour' => 59], 5, 60));
    }

    public function test_allowed_at_or_over_limits()
    {
        // ровно порог — это УЖЕ нельзя (выполнится 6-я операция)
        $this->assertFalse(Service_Prl100_Ratelimit::allowed(['minute' => 5, 'hour' => 0], 5, 60));
        $this->assertFalse(Service_Prl100_Ratelimit::allowed(['minute' => 0, 'hour' => 60], 5, 60));
        $this->assertFalse(Service_Prl100_Ratelimit::allowed(['minute' => 99, 'hour' => 600], 5, 60));
    }

    public function test_allowed_with_missing_keys()
    {
        // пустой счётчик окна не должен ронять решение
        $this->assertTrue(Service_Prl100_Ratelimit::allowed([], 5, 60));
    }

    // ---------- текст лимита ----------

    public function test_limit_text()
    {
        $this->assertSame('5 проверок в минуту, 60 в час',
            Service_Prl100_Ratelimit::limit_text(5, 60, 'проверок'));
        $this->assertSame('3 операций в минуту, 120 в час',
            Service_Prl100_Ratelimit::limit_text(3, 120, 'операций'));
    }

    // ---------- валидация входных имён (до обращения к БД) ----------

    public function test_window_counts_rejects_bad_names()
    {
        // SQL-инъекция в имя таблицы невозможна: имя не проходит валидацию
        $fail = FALSE;

        try
        {
            Service_Prl100_Ratelimit::window_counts('prl_633_checks; DROP TABLE users', '1.2.3.4');
        }
        catch (InvalidArgumentException $e)
        {
            $fail = TRUE;
        }

        $this->assertTrue($fail, 'невалидное имя таблицы должно быть отвергнуто');

        $fail = FALSE;

        try
        {
            Service_Prl100_Ratelimit::window_counts('prl_633_checks', '1.2.3.4', 'ip" = \'x');
        }
        catch (InvalidArgumentException $e)
        {
            $fail = TRUE;
        }

        $this->assertTrue($fail, 'невалидное имя колонки должно быть отвергнуто');

        $fail = FALSE;

        try
        {
            Service_Prl100_Ratelimit::window_counts('prl_633_checks', '1.2.3.4', 'client_ip', ['a;b']);
        }
        catch (InvalidArgumentException $e)
        {
            $fail = TRUE;
        }

        $this->assertTrue($fail, 'невалидный вид операции должен быть отвергнут');
    }

    public function test_window_counts_returns_shape_on_real_table()
    {
        $db = Database::instance();
        $db->query(NULL, "CREATE TEMP TABLE prl_100_test_rates (client_ip text, kind text, created_at timestamptz)");
        try
        {
            $counts = Service_Prl100_Ratelimit::window_counts('prl_100_test_rates', '203.0.113.7', 'client_ip', ['dns']);
            $this->assertSame(0, $counts['minute']);
            $this->assertSame(0, $counts['hour']);
        }
        finally
        {
            $db->query(NULL, 'DROP TABLE prl_100_test_rates');
        }
    }
}
