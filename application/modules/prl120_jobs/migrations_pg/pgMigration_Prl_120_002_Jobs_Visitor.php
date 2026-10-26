<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRL-120/002: привязка задач к посетителю (Visitor::uid()) — страница
 * «Мои задачи» собирает задачи из всех модулей в один список.
 */
class pgMigration_Prl_120_002_Jobs_Visitor extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
ALTER TABLE "prl_120_jobs" ADD COLUMN IF NOT EXISTS "visitor_id" VARCHAR(64) NULL;
CREATE INDEX IF NOT EXISTS "prl_120_jobs_visitor_idx" ON "prl_120_jobs" ("visitor_id", "id" DESC);
COMMENT ON COLUMN "prl_120_jobs"."visitor_id" IS 'Visitor::uid(): u:<user_id> или g:<ubid> — для страницы «Мои задачи»';
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $this->run_query('DROP INDEX IF EXISTS "prl_120_jobs_visitor_idx";');
        $this->run_query('ALTER TABLE "prl_120_jobs" DROP COLUMN IF EXISTS "visitor_id";');
    }
}
