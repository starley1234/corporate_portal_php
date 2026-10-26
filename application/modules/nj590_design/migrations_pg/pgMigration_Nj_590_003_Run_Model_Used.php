<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-590/003: фактическая модель прогона (nj_590_runs.model_used).
 *
 * Воркер после каждого LLM-вызова знает, чем РЕАЛЬНО отвечали (модель могли
 * подменить: обход отсутствующей, повтор на DEFAULT_MODEL). Значение доезжает
 * с upload и показывается в прогоне: если фактическая не совпала с запрошенной —
 * это сразу видно. Применённые миграции не редактируем — поэтому отдельный файл.
 */
class pgMigration_Nj_590_003_Run_Model_Used extends pgMigration
{
    public function up()
    {
        DB::query(NULL, 'ALTER TABLE "nj_590_runs" ADD COLUMN IF NOT EXISTS "model_used" VARCHAR(120) NULL')->execute();
        DB::query(NULL, 'COMMENT ON COLUMN "nj_590_runs"."model_used" IS \'Фактическая модель генерации (сообщает воркер; может отличаться от model из-за обходов LLM-слоя)\'')->execute();
    }

    public function down()
    {
        DB::query(NULL, 'ALTER TABLE "nj_590_runs" DROP COLUMN IF EXISTS "model_used"')->execute();
    }
}
