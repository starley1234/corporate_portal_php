<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_Prx_110_008_PublicationArchive extends pgMigration
{
    public function up()
    {
        $this->run_query("ALTER TABLE prx_110_publications
            DROP CONSTRAINT IF EXISTS prx_110_publications_state_check,
            ADD CONSTRAINT prx_110_publications_state_check CHECK (state IN ('approved', 'wip', 'archived'))");
    }

    public function down()
    {
        $this->run_query("UPDATE prx_110_publications SET state = 'wip' WHERE state = 'archived'");
        $this->run_query("ALTER TABLE prx_110_publications
            DROP CONSTRAINT IF EXISTS prx_110_publications_state_check,
            ADD CONSTRAINT prx_110_publications_state_check CHECK (state IN ('approved', 'wip'))");
    }
}
