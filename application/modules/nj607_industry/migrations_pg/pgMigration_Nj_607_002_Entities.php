<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj607_industry 002: СУЩНОСТИ отчёта и их связи с другими модулями.
 *
 * Зачем (владелец, 26.09.2026): «отчёт хороший — его оставь, но и сделай из него
 * сущности, которые надо будет связать с другими модулями. Наша цель — связанная
 * онтология».
 *
 * Отчёт остаётся текстом (Markdown) как был; из него ДОПОЛНИТЕЛЬНО извлекаются
 * сущности — организации-производители, регионы, сегменты/продукты — и
 * складываются в онтологию модуля:
 *
 *   nj_607_entities      — сущность (kind + slug уникальны: «одна компания — одна
 *                          запись», сколько бы отчётов её ни упомянули);
 *   nj_607_entities      .report_id — первый отчёт, где сущность встретилась
 *                          (остальные связи видны через этот же slug);
 *   nj_607_links         — связь сущности с ОБЪЕКТОМ другого модуля, найденным в
 *                          нашей же базе (профиль компании /company/<code>,
 *                          организация /orgs/<ogrn>, досье /docs/<code> и т.д.).
 *
 * Ни одна связь не выдумывается: в links попадает только то, что реально нашлось
 * в базе (совпадение по домену, названию или запросу).
 */
class pgMigration_Nj_607_002_Entities extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_607_entities" (
    "id"         BIGSERIAL PRIMARY KEY,
    "report_id"  BIGINT       NULL,
    "kind"       VARCHAR(16)  NOT NULL DEFAULT 'company',
    "slug"       VARCHAR(160) NOT NULL,
    "title"      VARCHAR(300) NOT NULL DEFAULT '',
    "url"        VARCHAR(500) NOT NULL DEFAULT '',
    "domain"     VARCHAR(120) NOT NULL DEFAULT '',
    "region"     VARCHAR(120) NOT NULL DEFAULT '',
    "inn"        VARCHAR(12)  NOT NULL DEFAULT '',
    "ogrn"       VARCHAR(15)  NOT NULL DEFAULT '',
    "details"    TEXT         NULL,
    "meta"       JSONB        NULL,
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS "nj_607_entities_uniq" ON "nj_607_entities" ("kind", "slug");
CREATE INDEX IF NOT EXISTS "nj_607_entities_report_idx" ON "nj_607_entities" ("report_id", "kind", "id");
COMMENT ON TABLE "nj_607_entities" IS 'nj607_industry: сущности карты отрасли (company|region|segment); slug — ключ онтологии';

CREATE TABLE IF NOT EXISTS "nj_607_links" (
    "id"          BIGSERIAL PRIMARY KEY,
    "entity_id"   BIGINT       NOT NULL,
    "module"      VARCHAR(24)  NOT NULL DEFAULT '',
    "target_code" VARCHAR(64)  NOT NULL DEFAULT '',
    "target_url"  VARCHAR(500) NOT NULL DEFAULT '',
    "label"       VARCHAR(200) NOT NULL DEFAULT '',
    "score"       INTEGER      NOT NULL DEFAULT 0,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS "nj_607_links_uniq"
    ON "nj_607_links" ("entity_id", "module", "target_code", "target_url");
CREATE INDEX IF NOT EXISTS "nj_607_links_entity_idx" ON "nj_607_links" ("entity_id", "score" DESC, "id");
COMMENT ON TABLE "nj_607_links" IS 'nj607_industry: связи сущностей с объектами других модулей (только реально найденное в базе)';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_607_links"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_607_entities"')->execute();
    }
}
