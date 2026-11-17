<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx392_analysis — прогоны ИИ-разбора и предложения.
 *
 * Модуль НИКОГДА не пишет в карточку документа напрямую: результат живёт в
 * prx_392_suggestions, пока человек не нажмёт «Применить». Это и есть
 * техническая граница «ИИ не решает права».
 */
class pgMigration_Prx_392_001_CreateAnalysis extends pgMigration
{
    public function up()
    {
        $this->run_query("
            CREATE TABLE IF NOT EXISTS prx_392_runs (
                id              bigserial PRIMARY KEY,
                document_id     bigint NOT NULL,
                message_id      bigint NOT NULL,
                profile         varchar(40) NOT NULL,
                model           varchar(160) NOT NULL,
                prompt_version  varchar(16) NOT NULL,
                schema_version  varchar(40) NOT NULL DEFAULT 'doc_card_v1',
                input_hash      char(32) NOT NULL,
                status          varchar(20) NOT NULL DEFAULT 'queued',
                job_id          bigint NULL,
                tokens          int NULL,
                duration_ms     int NULL,
                error           text NULL,
                created_by      bigint NULL,
                created_at      timestamp NOT NULL DEFAULT now(),
                started_at      timestamp NULL,
                finished_at     timestamp NULL
            )
        ");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_392_runs_doc_idx ON prx_392_runs (document_id, created_at DESC)");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_392_runs_status_idx ON prx_392_runs (status, created_at DESC)");
        $this->run_query("CREATE UNIQUE INDEX IF NOT EXISTS prx_392_runs_hash_uniq ON prx_392_runs (document_id, profile, input_hash)");

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prx_392_suggestions (
                id              bigserial PRIMARY KEY,
                run_id          bigint NOT NULL UNIQUE REFERENCES prx_392_runs(id) ON DELETE CASCADE,
                document_id     bigint NOT NULL,
                payload         jsonb NOT NULL,
                confidence      numeric(4,3) NULL,
                status          varchar(20) NOT NULL DEFAULT 'proposed',
                reviewed_by     bigint NULL,
                reviewed_at     timestamp NULL,
                created_at      timestamp NOT NULL DEFAULT now()
            )
        ");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_392_suggestions_doc_idx ON prx_392_suggestions (document_id, created_at DESC)");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_392_suggestions_status_idx ON prx_392_suggestions (status)");
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS prx_392_suggestions");
        $this->run_query("DROP TABLE IF EXISTS prx_392_runs");
    }
}
