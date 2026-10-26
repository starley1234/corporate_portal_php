<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-593/003: «Тренды GitHub» — публикация обзора.
 *
 * Владелец (08.10.2026): «каталог опубликованных обзоров для людей и СЕО;
 * обзор публикуется по умолчанию, но я могу снять его с публикации».
 * Поэтому у обзора появляется флаг публикации:
 *
 *   published = TRUE  — обзор виден в открытом каталоге /trends/catalog
 *                       (новые обзоры создаются именно такими: DEFAULT TRUE);
 *   published = FALSE — обзор остаётся у владельца (страница /trends/<code>
 *                       работает для владельца и администратора), но из каталога
 *                       и из карты сайта исчезает.
 *
 * Идемпотентно: ALTER … IF NOT EXISTS и CREATE INDEX IF NOT EXISTS — повторный
 * накат на прод безвреден (правило проекта, см. php_migration_lint).
 */
class pgMigration_Nj_593_003_Published extends pgMigration
{
    public function up()
    {
        // Сценарий из нескольких операторов — через run_script(): он разрезает
        // его по «;» с учётом кавычек (см. nj111_migrations), а не вслепую.
        $this->run_script(<<<SQL
ALTER TABLE "nj_593_reviews" ADD COLUMN IF NOT EXISTS "published" BOOLEAN NOT NULL DEFAULT TRUE;
COMMENT ON COLUMN "nj_593_reviews"."published" IS 'Обзор опубликован: виден в открытом каталоге /trends/catalog (снять и вернуть может владелец или администратор)';
CREATE INDEX IF NOT EXISTS "nj_593_reviews_published_idx" ON "nj_593_reviews" ("published", "rdate" DESC, "id" DESC);
SQL
        );
    }

    public function down()
    {
        $this->run_script(<<<SQL
DROP INDEX IF EXISTS "nj_593_reviews_published_idx";
ALTER TABLE "nj_593_reviews" DROP COLUMN IF EXISTS "published";
SQL
        );
    }
}
