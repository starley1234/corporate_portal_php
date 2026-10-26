<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-602/004: исследовательский конвейер — фактлист и оценка качества.
 *
 * nj_602_articles.facts   — JSONB-массив фактов, по которым написан текст:
 *   [{"src": n, "fact": "…", "kind": "number|date|…", "when": "2025", "why": "…"}, …]
 *   src = номер источника в sources (1-based) — ссылки [n] в теле ведут на него.
 * nj_602_articles.quality — JSONB-объект метрики «предметности»:
 *   {score, critic, novelty, water, facts, facts_numeric, citations, sources_cited,
 *    numbers, banned[], revised, thesis, title_hint, pipeline}
 * Пишет воркер nj602_article (handlers/nj602_research.py) через /content/api/item.
 */
class pgMigration_Nj_602_004_Facts_Quality extends pgMigration {

	public function up()
	{
		$this->run_query(<<<'SQL'
ALTER TABLE "nj_602_articles"
    ADD COLUMN IF NOT EXISTS "facts" JSONB NULL,
    ADD COLUMN IF NOT EXISTS "quality" JSONB NULL;
COMMENT ON COLUMN "nj_602_articles"."facts" IS 'Фактлист исследовательского конвейера: [{src, fact, kind, when, why}, …]';
COMMENT ON COLUMN "nj_602_articles"."quality" IS 'Метрика предметности статьи: {score, critic, novelty, water, facts, citations, …}';
SQL);
	}

	public function down()
	{
		$this->run_query('ALTER TABLE "nj_602_articles" DROP COLUMN IF EXISTS "facts", DROP COLUMN IF EXISTS "quality"');
	}
}
