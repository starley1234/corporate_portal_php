<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-593/002: «Тренды GitHub» — польза обзора.
 *
 *  * nj_593_items.apply    — «как применить/какие возможности даёт» (одна
 *    позиция обзора; пишет LLM от лица редактора дайджеста);
 *  * nj_593_reviews.takeaways — итоговые выводы дня: 2-4 коротких вывода
 *    «что взять в работу» по всему обзору (пишет LLM на финале).
 *
 * Обе колонки опциональны (NOT NULL DEFAULT '') — старые обзоры и старые
 * версии воркера продолжают работать без них.
 */
class pgMigration_Nj_593_002_Practical_Apply extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
ALTER TABLE "nj_593_items" ADD COLUMN IF NOT EXISTS "apply" TEXT NOT NULL DEFAULT '';
COMMENT ON COLUMN "nj_593_items"."apply" IS 'LLM: как применить этот репозиторий или какие возможности он открывает (1-2 предложения)';

ALTER TABLE "nj_593_reviews" ADD COLUMN IF NOT EXISTS "takeaways" TEXT NULL;
COMMENT ON COLUMN "nj_593_reviews"."takeaways" IS 'LLM: 2-4 вывода дня «что взять в работу» по всему обзору';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, <<<SQL
ALTER TABLE "nj_593_items" DROP COLUMN IF EXISTS "apply";
ALTER TABLE "nj_593_reviews" DROP COLUMN IF EXISTS "takeaways";
SQL
        )->execute();
    }
}
