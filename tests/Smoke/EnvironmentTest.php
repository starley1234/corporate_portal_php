<?php

use PHPUnit\Framework\TestCase;

/**
 * Дымовые тесты окружения и ядра.
 *
 * Назначение — быстро понять, что «фундамент на месте»: конфиги читаются,
 * БД отвечает, критичные классы существуют. Логика модулей тестируется
 * в своих каталогах (tests/ внутри модуля или tests/<имя>/).
 */
class EnvironmentTest extends TestCase
{
    public function test_kohana_bootstrapped(): void
    {
        $this->assertTrue(class_exists('Kohana'), 'Ядро Kohana не загрузилось');
        $this->assertNotEmpty(Kohana::modules(), 'Модули не подключены');
    }

    public function test_erp_modules_registered(): void
    {
        $modules = array_keys(Kohana::modules());
        foreach (['erp100_base', 'nj111_migrations', 'nj100_platform', 'nj120_jobs', 'nj115_semantic', 'nj130_agents', 'erp208_events'] as $m) {
            $this->assertContains($m, $modules, "Модуль $m не подключён в bootstrap.php");
        }
    }

    public function test_critical_config_loads(): void
    {
        foreach (['database', 'llm', 'nj120_jobs'] as $cfg) {
            $this->assertNotEmpty(Kohana::$config->load($cfg), "Пустой конфиг: $cfg");
        }
    }

    public function test_database_connects(): void
    {
        $db = Database::instance();
        $result = $db->query(Database::SELECT, 'SELECT version() AS v', FALSE);
        $row = $result->current();
        $this->assertArrayHasKey('v', (array) $row, 'PostgreSQL не отвечает');
    }

    public function test_migrations_table_exists(): void
    {
        $db = Database::instance();
        $count = $db->query(Database::SELECT,
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_name = 'migrations_pg'", FALSE)
            ->current();
        $this->assertSame('1', (string) ((array) $count)['c'],
            'Таблица миграций не найдена — выполните: make migrate');
    }

    public function test_jobs_queue_table_exists(): void
    {
        $db = Database::instance();
        $count = $db->query(Database::SELECT,
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_name = 'nj_120_jobs'", FALSE)
            ->current();
        $this->assertSame('1', (string) ((array) $count)['c'],
            'Таблица очереди nj_120_jobs не найдена — выполните: make migrate');
    }

    public function test_semantic_tables_exists(): void
    {
        $db = Database::instance();
        foreach (['nj_115_collections', 'nj_115_vectors'] as $table) {
            $count = $db->query(Database::SELECT,
                "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_name = '$table'", FALSE)
                ->current();
            $this->assertSame('1', (string) ((array) $count)['c'],
                "Таблица $table не найдена — выполните: make migrate");
        }
    }

    public function test_agents_tables_exists(): void
    {
        $db = Database::instance();
        foreach (['nj_130_agents', 'nj_130_agent_runs', 'nj_130_agent_messages'] as $table) {
            $count = $db->query(Database::SELECT,
                "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_name = '$table'", FALSE)
                ->current();
            $this->assertSame('1', (string) ((array) $count)['c'],
                "Таблица $table не найдена — выполните: make migrate");
        }
    }

    public function test_vec_dist_function_exists(): void
    {
        $db = Database::instance();
        $row = $db->query(Database::SELECT,
            'SELECT nj_115_vec_dist(ARRAY[1.0, 0.0]::real[], ARRAY[0.0, 1.0]::real[]) AS d', FALSE)
            ->current();
        $distance = (float) ((array) $row)['d'];
        $this->assertEqualsWithDelta(1.0, $distance, 0.001,
            'Ортогональные векторы должны иметь дистанцию 1 (косинусная)');
    }
}
