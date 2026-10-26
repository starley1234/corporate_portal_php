<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj610_inbox 001: разобранные входящие.
 * message_id — Message-ID из IMAP (дедуп); draft — черновик ответа LLM.
 */
class pgMigration_Nj_610_001_Messages extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_610_messages" (
    "id"         BIGSERIAL PRIMARY KEY,
    "message_id" VARCHAR(250) NOT NULL UNIQUE,
    "from_addr"  VARCHAR(300) NOT NULL DEFAULT '',
    "subject"    VARCHAR(500) NOT NULL DEFAULT '',
    "body"       TEXT         NULL,
    "received_at" TIMESTAMPTZ NULL,
    "category"   VARCHAR(40)  NOT NULL DEFAULT '',
    "priority"   VARCHAR(10)  NOT NULL DEFAULT 'normal',
    "draft"      TEXT         NULL,
    "status"     VARCHAR(12)  NOT NULL DEFAULT 'new',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_610_messages_status_idx" ON "nj_610_messages" ("status", "id" DESC);
COMMENT ON TABLE "nj_610_messages" IS 'nj610_inbox: входящие; category от LLM; status new|drafted|done|ignored';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_610_messages"')->execute();
    }
}
