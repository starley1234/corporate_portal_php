<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx218: сверка реестра с манифестами модулей и наличие памятки.
 *
 * manifest_title/manifest_short — что манифест (`config/prx_module.php`
 * title/short) говорил при последнем скане. По ним сканер отличает
 * автоматическое значение от ручного: название/описание обновляются из
 * манифеста, только если совпадают с прошлым манифестным (или пусты/равны
 * системному имени); правку человека скан не перетирает.
 * has_manifest/has_agents — есть ли манифест и памятка AGENTS.md на диске.
 */
class pgMigration_004_Prx218_ModuleManager_ManifestSync extends pgMigration
{
    public function up()
    {
        $this->run_query("ALTER TABLE prx_218_modules
            ADD COLUMN IF NOT EXISTS manifest_title VARCHAR(255) NULL,
            ADD COLUMN IF NOT EXISTS manifest_short TEXT NULL,
            ADD COLUMN IF NOT EXISTS has_manifest BOOLEAN NOT NULL DEFAULT FALSE,
            ADD COLUMN IF NOT EXISTS has_agents BOOLEAN NOT NULL DEFAULT FALSE");
        $this->run_query("ALTER TABLE prx_218_scan_runs
            ADD COLUMN IF NOT EXISTS synced INTEGER NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS no_agents INTEGER NOT NULL DEFAULT 0");
    }

    public function down()
    {
        $this->run_query("ALTER TABLE prx_218_scan_runs DROP COLUMN IF EXISTS synced, DROP COLUMN IF EXISTS no_agents");
        $this->run_query("ALTER TABLE prx_218_modules DROP COLUMN IF EXISTS manifest_title, DROP COLUMN IF EXISTS manifest_short,
            DROP COLUMN IF EXISTS has_manifest, DROP COLUMN IF EXISTS has_agents");
    }
}
