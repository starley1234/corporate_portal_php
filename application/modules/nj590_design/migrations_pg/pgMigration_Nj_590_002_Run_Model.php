<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-590/002: колонка model в nj_590_runs (выбор модели в форме /design).
 *
 * Отдельной миграцией потому, что колонку успели вписать в УЖЕ применённую
 * 001: ранер nj111 помнит применённые по имени класса и правки их содержимого
 * не перечитывает — на проде колонки не было, и INSERT в create падал
 * («Не удалось создать прогон»). ПРАВИЛО: применённые миграции не редактируются —
 * любое изменение схемы только новым файлом 00X.
 */
class pgMigration_Nj_590_002_Run_Model extends pgMigration
{
    public function up()
    {
        DB::query(NULL, 'ALTER TABLE "nj_590_runs" ADD COLUMN IF NOT EXISTS "model" VARCHAR(120) NULL')->execute();
        DB::query(NULL, 'COMMENT ON COLUMN "nj_590_runs"."model" IS \'Модель генерации вариантов (админский выбор формы или env воркера)\'')->execute();
    }

    public function down()
    {
        DB::query(NULL, 'ALTER TABLE "nj_590_runs" DROP COLUMN IF EXISTS "model"')->execute();
    }
}
