<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj634_pdf 001: журнал прогонов «PDF-инструментов».
 *
 * nj_634_runs — история операций (info/text/txt2pdf/split/merge): имя файла,
 * страницы/символы/размер, статус, сводка (JSONB), извлечённый текст (для
 * «копировать/скачать», обрезан лимитом NJ634_MAX_TEXT_CHARS). Счётчик rate
 * limit считается по этой же таблице (client_ip).
 */
class pgMigration_Nj_634_001_Runs extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_634_runs" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "client_ip"   VARCHAR(64)  NOT NULL DEFAULT '',
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "kind"        VARCHAR(20)  NOT NULL DEFAULT 'info',
    "name"        VARCHAR(255) NOT NULL DEFAULT '',
    "pages"       INTEGER      NULL,
    "chars"       INTEGER      NULL,
    "size"        INTEGER      NULL,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'ok',
    "result"      JSONB        NULL,
    "text"        TEXT         NULL,
    "error"       TEXT         NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_634_runs_ip_idx"  ON "nj_634_runs" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_634_runs_uid_idx" ON "nj_634_runs" ("visitor_uid", "id" DESC);
COMMENT ON TABLE "nj_634_runs" IS 'NJ-634 история операций PDF-инструментов (info|text|txt2pdf|split|merge); result JSONB — сводка, text — извлечённый текст';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_634_runs"')->execute();
    }
}
