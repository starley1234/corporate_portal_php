<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj641_imagegen 002: модерация и библиотека изображений.
 *
 * Владелец (26.09): «нет страницы с сгенерированными изображениями для
 * модерации и публикации». «Публикация» здесь = перевод картинки в библиотеку,
 * откуда её переиспользуют другие сервисы (статьи, презентации):
 *
 *   review_status = 'pending'  — на модерации (по умолчанию, ничего не публикуем);
 *   review_status = 'approved' — в библиотеке (доступна сервисам через library_pick());
 *   review_status = 'rejected' — отклонено (в библиотеке не появляется).
 *
 * Колонки добавляются к уже существующей таблице прогонов: старые строки
 * получают 'pending' и не ломают ни историю, ни выдачу результата.
 */
class pgMigration_Nj_641_002_Review extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
ALTER TABLE "nj_641_runs"
    ADD COLUMN IF NOT EXISTS "review_status" VARCHAR(12) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS "reviewed_at"   TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS "reviewed_by"   VARCHAR(40) NOT NULL DEFAULT '';

CREATE INDEX IF NOT EXISTS "nj_641_runs_review_idx" ON "nj_641_runs" ("review_status", "id" DESC);

COMMENT ON COLUMN "nj_641_runs"."review_status" IS 'pending — на модерации, approved — в библиотеке (доступна сервисам), rejected — отклонено';
COMMENT ON COLUMN "nj_641_runs"."reviewed_by" IS 'кто модерировал (visitor_uid администратора)';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, <<<SQL
ALTER TABLE "nj_641_runs"
    DROP COLUMN IF EXISTS "review_status",
    DROP COLUMN IF EXISTS "reviewed_at",
    DROP COLUMN IF EXISTS "reviewed_by";
SQL
        )->execute();
    }
}
