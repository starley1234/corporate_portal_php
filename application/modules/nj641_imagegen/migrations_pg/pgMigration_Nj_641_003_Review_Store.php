<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj641_imagegen 003: отдельное хранилище модерации изображений.
 *
 * Миграция 002 добавляла колонки review_status/reviewed_at/reviewed_by прямо в
 * nj_641_runs. На проде (26.09) их не появилось: ADD COLUMN требует прав
 * владельца таблицы, а у пользователя БД их не было — запрос списка падал, и
 * страница «Изображения» показывала «ничего нет» при живых сгенерированных
 * картинках.
 *
 * Поэтому статус модерации живёт в ОТДЕЛЬНОЙ таблице nj_641_review: её
 * приложение создаёт само (CREATE TABLE доступен шире, чем ALTER чужой
 * таблицы — этим же путём живут таблицы остальных модулей), а миграция 003
 * остаётся штатным путём для тех, кто применяет миграции.
 *
 * Колонки из 002 остаются в схеме как есть — код на них больше не опирается.
 */
class pgMigration_Nj_641_003_Review_Store extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_641_review" (
    "run_id"      BIGINT       PRIMARY KEY,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'pending',
    "reviewed_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "reviewed_by" VARCHAR(40)  NOT NULL DEFAULT ''
)
SQL
        )->execute();

        DB::query(NULL,
            'CREATE INDEX IF NOT EXISTS "nj_641_review_status_idx" ON "nj_641_review" ("status", "run_id" DESC)'
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_641_review"')->execute();
    }
}
