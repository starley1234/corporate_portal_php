<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx150: организация ключа (nullable).
 *
 * FK на prx_300_organizations не ставим — модуль не читает чужие таблицы.
 * Храним скаляр, а при выдаче ключа проверяем существование через публичный
 * сервис prx300 (если модуль подключён).
 */
class pgMigration_Prx_150_002_KeyOrganization extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
ALTER TABLE prx_150_api_clients ADD COLUMN IF NOT EXISTS organization_id BIGINT NULL;
CREATE INDEX IF NOT EXISTS prx_150_api_clients_org ON prx_150_api_clients (organization_id);
SQL);
    }

    public function down()
    {
        $this->run_query(<<<'SQL'
DROP INDEX IF EXISTS prx_150_api_clients_org;
ALTER TABLE prx_150_api_clients DROP COLUMN IF EXISTS organization_id;
SQL);
    }
}
