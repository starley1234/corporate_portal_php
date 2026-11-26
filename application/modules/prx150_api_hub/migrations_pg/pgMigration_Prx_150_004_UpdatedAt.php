<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRX-150/004: updated_at у ключа.
 *
 * Правка ключа (edit/save) пишет updated_at = now(), но колонки в схеме 001
 * не было — на проде сохранение падало «column "updated_at" does not exist».
 */
class pgMigration_Prx_150_004_UpdatedAt extends pgMigration
{
    public function up()
    {
        $this->run_query("ALTER TABLE prx_150_api_clients
            ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NULL");
    }

    public function down()
    {
        $this->run_query("ALTER TABLE prx_150_api_clients DROP COLUMN IF EXISTS updated_at");
    }
}
