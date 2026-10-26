<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Прогоны распознавания растений (nj652_plant).
 *
 * Схема общая для всех VLM-сервисов платформы (см. шапку класса `Vision`):
 * сам кадр в базе НЕ хранится — он лежит файлом в приватном каталоге и
 * удаляется сразу после приёмки результата. В jsonb остаётся только разбор:
 * позиции, суммы и пометки модели.
 */
class pgMigration_Nj_652_001_Plant_Runs extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_652_runs" (
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

        $this->run_query('CREATE INDEX IF NOT EXISTS "nj_652_runs_visitor_idx"
                          ON "nj_652_runs" ("visitor", "id" DESC);');
        $this->run_query('CREATE INDEX IF NOT EXISTS "nj_652_runs_created_idx"
                          ON "nj_652_runs" ("created_at");');
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_652_runs";');
    }
}
