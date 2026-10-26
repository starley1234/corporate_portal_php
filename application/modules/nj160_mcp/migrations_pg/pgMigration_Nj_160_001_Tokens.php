<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj160_mcp 001: self-service токены MCP.
 *
 * В хранилище — только sha256-хэш токена (утечка БД не раскрывает ключи).
 * Пользователь создаёт токен на /mcp, ключ показывается один раз.
 */
class pgMigration_Nj_160_001_Tokens extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_160_tokens" (
    "id"          BIGSERIAL PRIMARY KEY,
    "token_hash"  VARCHAR(64)  NOT NULL UNIQUE,
    "label"       VARCHAR(120) NOT NULL DEFAULT '',
    "user_id"     BIGINT       NOT NULL,
    "calls"       BIGINT       NOT NULL DEFAULT 0,
    "last_call_at" TIMESTAMPTZ NULL,
    "revoked_at"  TIMESTAMPTZ  NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_160_tokens_user_idx" ON "nj_160_tokens" ("user_id", "id" DESC);
COMMENT ON TABLE "nj_160_tokens" IS 'nj160_mcp: персональные токены MCP (sha256, ключ в БД не хранится)';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_160_tokens"')->execute();
    }
}
