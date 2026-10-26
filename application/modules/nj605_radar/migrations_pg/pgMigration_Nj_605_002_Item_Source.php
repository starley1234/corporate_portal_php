<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj605_radar 002: колонка источника находки (с какой площадки пришла).
 * source — host площадки (zakupki.gov.ru, rostender.info, b2b-center.ru…),
 * заполняет воркер; пусто — источник неизвестен/поисковая выдача напрямую.
 */
class pgMigration_Nj_605_002_Item_Source extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
ALTER TABLE "nj_605_items" ADD COLUMN IF NOT EXISTS "source" VARCHAR(40) NOT NULL DEFAULT '';
COMMENT ON COLUMN "nj_605_items"."source" IS 'host площадки-источника находки (напр. zakupki.gov.ru)';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'ALTER TABLE "nj_605_items" DROP COLUMN IF EXISTS "source"')->execute();
    }
}
