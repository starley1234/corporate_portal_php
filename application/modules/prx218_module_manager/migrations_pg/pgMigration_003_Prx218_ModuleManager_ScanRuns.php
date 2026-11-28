<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx218: история сканирований и признак «папка найдена».
 *
 * Раньше «Последняя активность реестра» бралась из дат записей модулей и
 * смешивала сканирование с правками и LLM-анализом: по ней нельзя было
 * сказать, когда сканер реально ходил по диску и чем кончился. Теперь каждый
 * запуск — строка prx_218_scan_runs (время, кто, счётчики, итог/ошибка).
 *
 * dir_found/last_seen_at — отдельный признак «папка модуля есть на диске на
 * момент последнего скана»: удалённая папка больше не выглядит живым модулем,
 * а запись реестра (ручные поля, история) не теряется.
 */
class pgMigration_003_Prx218_ModuleManager_ScanRuns extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS prx_218_scan_runs (
    id BIGSERIAL PRIMARY KEY,
    started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at TIMESTAMPTZ NULL,
    actor_id BIGINT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'ok', 'partial', 'error')),
    found INTEGER NOT NULL DEFAULT 0,
    created INTEGER NOT NULL DEFAULT 0,
    updated INTEGER NOT NULL DEFAULT 0,
    skipped INTEGER NOT NULL DEFAULT 0,
    missing INTEGER NOT NULL DEFAULT 0,
    error TEXT NULL
)
SQL);
        $this->run_query('CREATE INDEX IF NOT EXISTS prx_218_scan_runs_started_idx ON prx_218_scan_runs (started_at DESC)');
        $this->run_query('ALTER TABLE prx_218_modules ADD COLUMN IF NOT EXISTS dir_found BOOLEAN NOT NULL DEFAULT TRUE');
        $this->run_query('ALTER TABLE prx_218_modules ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMPTZ NULL');
    }

    public function down()
    {
        $this->run_query('ALTER TABLE prx_218_modules DROP COLUMN IF EXISTS last_seen_at');
        $this->run_query('ALTER TABLE prx_218_modules DROP COLUMN IF EXISTS dir_found');
        $this->run_query('DROP TABLE IF EXISTS prx_218_scan_runs');
    }
}
