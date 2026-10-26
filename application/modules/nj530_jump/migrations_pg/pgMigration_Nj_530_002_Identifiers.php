<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-530/002: идентификаторы в открытом виде (решение владельца, 09.2026).
 *
 * - creator_ip в nj_530_links — кто создал короткую ссылку;
 * - nj_530_clicks — журнал переходов (кто кликнул: IP + время).
 * Раньше хранился только счётчик кликов.
 */
class pgMigration_Nj_530_002_Identifiers extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
ALTER TABLE "nj_530_links" ADD COLUMN IF NOT EXISTS "creator_ip" VARCHAR(45) NOT NULL DEFAULT '';
COMMENT ON COLUMN "nj_530_links"."creator_ip" IS 'IP создателя ссылки (открыто, решение владельца 09.2026)';

CREATE TABLE IF NOT EXISTS "nj_530_clicks" (
    "id"         BIGSERIAL PRIMARY KEY,
    "link_id"    BIGINT NOT NULL REFERENCES "nj_530_links"("id") ON DELETE CASCADE,
    "ip"         VARCHAR(45) NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_530_clicks_link_idx"  ON "nj_530_clicks" ("link_id", "created_at" DESC);
CREATE INDEX IF NOT EXISTS "nj_530_clicks_ip_idx"    ON "nj_530_clicks" ("ip");
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_530_clicks" CASCADE;');
        $this->run_query('ALTER TABLE "nj_530_links" DROP COLUMN IF EXISTS "creator_ip";');
    }
}
