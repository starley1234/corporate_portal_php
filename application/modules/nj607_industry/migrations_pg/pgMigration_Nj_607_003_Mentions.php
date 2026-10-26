<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj607_industry 003: УПОМИНАНИЯ сущностей в отчётах.
 *
 * Зачем (владелец, 26.09.2026): онтология — ОБЩАЯ: одна компания хранится одной
 * записью `nj_607_entities` (kind + slug), сколько бы отчётов её ни упомянули.
 * Но страница отчёта показывала сущности по полю `entities.report_id` — то есть
 * только те, что ВПЕРВЫЕ появились в этом отчёте. Отчёт, который упоминает уже
 * известные компании, оставался без блока «Сущности отчёта» — со стороны это
 * выглядело как «онтология пропала».
 *
 * Решение: отдельная таблица упоминаний (отчёт × сущность). Она не меняет
 * хранение сущностей и связей; старые данные переносятся одним INSERT ... SELECT
 * по уже накопленному `entities.report_id`.
 */
class pgMigration_Nj_607_003_Mentions extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_607_mentions" (
    "id"         BIGSERIAL PRIMARY KEY,
    "entity_id"  BIGINT      NOT NULL,
    "report_id"  BIGINT      NOT NULL,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_607_entities_slug_idx" ON "nj_607_entities" ("slug");
CREATE UNIQUE INDEX IF NOT EXISTS "nj_607_mentions_uniq" ON "nj_607_mentions" ("entity_id", "report_id");
CREATE INDEX IF NOT EXISTS "nj_607_mentions_report_idx" ON "nj_607_mentions" ("report_id", "entity_id");
COMMENT ON TABLE "nj_607_mentions" IS 'nj607_industry: в каких отчётах встречается сущность (онтология общая, отчёт ссылается на неё)';

INSERT INTO "nj_607_mentions" ("entity_id", "report_id")
SELECT "id", "report_id" FROM "nj_607_entities" WHERE "report_id" IS NOT NULL
ON CONFLICT ("entity_id", "report_id") DO NOTHING;
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_607_mentions"')->execute();
    }
}
