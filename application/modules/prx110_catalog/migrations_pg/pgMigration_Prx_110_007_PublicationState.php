<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_Prx_110_007_PublicationState extends pgMigration
{
    public function up()
    {
        $this->run_query("ALTER TABLE prx_110_publications ADD COLUMN IF NOT EXISTS state VARCHAR(8)
            NOT NULL DEFAULT 'approved' CHECK (state IN ('approved', 'wip'))");
    }

    public function down()
    {
        $this->run_query('ALTER TABLE prx_110_publications DROP COLUMN IF EXISTS state');
    }
}
