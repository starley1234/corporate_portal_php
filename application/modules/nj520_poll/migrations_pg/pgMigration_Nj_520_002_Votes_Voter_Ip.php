<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-520/002: хранить IP голосующего в открытом виде.
 *
 * Было: voter_hash = sha256(salt|poll_id|ip|sha1(ua)) — приватно, но непрозрачно.
 * Стало: voter_ip VARCHAR(45) (IPv4/IPv6), UNIQUE(poll_id, voter_ip).
 * Решение владельца от 09.2026: приватность IP не требуется.
 */
class pgMigration_Nj_520_002_Votes_Voter_Ip extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
ALTER TABLE "nj_520_votes" DROP CONSTRAINT IF EXISTS "nj_520_votes_poll_id_voter_hash_key";
ALTER TABLE "nj_520_votes" DROP COLUMN IF EXISTS "voter_hash";

ALTER TABLE "nj_520_votes" ADD COLUMN IF NOT EXISTS "voter_ip" VARCHAR(45) NOT NULL DEFAULT '';
CREATE UNIQUE INDEX IF NOT EXISTS "nj_520_votes_poll_ip_uidx" ON "nj_520_votes" ("poll_id", "voter_ip");
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        // при откате восстановить уникальность: старые hash'и не восстановить,
        // пустые заполняем md5(id) — лишь бы UNIQUE не спорил
        $sql = <<<SQL
DROP INDEX IF EXISTS "nj_520_votes_poll_ip_uidx";
ALTER TABLE "nj_520_votes" DROP COLUMN IF EXISTS "voter_ip";

ALTER TABLE "nj_520_votes" ADD COLUMN IF NOT EXISTS "voter_hash" CHAR(64) NOT NULL DEFAULT '';
UPDATE "nj_520_votes" SET "voter_hash" = md5('nj520:'||"id") WHERE "voter_hash" = '';
ALTER TABLE "nj_520_votes" ADD CONSTRAINT "nj_520_votes_poll_id_voter_hash_key" UNIQUE ("poll_id", "voter_hash");
SQL;

        $this->run_query($sql);
    }
}
