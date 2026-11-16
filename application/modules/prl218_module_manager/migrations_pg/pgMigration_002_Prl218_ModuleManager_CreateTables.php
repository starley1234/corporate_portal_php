<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_002_Prl218_ModuleManager_CreateTables extends pgMigration {

    // protected $_db_group = 'default';

    public function up()
    {
        // Добавление поля сортировки в таблицу модулей
        $this->run_query("ALTER TABLE prl_218_modules ADD COLUMN sort_order INTEGER DEFAULT 0;");
        $this->run_query("COMMENT ON COLUMN prl_218_modules.sort_order IS 'Порядок сортировки модулей в интерфейсе';");
        
        $this->run_query("CREATE INDEX IF NOT EXISTS idx_prl218_modules_sort_order ON prl_218_modules (sort_order);");
    }

    public function down()
    {
        $this->run_query("DROP INDEX IF EXISTS idx_prl218_modules_sort_order;");
        $this->run_query("ALTER TABLE prl_218_modules DROP COLUMN IF EXISTS sort_order;");
    }
}