<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx395: владелец задания — целочисленный ключ prx150.
 *
 * Строковый `client_id` (ID из PRX395_DOCUMENT_EXTRACT_CLIENTS) уходит вместе с
 * этой картой: доступ теперь выдаёт prx150, а не env. Положительные ID —
 * ключи prx150, отрицательные — задания из админки (ID пользователя).
 *
 * База свежая, но миграция остаётся повторяемой: старые строки, если они
 * есть, получают служебного владельца 0.
 */
class pgMigration_Prx_395_002_KeyOwner extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
ALTER TABLE prx_395_extractions ADD COLUMN IF NOT EXISTS api_client_id BIGINT NULL;
UPDATE prx_395_extractions SET api_client_id = 0 WHERE api_client_id IS NULL;
ALTER TABLE prx_395_extractions ALTER COLUMN api_client_id SET NOT NULL;
ALTER TABLE prx_395_extractions DROP COLUMN IF EXISTS client_id;
CREATE UNIQUE INDEX IF NOT EXISTS prx_395_extractions_owner_key
    ON prx_395_extractions (api_client_id, request_key);
SQL);
    }

    public function down()
    {
        $this->run_query(<<<'SQL'
DROP INDEX IF EXISTS prx_395_extractions_owner_key;
ALTER TABLE prx_395_extractions DROP COLUMN IF EXISTS api_client_id;
SQL);
    }
}
