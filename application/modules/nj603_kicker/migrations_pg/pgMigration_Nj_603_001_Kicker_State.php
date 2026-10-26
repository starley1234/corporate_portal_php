<?php defined('SYSPATH') or die('No direct script access.');

/** Состояние расписаний nj603; без DDL в горячем HTTP-пути. */
class pgMigration_Nj_603_001_Kicker_State extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_603_kicks" (
    "name" VARCHAR(60) PRIMARY KEY,
    "last_run_at" TIMESTAMPTZ NULL,
    "last_status" VARCHAR(24) NOT NULL DEFAULT '',
    "last_error" TEXT NULL,
    "last_job_id" BIGINT NULL,
    "runs" BIGINT NOT NULL DEFAULT 0,
    "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
SQL);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_603_kicks" CASCADE;');
    }
}
