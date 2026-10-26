<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-661/001: таблицы «ИИ-радара» (nj661_airadar).
 *
 *   nj_661_runs    — прогоны конвейера (этапы для живого статус-ряда);
 *   nj_661_items   — найденные материалы (URL-дедуп: работу не делаем заново);
 *   nj_661_facts   — база знаний: факты с хешем и эмбеддингом (семантический
 *                    дедуп + sightings — сколько раз факт всплывал);
 *   nj_661_ideas   — идеи «что реализовать на n2j» (статус-борд владельца);
 *   nj_661_reports — отчёты и письма.
 *
 * Владение не нужно: модуль админский (audience admin), данные общие для
 * владельца. Эмбеддинг факта хранится TEXT (JSON-массив float) — без
 * зависимости от pgvector; поиск похожих — косинус в PHP по последним фактам
 * (паттерн nj654_similar, объёмы базы знаний это позволяют).
 */
class pgMigration_Nj_661_001_Airadar_CreateTables extends pgMigration
{
	public function up()
	{
		$sql = <<<SQL

CREATE TABLE IF NOT EXISTS "nj_661_runs" (
    "id"          BIGSERIAL PRIMARY KEY,
    "trigger"     VARCHAR(16)  NOT NULL DEFAULT 'schedule',
    "status"      VARCHAR(16)  NOT NULL DEFAULT 'new',
    "job_id"      BIGINT       NULL,
    "stage"       VARCHAR(120) NOT NULL DEFAULT '',
    "stage_note"  VARCHAR(300) NOT NULL DEFAULT '',
    "stats"       TEXT         NOT NULL DEFAULT '',
    "error"       TEXT         NOT NULL DEFAULT '',
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_661_runs_status_idx" ON "nj_661_runs" ("status", "created_at");

CREATE TABLE IF NOT EXISTS "nj_661_items" (
    "id"           BIGSERIAL PRIMARY KEY,
    "run_id"       BIGINT       NULL,
    "source"       VARCHAR(24)  NOT NULL,
    "url"          TEXT         NOT NULL,
    "url_hash"     VARCHAR(40)  NOT NULL,
    "host"         VARCHAR(120) NOT NULL DEFAULT '',
    "title"        VARCHAR(300) NOT NULL DEFAULT '',
    "snippet"      TEXT         NOT NULL DEFAULT '',
    "meta"         TEXT         NOT NULL DEFAULT '',
    "published_at" TIMESTAMPTZ  NULL,
    "status"       VARCHAR(16)  NOT NULL DEFAULT 'new',
    "category"     VARCHAR(24)  NOT NULL DEFAULT '',
    "score"        SMALLINT     NOT NULL DEFAULT 0,
    "why"          TEXT         NOT NULL DEFAULT '',
    "starred"      BOOLEAN      NOT NULL DEFAULT FALSE,
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS "nj_661_items_url_hash_uidx" ON "nj_661_items" ("url_hash");
CREATE INDEX IF NOT EXISTS "nj_661_items_score_idx" ON "nj_661_items" ("score", "created_at");

CREATE TABLE IF NOT EXISTS "nj_661_facts" (
    "id"          BIGSERIAL PRIMARY KEY,
    "fact_hash"   VARCHAR(40)  NOT NULL,
    "text"        TEXT         NOT NULL,
    "tags"        TEXT         NOT NULL DEFAULT '',
    "source_url"  TEXT         NOT NULL DEFAULT '',
    "item_id"     BIGINT       NULL,
    "sightings"   INTEGER      NOT NULL DEFAULT 1,
    "embedding"   TEXT         NOT NULL DEFAULT '',
    "first_seen"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "last_seen"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS "nj_661_facts_fact_hash_uidx" ON "nj_661_facts" ("fact_hash");
CREATE INDEX IF NOT EXISTS "nj_661_facts_last_seen_idx" ON "nj_661_facts" ("last_seen");

CREATE TABLE IF NOT EXISTS "nj_661_ideas" (
    "id"         BIGSERIAL PRIMARY KEY,
    "idea_hash"  VARCHAR(40)  NOT NULL,
    "title"      VARCHAR(200) NOT NULL,
    "body"       TEXT         NOT NULL DEFAULT '',
    "benefit"    TEXT         NOT NULL DEFAULT '',
    "fit"        TEXT         NOT NULL DEFAULT '',
    "score"      SMALLINT     NOT NULL DEFAULT 0,
    "source_url" TEXT         NOT NULL DEFAULT '',
    "item_id"    BIGINT       NULL,
    "status"     VARCHAR(16)  NOT NULL DEFAULT 'new',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS "nj_661_ideas_idea_hash_uidx" ON "nj_661_ideas" ("idea_hash");

CREATE TABLE IF NOT EXISTS "nj_661_reports" (
    "id"         BIGSERIAL PRIMARY KEY,
    "run_id"     BIGINT       NULL,
    "title"      VARCHAR(200) NOT NULL DEFAULT '',
    "summary"    TEXT         NOT NULL DEFAULT '',
    "highlights" TEXT         NOT NULL DEFAULT '',
    "sections"   TEXT         NOT NULL DEFAULT '',
    "stats"      TEXT         NOT NULL DEFAULT '',
    "emailed"    BOOLEAN      NOT NULL DEFAULT FALSE,
    "emailed_at" TIMESTAMPTZ  NULL,
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE "nj_661_runs" IS 'nj661_airadar: прогоны конвейера; trigger schedule|manual; status new|running|done|fail';
COMMENT ON TABLE "nj_661_items" IS 'nj661_airadar: найденные материалы; url_hash — дедуп (работу не делаем заново); status new|analyzed|skipped|noise';
COMMENT ON TABLE "nj_661_facts" IS 'nj661_airadar: база знаний — факты (не вода): fact_hash + embedding для семантического дедупа';
COMMENT ON TABLE "nj_661_ideas" IS 'nj661_airadar: идеи применения на n2j; status new|accepted|done|rejected';
COMMENT ON TABLE "nj_661_reports" IS 'nj661_airadar: отчёты конвейера и письма владельцу';

SQL;

		// run_script режет по «;» ВНЕ строковых литералов: в текстах COMMENT
		// ниже есть точки с запятой (упавший первый накат 06.10.2026 —
		// «незавершённая строка в кавычках» — был именно из-за explode).
		$this->run_script($sql);
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS "nj_661_reports" CASCADE;');
		$this->run_query('DROP TABLE IF EXISTS "nj_661_ideas" CASCADE;');
		$this->run_query('DROP TABLE IF EXISTS "nj_661_facts" CASCADE;');
		$this->run_query('DROP TABLE IF EXISTS "nj_661_items" CASCADE;');
		$this->run_query('DROP TABLE IF EXISTS "nj_661_runs" CASCADE;');
	}
}
