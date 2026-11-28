<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRX-150/005: перевыпуск ключа без простоя.
 *
 * Новый секрет пишется в key_hash того же клиента (права, лимиты и журнал
 * остаются), прежний — в prev_key_hash и действует до prev_valid_until:
 * потребитель успевает заменить ключ. Без льготного срока prev_* пусты.
 */
class pgMigration_Prx_150_005_KeyRotation extends pgMigration
{
    public function up()
    {
        $this->run_query("ALTER TABLE prx_150_api_clients
            ADD COLUMN IF NOT EXISTS prev_key_hash CHAR(64) NULL,
            ADD COLUMN IF NOT EXISTS prev_key_prefix VARCHAR(16) NULL,
            ADD COLUMN IF NOT EXISTS prev_valid_until TIMESTAMPTZ NULL,
            ADD COLUMN IF NOT EXISTS rotated_at TIMESTAMPTZ NULL,
            ADD COLUMN IF NOT EXISTS rotations INTEGER NOT NULL DEFAULT 0");
        $this->run_query("CREATE UNIQUE INDEX IF NOT EXISTS prx_150_api_clients_prev_hash
            ON prx_150_api_clients (prev_key_hash) WHERE prev_key_hash IS NOT NULL");
    }

    public function down()
    {
        $this->run_query('DROP INDEX IF EXISTS prx_150_api_clients_prev_hash');
        $this->run_query("ALTER TABLE prx_150_api_clients
            DROP COLUMN IF EXISTS prev_key_hash, DROP COLUMN IF EXISTS prev_key_prefix,
            DROP COLUMN IF EXISTS prev_valid_until, DROP COLUMN IF EXISTS rotated_at,
            DROP COLUMN IF EXISTS rotations");
    }
}
