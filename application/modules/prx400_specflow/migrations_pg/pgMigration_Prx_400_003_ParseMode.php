<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx400_specflow: режим разбора на спецификации.
 *
 * Запоминаем выбранный пользователем parse_mode (ocr|ocr_vision|vision):
 * «Повторить разбор» без явного режима в POST использует сохранённый —
 * старая открытая страница (форма без селектора) больше не откатывает
 * разбор скана в OCR молча.
 */
class pgMigration_Prx_400_003_ParseMode extends pgMigration
{
    public function up()
    {
        $this->run_query(
            "ALTER TABLE prx_400_specs ADD COLUMN IF NOT EXISTS parse_mode VARCHAR(16) NOT NULL DEFAULT 'ocr';");
    }

    public function down()
    {
        $this->run_query("ALTER TABLE prx_400_specs DROP COLUMN IF EXISTS parse_mode;");
    }
}
