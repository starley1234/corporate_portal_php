<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx399: управление качеством сборки.
 *
 *  included  — состав сборки решает человек (ADR-003): дельта excluded=no
 *              не попадает в редакцию при пересборке;
 *  body_in_ds — гард воркера: body дельты дословно найден в тексте ДС
 *              (yes/no); no → бейдж «модель пересказала» в карточке.
 * VARCHAR, не BOOLEAN: PDO/pgsql отдаёт булево строкой 't'/'f'.
 */
class pgMigration_Prx_399_002_QualityControls extends pgMigration {

    public function up()
    {
        $this->run_query("ALTER TABLE prx_399_deltas ADD COLUMN IF NOT EXISTS included VARCHAR(8) NOT NULL DEFAULT 'yes'");
        $this->run_query("ALTER TABLE prx_399_deltas ADD COLUMN IF NOT EXISTS body_in_ds VARCHAR(8) NULL");
        $this->run_query("COMMENT ON COLUMN prx_399_deltas.included IS 'yes|no — дельта включена человеком в состав сборки'");
        $this->run_query("COMMENT ON COLUMN prx_399_deltas.body_in_ds IS 'yes|no — гард воркера: body дословно найден в тексте ДС'");
    }

    public function down()
    {
        $this->run_query("ALTER TABLE prx_399_deltas DROP COLUMN IF EXISTS body_in_ds");
        $this->run_query("ALTER TABLE prx_399_deltas DROP COLUMN IF EXISTS included");
    }
}
