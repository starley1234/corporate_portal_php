<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-520/004: один голос — с одного БРАУЗЕРА, а не с одного IP.
 *
 * Было: UNIQUE(poll_id, voter_ip). Целый офис, общежитие или мобильный
 * оператор за одним NAT голосовали один раз на всех, а вкладка «инкогнито»
 * с того же адреса голос всё равно не отдавала бы второй раз — то есть
 * защита мешала честным и не мешала никому всерьёз.
 *
 * Стало: voter_key VARCHAR(64) — ключ посетителя платформы (`Visitor::uid()`:
 * `u:<id>` у залогиненных, `g:<ubid>` у анонимов) или `ip:<адрес>`, если куку
 * поставить не удалось. UNIQUE(poll_id, voter_key).
 *
 * Прежние голоса переносим как `ip:<адрес>` — их уникальность сохраняется,
 * а счётчики не меняются. Колонка voter_ip остаётся для истории (новые
 * голоса её не заполняют): удалять данные миграцией нельзя.
 *
 * Решение владельца от 04.10.2026.
 */
class pgMigration_Nj_520_004_Votes_Voter_Key extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
ALTER TABLE "nj_520_votes" ADD COLUMN IF NOT EXISTS "voter_key" VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE "nj_520_votes" ALTER COLUMN "voter_ip" DROP NOT NULL;
ALTER TABLE "nj_520_votes" ALTER COLUMN "voter_ip" SET DEFAULT '';

UPDATE "nj_520_votes"
   SET "voter_key" = 'ip:'||COALESCE(NULLIF("voter_ip", ''), 'unknown-'||"id")
 WHERE "voter_key" = '';

DROP INDEX IF EXISTS "nj_520_votes_poll_ip_uidx";
CREATE UNIQUE INDEX IF NOT EXISTS "nj_520_votes_poll_voter_uidx" ON "nj_520_votes" ("poll_id", "voter_key");
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        // Откат: уникальность снова по IP. У голосов, пришедших от браузеров,
        // адреса нет — чтобы UNIQUE не спорил, подставляем служебное значение.
        $sql = <<<SQL
DROP INDEX IF EXISTS "nj_520_votes_poll_voter_uidx";
UPDATE "nj_520_votes" SET "voter_ip" = 'key-'||"id" WHERE COALESCE("voter_ip", '') = '';
ALTER TABLE "nj_520_votes" ALTER COLUMN "voter_ip" SET NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS "nj_520_votes_poll_ip_uidx" ON "nj_520_votes" ("poll_id", "voter_ip");
ALTER TABLE "nj_520_votes" DROP COLUMN IF EXISTS "voter_key";
SQL;

        $this->run_query($sql);
    }
}
