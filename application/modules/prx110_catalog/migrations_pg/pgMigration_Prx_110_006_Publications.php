<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_Prx_110_006_Publications extends pgMigration
{
    public function up()
    {
        $this->run_query('CREATE TABLE IF NOT EXISTS prx_110_publications (
            module_name VARCHAR(128) PRIMARY KEY,
            approved_by INTEGER REFERENCES users (id) ON DELETE SET NULL,
            approved_at TIMESTAMPTZ NOT NULL DEFAULT now()
        )');
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS prx_110_publications');
    }
}
