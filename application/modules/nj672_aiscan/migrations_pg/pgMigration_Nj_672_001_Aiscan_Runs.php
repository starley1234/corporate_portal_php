<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Прогоны «Сканера безопасности ИИ-моделей» (nj672_aiscan).
 *
 * Схема та же, что у стендов платформы (класс `Vision`): один аудит = одна
 * строка, весь отчёт (находки, матрица OWASP, стресс-профиль) — в jsonb.
 * Ключ цели в БД не пишется никогда: он живёт только в задаче очереди и в
 * памяти воркера (контракт модуля, держит тест воркера).
 */
class pgMigration_Nj_672_001_Aiscan_Runs extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_672_runs" (
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

        $this->run_query('CREATE INDEX IF NOT EXISTS "nj_672_runs_created_idx"
                          ON "nj_672_runs" ("created_at" DESC);');
        $this->run_query('CREATE INDEX IF NOT EXISTS "nj_672_runs_status_idx"
                          ON "nj_672_runs" ("status");');
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_672_runs";');
    }
}
