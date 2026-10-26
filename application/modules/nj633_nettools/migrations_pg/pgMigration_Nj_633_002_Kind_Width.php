<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj633_nettools 002: расширить kind для видов проверок.
 *
 * В 001 колонка nj_633_checks.kind была VARCHAR(16); набор видов расширился
 * (добавлены конвертеры ipconv, base64 — до 24 символов). Идемпотентно:
 * alter через DO-блок, применяется только если ширина меньше.
 */
class pgMigration_Nj_633_002_Kind_Width extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_name = 'nj_633_checks' AND column_name = 'kind'
           AND character_maximum_length < 24
    ) THEN
        ALTER TABLE "nj_633_checks" ALTER COLUMN "kind" TYPE VARCHAR(24);
    END IF;
END$$;
SQL
        )->execute();
    }

    public function down()
    {
        // осознанно не сужаем обратно: данные шире 16 могут не влезть
    }
}
