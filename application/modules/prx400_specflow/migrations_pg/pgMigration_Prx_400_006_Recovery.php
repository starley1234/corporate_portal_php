<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx400_specflow: автоповтор разбора после падения воркера.
 *
 * recoveries — сколько раз prx400:purge сам перезапустил разбор, потому что
 * обслуживание очереди остановило задачу «воркер замолчал»
 * (Service_Prx120_JobQueue::STOP_WORKER_LOST). Предел — PRX400_CRASH_RETRIES;
 * ручной запуск оператором обнуляет счётчик.
 */
class pgMigration_Prx_400_006_Recovery extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE prx_400_specs ADD COLUMN IF NOT EXISTS recoveries SMALLINT NOT NULL DEFAULT 0');
        $this->run_query("COMMENT ON COLUMN prx_400_specs.recoveries IS 'Автоперезапуски после падения воркера (prx400:purge), обнуляется ручным запуском'");
    }

    public function down()
    {
        $this->run_query('ALTER TABLE prx_400_specs DROP COLUMN IF EXISTS recoveries');
    }
}
