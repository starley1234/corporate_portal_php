<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj606_leads 001: потоки лидов и найденные компании.
 *
 * Поток = «кто мне нужен»: отрасль/что производят + регион.
 * Воркер ищет производителей по открытым источникам, LLM извлекает
 * компании (название, сайт, ИНН если виден, чем занимаются, зацепка) →
 * лиды попадают в /leads, откуда в один клик — профиль nj591 и письмо nj592.
 */
class pgMigration_Nj_606_001_Leads_Tables extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_606_flows" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NOT NULL,
    "title"       VARCHAR(120) NOT NULL DEFAULT '',
    "industry"    VARCHAR(300) NOT NULL DEFAULT '',
    "region"      VARCHAR(120) NOT NULL DEFAULT '',
    "last_run_at" TIMESTAMPTZ  NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_606_flows_user_idx" ON "nj_606_flows" ("user_id", "id" DESC);
COMMENT ON TABLE "nj_606_flows" IS 'nj606_leads: поток поиска компаний-заказчиков (industry+region)';

CREATE TABLE IF NOT EXISTS "nj_606_leads" (
    "id"         BIGSERIAL PRIMARY KEY,
    "flow_id"    BIGINT      NOT NULL,
    "user_id"    BIGINT      NOT NULL,
    "ext_id"     VARCHAR(40) NOT NULL,
    "company"    VARCHAR(300) NOT NULL DEFAULT '',
    "site"       VARCHAR(300) NOT NULL DEFAULT '',
    "inn"        VARCHAR(20) NOT NULL DEFAULT '',
    "what"       TEXT        NULL,
    "hook"       TEXT        NULL,
    "score"      INTEGER     NOT NULL DEFAULT 0,
    "status"     VARCHAR(12) NOT NULL DEFAULT 'new',
    "profile_code" VARCHAR(16) NOT NULL DEFAULT '',
    "letter_code"  VARCHAR(16) NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS "nj_606_leads_uniq" ON "nj_606_leads" ("flow_id", "ext_id");
CREATE INDEX IF NOT EXISTS "nj_606_leads_user_idx" ON "nj_606_leads" ("user_id", "id" DESC);
COMMENT ON TABLE "nj_606_leads" IS 'nj606_leads: найденные компании; status new|taken|gone; ext_id=sha1(сайт или название)';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_606_leads"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_606_flows"')->execute();
    }
}
