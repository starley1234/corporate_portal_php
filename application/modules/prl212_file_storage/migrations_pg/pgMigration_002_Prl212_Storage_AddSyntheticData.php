<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_002_Prl212_Storage_AddSyntheticData extends pgMigration {

    // protected $_db_group = 'default';

    public function up()
    {
        // Создаем уникальный индекс для корректной работы ON CONFLICT
        $this->run_query("CREATE UNIQUE INDEX IF NOT EXISTS prl_212_storage_backends_name_idx ON prl_212_storage_backends (name)");

        // Добавление дефолтного локального бэкенда
        $this->run_query("
            INSERT INTO prl_212_storage_backends (name, driver, is_active)
            VALUES ('Local Default', 'local', 1)
            ON CONFLICT (name) DO NOTHING;
        ");
    }

    public function down()
    {
        // Удаление дефолтного локального бэкенда
        $this->run_query("
            DELETE FROM prl_212_storage_backends
            WHERE name = 'Local Default' AND driver = 'local';
        ");

        // Удаление индекса
        $this->run_query("DROP INDEX IF EXISTS prl_212_storage_backends_name_idx");
    }
}
