<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Платформенные идемпотентные ключи внешних операций (Prx_Idempotency).
 *
 * Один ключ на (scope, владелец, ключ); тело не хранится — только sha256.
 * `result` заполняется в finish(); до этого строка держит блокировку.
 * Чистка по `expires_at` объявлена в манифесте prx100_base.
 */
class pgMigration_Prx_110_005_Idempotency extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS prx_idempotency_keys (
    id BIGSERIAL PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    owner_key VARCHAR(128) NOT NULL,
    idem_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    result JSONB NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'done')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at TIMESTAMPTZ NOT NULL,
    UNIQUE (scope, owner_key, idem_key)
);
CREATE INDEX IF NOT EXISTS prx_idempotency_keys_expiry ON prx_idempotency_keys (expires_at);
SQL);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS prx_idempotency_keys;');
    }
}
