<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx399: текст ДС хранится рядом с файлом (пишет воркер в delta_result,
 * ограничен PRX399_MAX_TEXT_BYTES). Без него невозможно side-by-side ревью
 * «ДС ↔ дельты»: у сканов без текстового слоя текст из PDF сайтом не
 * извлекается. base-файлам текст не нужен — их редакция собирается из AST.
 */
class pgMigration_Prx_399_003_DsText extends pgMigration {

    public function up()
    {
        $this->run_query("ALTER TABLE prx_399_files ADD COLUMN IF NOT EXISTS text TEXT NULL");
        $this->run_query("COMMENT ON COLUMN prx_399_files.text IS 'Полный текст ДС (от воркера) для ревью на карточке пакета'");
    }

    public function down()
    {
        $this->run_query("ALTER TABLE prx_399_files DROP COLUMN IF EXISTS text");
    }
}
