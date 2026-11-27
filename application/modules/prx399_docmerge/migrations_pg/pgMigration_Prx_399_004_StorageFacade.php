<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx399: файлы пакета — через фасад Storage:: (prx212), как у остальных
 * модулей. Раньше PDF писались прямо в storage/docmerge, а путь в БД был
 * зашит как 'storage/docmerge/…' даже при заданном PRX399_STORAGE_DIR —
 * воркер получал file_unavailable. Новые файлы: file_id (prx212), storage_path
 * NULL; старые записи читаются по storage_path, пока их пакеты не удалят.
 */
class pgMigration_Prx_399_004_StorageFacade extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE prx_399_files ADD COLUMN IF NOT EXISTS file_id BIGINT NULL');
        $this->run_query('ALTER TABLE prx_399_files ALTER COLUMN storage_path DROP NOT NULL');
        $this->run_query('CREATE INDEX IF NOT EXISTS prx_399_files_file ON prx_399_files (file_id) WHERE file_id IS NOT NULL');
        $this->run_query("COMMENT ON COLUMN prx_399_files.file_id IS 'Файл prx212 (Storage::, модуль prx399); NULL — старая запись со storage_path'");
    }

    public function down()
    {
        $this->run_query('DROP INDEX IF EXISTS prx_399_files_file');
        $this->run_query("UPDATE prx_399_files SET storage_path = '' WHERE storage_path IS NULL");
        $this->run_query('ALTER TABLE prx_399_files ALTER COLUMN storage_path SET NOT NULL');
        $this->run_query('ALTER TABLE prx_399_files DROP COLUMN IF EXISTS file_id');
    }
}
