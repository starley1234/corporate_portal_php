<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Прогоны «Стенда эмбеддингов» (nj671_embedbench).
 *
 * Схема та же, что у VLM-сервисов платформы (шапка класса `Vision`): один
 * прогон = одна строка, результат лежит в jsonb, векторов в базе нет —
 * стенду они не нужны вовсе (он меряет косинусы и сразу их забывает).
 */
class pgMigration_Nj_671_001_Embedbench_Runs extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_671_runs" (
    id          BIGSERIAL PRIMARY KEY,
    user_id     INT,
    visitor     VARCHAR(64) NOT NULL DEFAULT '',
    client_ip   VARCHAR(64) NOT NULL DEFAULT '',
    job_id      BIGINT,
    status      VARCHAR(16) NOT NULL DEFAULT 'queued',
    title       VARCHAR(200) NOT NULL DEFAULT '',
    model       VARCHAR(160) NOT NULL DEFAULT '',
    elapsed_ms  INT NOT NULL DEFAULT 0,
    error       TEXT NOT NULL DEFAULT '',
    result      JSONB,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at TIMESTAMPTZ
);
SQL);

        $this->run_query('CREATE INDEX IF NOT EXISTS "nj_671_runs_created_idx"
                          ON "nj_671_runs" ("created_at" DESC);');
        $this->run_query('CREATE INDEX IF NOT EXISTS "nj_671_runs_status_idx"
                          ON "nj_671_runs" ("status");');
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_671_runs";');
    }
}
