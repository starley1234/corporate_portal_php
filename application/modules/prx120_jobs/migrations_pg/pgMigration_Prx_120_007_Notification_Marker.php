<?php defined('SYSPATH') or die('No direct script access.');

/** Забирает у runtime Notify владение маркером. Существующие timestamps не меняются. */
class pgMigration_Prx_120_007_Notification_Marker extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE "prx_120_jobs" ADD COLUMN IF NOT EXISTS "notified_at" TIMESTAMPTZ NULL');
    }

    public function down()
    {
        // Колонка существовала до этой миграции (runtime DDL Notify).
        // Не удаляем её и накопленные отметки при rollback владения схемой.
    }
}
