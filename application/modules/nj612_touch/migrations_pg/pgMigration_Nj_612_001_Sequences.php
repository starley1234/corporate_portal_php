<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj612_touch 001: серии касаний (follow-up письма).
 *
 * sequences — сама серия (компания + контакт + оффер), steps — письма
 * по шагам. Остановка при ответе: контакт найден среди разобранных
 * входящих nj610 (received_at позже отправки первого шага).
 */
class pgMigration_Nj_612_001_Sequences extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_612_sequences" (
    "id"            BIGSERIAL PRIMARY KEY,
    "user_id"       BIGINT NOT NULL,
    "code"          VARCHAR(24) NOT NULL UNIQUE,
    "company"       VARCHAR(300) NOT NULL DEFAULT '',
    "contact_email" VARCHAR(200) NOT NULL DEFAULT '',
    "contact_name"  VARCHAR(200) NOT NULL DEFAULT '',
    "offer"         TEXT NULL,
    "tone"          VARCHAR(20) NOT NULL DEFAULT 'business',
    "steps_total"   INTEGER NOT NULL DEFAULT 3,
    "status"        VARCHAR(12) NOT NULL DEFAULT 'active',
    "current_step"  INTEGER NOT NULL DEFAULT 0,
    "next_run_at"   TIMESTAMPTZ NULL,
    "answered_at"   TIMESTAMPTZ NULL,
    "created_at"    TIMESTAMPTZ NOT NULL DEFAULT now(),
    "updated_at"    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_612_sequences_user_idx" ON "nj_612_sequences" ("user_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_612_sequences_tick_idx" ON "nj_612_sequences" ("status", "next_run_at");

CREATE TABLE IF NOT EXISTS "nj_612_steps" (
    "id"          BIGSERIAL PRIMARY KEY,
    "sequence_id" BIGINT NOT NULL,
    "seq"         INTEGER NOT NULL,
    "subject"     VARCHAR(500) NOT NULL DEFAULT '',
    "body"        TEXT NULL,
    "status"      VARCHAR(12) NOT NULL DEFAULT 'drafting',
    "sent_at"     TIMESTAMPTZ NULL,
    "error"       TEXT NULL,
    "created_at"  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_612_steps_seq_idx" ON "nj_612_steps" ("sequence_id", "seq");

COMMENT ON TABLE "nj_612_sequences" IS 'nj612_touch: серии касаний; status active|paused|done|stopped|answered';
COMMENT ON TABLE "nj_612_steps" IS 'nj612_touch: письма шагов; status drafting|ready|sent|skipped';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_612_steps"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_612_sequences"')->execute();
    }
}
