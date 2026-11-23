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

    public function test_prx_modules_registered(): void
    {
        $modules = array_keys(Kohana::modules());
        foreach (['prx102_core', 'prx111_migrations', 'prx103_platform', 'prx120_jobs', 'prx115_semantic', 'prx208_events'] as $m) {
            $this->assertContains($m, $modules, "Модуль $m не подключён в bootstrap.php");
        }
    }

    public function test_core_auth_models_keep_shared_table_names(): void
    {
        $this->assertSame('users', ORM::factory('Prx102_User')->table_name());
        $this->assertSame('roles', ORM::factory('Prx102_Role')->table_name());
        $this->assertSame('users', ORM::factory('User')->table_name());
        $this->assertSame('roles', ORM::factory('Role')->table_name());
    }

    public function test_critical_config_loads(): void
    {
        foreach (['database', 'llm', 'prx120_jobs'] as $cfg) {
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
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_name = 'prx_120_jobs'", FALSE)
            ->current();
        $this->assertSame('1', (string) ((array) $count)['c'],
            'Таблица очереди prx_120_jobs не найдена — выполните: make migrate');
    }

    public function test_semantic_tables_exists(): void
    {
        $db = Database::instance();
        foreach (['prx_115_collections', 'prx_115_vectors'] as $table) {
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
            'SELECT prx_115_vec_dist(ARRAY[1.0, 0.0]::real[], ARRAY[0.0, 1.0]::real[]) AS d', FALSE)
            ->current();
        $distance = (float) ((array) $row)['d'];
        $this->assertEqualsWithDelta(1.0, $distance, 0.001,
            'Ортогональные векторы должны иметь дистанцию 1 (косинусная)');
    }
}
