<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_Nj_530_001_Links_Table extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_530_links" (
    "id"         SERIAL PRIMARY KEY,
    "code"       VARCHAR(10) NOT NULL UNIQUE,
    "url"        VARCHAR(2048) NOT NULL,
    "host"       VARCHAR(255) DEFAULT NULL,
    "user_id"    INTEGER DEFAULT NULL,
    "clicks"     BIGINT NOT NULL DEFAULT 0,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_530_links_url_idx" ON "nj_530_links" ("url");
CREATE INDEX IF NOT EXISTS "nj_530_links_user_idx" ON "nj_530_links" ("user_id");
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_530_links" CASCADE;');
    }
}
