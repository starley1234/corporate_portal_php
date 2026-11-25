<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx150: файловые лимиты ключа (JSONB) — правка ключа без отзыва.
 *
 * Ключи 'max_file_bytes' / 'max_files_per_request' / 'allowed_formats'.
 * Отсутствующий ключ = безлимит (либо глобальный лимит модуля-потребителя).
 * Форматы — список расширений ('pdf', 'png', 'jpg', …); пустой список =
 * любые форматы, разрешённые модулем.
 */
class pgMigration_Prx_150_003_FileLimits extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
ALTER TABLE prx_150_api_clients ADD COLUMN IF NOT EXISTS file_limits JSONB NOT NULL DEFAULT '{}'::jsonb;
SQL);
    }

    public function down()
    {
        $this->run_query(<<<'SQL'
ALTER TABLE prx_150_api_clients DROP COLUMN IF EXISTS file_limits;
SQL);
    }
}
