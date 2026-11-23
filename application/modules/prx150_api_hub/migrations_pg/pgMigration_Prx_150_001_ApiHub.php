<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx150: ключи внешнего API и журнал использования.
 *
 * Ключ хранится хешем; полный ключ не восстанавливается. usage — одна строка
 * на запрос, чистится манифестом (90 дней). Индексы — по клиенту/времени,
 * scope/времени и статусу/времени: три привычных разреза мониторинга.
 */
class pgMigration_Prx_150_001_ApiHub extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS prx_150_api_clients (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    key_hash CHAR(64) NOT NULL UNIQUE,
    key_prefix VARCHAR(16) NOT NULL,
    scopes JSONB NOT NULL DEFAULT '[]'::jsonb,
    rate_limit_per_min INTEGER NOT NULL DEFAULT 60 CHECK (rate_limit_per_min > 0),
    status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'revoked')),
    expires_at TIMESTAMPTZ NULL,
    last_used_at TIMESTAMPTZ NULL,
    last_used_ip_hash VARCHAR(64) NULL,
    created_by BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    revoked_at TIMESTAMPTZ NULL,
    note VARCHAR(500) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS prx_150_api_clients_status ON prx_150_api_clients (status, id);
CREATE INDEX IF NOT EXISTS prx_150_api_clients_expiry ON prx_150_api_clients (expires_at);

CREATE TABLE IF NOT EXISTS prx_150_api_usage (
    id BIGSERIAL PRIMARY KEY,
    client_id BIGINT NOT NULL REFERENCES prx_150_api_clients (id) ON DELETE CASCADE,
    scope VARCHAR(64) NOT NULL,
    method VARCHAR(8) NOT NULL,
    path VARCHAR(512) NOT NULL,
    status INTEGER NOT NULL,
    duration_ms INTEGER NOT NULL DEFAULT 0,
    request_bytes BIGINT NOT NULL DEFAULT 0,
    response_bytes BIGINT NOT NULL DEFAULT 0,
    ip_hash VARCHAR(64) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS prx_150_api_usage_client
    ON prx_150_api_usage (client_id, created_at DESC);
CREATE INDEX IF NOT EXISTS prx_150_api_usage_scope
    ON prx_150_api_usage (scope, created_at DESC);
CREATE INDEX IF NOT EXISTS prx_150_api_usage_status
    ON prx_150_api_usage (status, created_at DESC);
SQL);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS prx_150_api_usage; DROP TABLE IF EXISTS prx_150_api_clients;');
    }
}
