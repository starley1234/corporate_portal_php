<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-602/003: журнал прогона статьи (прогресс воркера) для живой диагностики.
 *
 * nj_602_articles.progress — JSONB-массив меток воркера:
 *   [{"t": unix_ts, "phase": "sources|read|text|enrich|posted|error|...",
 *     "note": "человекочитаемое"}, …]
 * Пишет воркер через /content/api/progress/<code> (поле trace) и в момент
 * приёма статьи (api_save). Раньше /progress только ставил updated_at —
 * фазы бесследно пропадали, и на странице было не видно, где застрял сбор.
 */
class pgMigration_Nj_602_003_Progress_Log extends pgMigration {

	public function up()
	{
		$this->run_query(<<<'SQL'
ALTER TABLE "nj_602_articles"
    ADD COLUMN IF NOT EXISTS "progress" JSONB NULL;
COMMENT ON COLUMN "nj_602_articles"."progress" IS 'Журнал прогона воркера: [{t, phase, note}, …], пишется через /content/api/progress';
SQL);
	}

	public function down()
	{
		$this->run_query('ALTER TABLE "nj_602_articles" DROP COLUMN IF EXISTS "progress"');
	}
}
