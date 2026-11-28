<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx103_platform: журнал действий администратора (фасад Audit::log).
 *
 * Только добавление: UPDATE запрещён триггером — запись журнала не правят
 * задним числом. DELETE разрешён только чистке по сроку хранения (манифест
 * prx103 `cleanup`, 365 дней). Секреты в data не попадают — их вырезает
 * Service_Prx103_Audit до записи.
 */
class pgMigration_Prx_103_002_AuditLog extends pgMigration
{
    public function up()
    {
        $this->run_query("
            CREATE TABLE IF NOT EXISTS prx_103_audit_log (
                id         BIGSERIAL PRIMARY KEY,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                actor_id   BIGINT NOT NULL DEFAULT 0,
                actor      VARCHAR(64) NOT NULL DEFAULT '',
                category   VARCHAR(64) NOT NULL,
                action     VARCHAR(64) NOT NULL,
                object_id  VARCHAR(128) NULL,
                data       JSONB NOT NULL DEFAULT '{}'::jsonb,
                ip_hash    VARCHAR(64) NULL
            )
        ");
        $this->run_query('CREATE INDEX IF NOT EXISTS prx_103_audit_log_time ON prx_103_audit_log (created_at DESC)');
        $this->run_query('CREATE INDEX IF NOT EXISTS prx_103_audit_log_category ON prx_103_audit_log (category, created_at DESC)');
        $this->run_query('CREATE INDEX IF NOT EXISTS prx_103_audit_log_actor ON prx_103_audit_log (actor_id, created_at DESC)');
        $this->run_query("
            CREATE OR REPLACE FUNCTION prx_103_audit_log_immutable() RETURNS trigger AS \$fn\$
            BEGIN
                RAISE EXCEPTION 'prx_103_audit_log: записи журнала не изменяются';
            END
            \$fn\$ LANGUAGE plpgsql
        ");
        $this->run_query('DROP TRIGGER IF EXISTS prx_103_audit_log_no_update ON prx_103_audit_log');
        $this->run_query('CREATE TRIGGER prx_103_audit_log_no_update BEFORE UPDATE ON prx_103_audit_log
            FOR EACH ROW EXECUTE FUNCTION prx_103_audit_log_immutable()');
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS prx_103_audit_log');
        $this->run_query('DROP FUNCTION IF EXISTS prx_103_audit_log_immutable()');
    }
}
