<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj609_watchdog 001: наблюдаемые контрагенты и события по ним.
 *
 * Раз в неделю воркер ищет по открытым источникам сигналы: суды/арбитражи,
 * банкротство, изменения ЕГРЮЛ, негативные новости. LLM фильтрует шум.
 */
class pgMigration_Nj_609_001_Watchdog_Tables extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_609_subjects" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NOT NULL,
    "company"     VARCHAR(300) NOT NULL DEFAULT '',
    "inn"         VARCHAR(20)  NOT NULL DEFAULT '',
    "note"        VARCHAR(300) NOT NULL DEFAULT '',
    "last_run_at" TIMESTAMPTZ  NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_609_subjects_user_idx" ON "nj_609_subjects" ("user_id", "id" DESC);
COMMENT ON TABLE "nj_609_subjects" IS 'nj609_watchdog: контрагенты под наблюдением';

CREATE TABLE IF NOT EXISTS "nj_609_events" (
    "id"         BIGSERIAL PRIMARY KEY,
    "subject_id" BIGINT      NOT NULL,
    "user_id"    BIGINT      NOT NULL,
    "ext_id"     VARCHAR(40) NOT NULL,
    "kind"       VARCHAR(30) NOT NULL DEFAULT '',
    "severity"   VARCHAR(10) NOT NULL DEFAULT 'info',
    "title"      VARCHAR(300) NOT NULL DEFAULT '',
    "url"        VARCHAR(500) NOT NULL DEFAULT '',
    "summary"    TEXT        NULL,
    "status"     VARCHAR(10) NOT NULL DEFAULT 'new',
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS "nj_609_events_uniq" ON "nj_609_events" ("subject_id", "ext_id");
CREATE INDEX IF NOT EXISTS "nj_609_events_user_idx" ON "nj_609_events" ("user_id", "id" DESC);
COMMENT ON TABLE "nj_609_events" IS 'nj609_watchdog: события; severity risk|warn|info; status new|seen';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_609_events"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_609_subjects"')->execute();
    }
}
