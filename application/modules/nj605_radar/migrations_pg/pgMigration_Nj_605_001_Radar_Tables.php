<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj605_radar 001: наблюдения (watches) и найденные заказы (items).
 *
 * Радар = «что ищу»: текст запроса + регион + ключевые слова.
 * Пиналка nj603 раз в 30 мин дёргает /radar/api/collect → задачи в nj120;
 * воркер ищет по открытым источникам, скорит LLM и возвращает items.
 */
class pgMigration_Nj_605_001_Radar_Tables extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_605_watches" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NOT NULL,
    "title"       VARCHAR(120) NOT NULL DEFAULT '',
    "query"       VARCHAR(300) NOT NULL DEFAULT '',
    "region"      VARCHAR(120) NOT NULL DEFAULT '',
    "last_run_at" TIMESTAMPTZ  NULL,
    "enabled"     BOOLEAN      NOT NULL DEFAULT TRUE,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_605_watches_user_idx" ON "nj_605_watches" ("user_id", "id" DESC);
COMMENT ON TABLE "nj_605_watches" IS 'nj605_radar: наблюдение за заказами (что, где); enabled=FALSE — не собирать';

CREATE TABLE IF NOT EXISTS "nj_605_items" (
    "id"           BIGSERIAL PRIMARY KEY,
    "watch_id"     BIGINT      NOT NULL,
    "user_id"      BIGINT      NOT NULL,
    "ext_id"       VARCHAR(40) NOT NULL,
    "title"        VARCHAR(300) NOT NULL DEFAULT '',
    "url"          VARCHAR(500) NOT NULL DEFAULT '',
    "customer"     VARCHAR(300) NOT NULL DEFAULT '',
    "price"        VARCHAR(60) NOT NULL DEFAULT '',
    "published_at" VARCHAR(40) NOT NULL DEFAULT '',
    "score"        INTEGER     NOT NULL DEFAULT 0,
    "why"          TEXT        NULL,
    "status"       VARCHAR(12) NOT NULL DEFAULT 'new',
    "created_at"   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS "nj_605_items_uniq" ON "nj_605_items" ("watch_id", "ext_id");
CREATE INDEX IF NOT EXISTS "nj_605_items_user_idx" ON "nj_605_items" ("user_id", "id" DESC);
COMMENT ON TABLE "nj_605_items" IS 'nj605_radar: найденные заказы; status new|taken|dismissed; ext_id=sha1(url)';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_605_items"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_605_watches"')->execute();
    }
}
