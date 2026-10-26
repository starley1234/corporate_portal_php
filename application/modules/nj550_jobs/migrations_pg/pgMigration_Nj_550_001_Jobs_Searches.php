<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-550/001: поисковые запросы вакансий и найденные позиции
 * («Работа 2 Оффер»: город + направление → вакансии с сайтов работы и компаний).
 */
class pgMigration_Nj_550_001_Jobs_Searches extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_550_searches" (
    "id"          SERIAL PRIMARY KEY,
    "code"        VARCHAR(16) NOT NULL UNIQUE,
    "visitor_id"  VARCHAR(64) NOT NULL,
    "user_id"     INTEGER NULL,
    "city"        VARCHAR(80) NOT NULL,
    "direction"   VARCHAR(200) NOT NULL,
    "status"      VARCHAR(12) NOT NULL DEFAULT 'new',
    "summary"     TEXT NULL,
    "meta"        JSONB NOT NULL DEFAULT '{}'::jsonb,
    "error"       TEXT NULL,
    "job_id"      BIGINT NULL,
    "client_ip"   VARCHAR(45) NOT NULL DEFAULT '',
    "created_at"  TIMESTAMPTZ NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ NULL,
    CONSTRAINT "nj_550_status_chk" CHECK ("status" IN ('new','done','error'))
);

CREATE INDEX IF NOT EXISTS "nj_550_visitor_idx" ON "nj_550_searches" ("visitor_id", "created_at" DESC);

CREATE TABLE IF NOT EXISTS "nj_550_results" (
    "id"         SERIAL PRIMARY KEY,
    "search_id"  BIGINT NOT NULL REFERENCES "nj_550_searches"("id") ON DELETE CASCADE,
    "title"      VARCHAR(300) NOT NULL,
    "company"    VARCHAR(200) NULL,
    "url"        TEXT NOT NULL,
    "url_hash"   VARCHAR(32) NOT NULL,
    "source"     VARCHAR(120) NULL,
    "snippet"    TEXT NULL,
    "relevance"  SMALLINT NOT NULL DEFAULT 0,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT "nj_550_search_url_uniq" UNIQUE ("search_id", "url_hash")
);

CREATE INDEX IF NOT EXISTS "nj_550_results_search_idx" ON "nj_550_results" ("search_id", "relevance" DESC);

COMMENT ON TABLE  "nj_550_searches" IS 'NJ-550 «Работа 2 Оффер»: поисковые запросы вакансий (город+направление)';
COMMENT ON TABLE  "nj_550_results"  IS 'NJ-550: найденные вакансии/страницы компаний (результат воркера nj550.jobs)';
COMMENT ON COLUMN "nj_550_searches"."visitor_id" IS 'Visitor::uid() — u:<user_id> или g:<ubid> (аноним)';
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_550_results" CASCADE;');
        $this->run_query('DROP TABLE IF EXISTS "nj_550_searches" CASCADE;');
    }
}
