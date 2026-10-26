<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_Nj_520_001_Polls_Tables extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_520_polls" (
    "id"         SERIAL PRIMARY KEY,
    "code"       VARCHAR(8) NOT NULL UNIQUE,
    "title"      VARCHAR(300) NOT NULL,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "nj_520_options" (
    "id"      SERIAL PRIMARY KEY,
    "poll_id" INTEGER NOT NULL REFERENCES "nj_520_polls"("id") ON DELETE CASCADE,
    "label"   VARCHAR(200) NOT NULL,
    "pos"     SMALLINT NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS "nj_520_options_poll_idx" ON "nj_520_options" ("poll_id");

CREATE TABLE IF NOT EXISTS "nj_520_votes" (
    "id"         SERIAL PRIMARY KEY,
    "poll_id"    INTEGER NOT NULL REFERENCES "nj_520_polls"("id") ON DELETE CASCADE,
    "option_id"  INTEGER NOT NULL REFERENCES "nj_520_options"("id") ON DELETE CASCADE,
    "voter_hash" CHAR(64) NOT NULL,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE ("poll_id", "voter_hash")
);
CREATE INDEX IF NOT EXISTS "nj_520_votes_option_idx" ON "nj_520_votes" ("option_id");
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_520_votes", "nj_520_options", "nj_520_polls" CASCADE;');
    }
}
