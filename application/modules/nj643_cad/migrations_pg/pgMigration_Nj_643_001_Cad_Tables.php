<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Модуль «CAD-детали» (nj643_cad).
 *
 * Зачем (владелец, 26.09.2026): «Сделай хороший сервис для генерации 3D с
 * помощью LLM и /v1/cad/verify-and-build. Постарайся сделать его рабочим,
 * качественным. Далее будем улучшать функционал».
 *
 * Что здесь:
 *   nj_643_parts     — детали: описание, код модели, схема параметров, состояние,
 *                      ссылка-код, привязка к задаче очереди;
 *   nj_643_revisions — ревизии детали: сборка по описанию / правка словами /
 *                      пересборка по параметрам (история не теряется);
 *   nj_643_files     — файлы ревизий: STEP/STL/GLB и PNG-ракурсы движка;
 *   nj_643_runs      — журнал обращений к внешнему движку (HTTP, время, ошибка);
 *   nj_643_usage     — расход LLM по фазам (модель, токены, время).
 *
 * Миграция идемпотентна (CREATE … IF NOT EXISTS), а сервис дополнительно
 * создаёт те же таблицы сам (Service_Nj643_Cad::ensure_tables) — модуль обязан
 * подниматься одним git pull, даже если миграции ещё не накатывали.
 *
 * ВАЖНО: базовый класс — `pgMigration` (модуль nj111_migrations), а не
 * `Migration`: так работает Migrator (/migrate_pg).
 */
class pgMigration_Nj_643_001_Cad_Tables extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_643_parts" (
    "id"                BIGSERIAL PRIMARY KEY,
    "code"              VARCHAR(16)  NOT NULL UNIQUE,
    "user_id"           BIGINT       NOT NULL DEFAULT 0,
    "title"             VARCHAR(200) NOT NULL DEFAULT '',
    "prompt"            TEXT         NOT NULL DEFAULT '',
    "status"            VARCHAR(12)  NOT NULL DEFAULT 'queued',
    "stage"             VARCHAR(200) NOT NULL DEFAULT '',
    "code_py"           TEXT         NULL,
    "schema_definition" JSONB        NULL,
    "params"            JSONB        NULL,
    "report"            JSONB        NULL,
    "error"             TEXT         NULL,
    "job_id"            BIGINT       NOT NULL DEFAULT 0,
    "revision"          INTEGER      NOT NULL DEFAULT 1,
    "exports"           JSONB        NULL,
    "deflection"        NUMERIC(6,4) NOT NULL DEFAULT 0.025,
    "stress"            BOOLEAN      NOT NULL DEFAULT TRUE,
    "files_count"       INTEGER      NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at"       TIMESTAMPTZ  NULL,
    "deleted_at"        TIMESTAMPTZ  NULL
)
SQL);

        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_643_revisions" (
    "id"                BIGSERIAL PRIMARY KEY,
    "part_id"           BIGINT       NOT NULL,
    "rev"               INTEGER      NOT NULL DEFAULT 1,
    "action"            VARCHAR(16)  NOT NULL DEFAULT 'generate',
    "instruction"       TEXT         NULL,
    "code_py"           TEXT         NULL,
    "schema_definition" JSONB        NULL,
    "params"            JSONB        NULL,
    "status"            VARCHAR(12)  NOT NULL DEFAULT 'queued',
    "report"            JSONB        NULL,
    "error"             TEXT         NULL,
    "attempts"          INTEGER      NOT NULL DEFAULT 0,
    "job_id"            BIGINT       NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at"       TIMESTAMPTZ  NULL
)
SQL);

        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_643_files" (
    "id"          BIGSERIAL PRIMARY KEY,
    "part_id"     BIGINT       NOT NULL,
    "revision_id" BIGINT       NOT NULL DEFAULT 0,
    "kind"        VARCHAR(16)  NOT NULL DEFAULT 'other',
    "view"        VARCHAR(40)  NOT NULL DEFAULT '',
    "name"        VARCHAR(200) NOT NULL DEFAULT '',
    "path"        VARCHAR(300) NOT NULL DEFAULT '',
    "size"        BIGINT       NOT NULL DEFAULT 0,
    "meta"        JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL);

        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_643_runs" (
    "id"          BIGSERIAL PRIMARY KEY,
    "part_id"     BIGINT       NOT NULL DEFAULT 0,
    "revision_id" BIGINT       NOT NULL DEFAULT 0,
    "kind"        VARCHAR(20)  NOT NULL DEFAULT 'build',
    "ok"          BOOLEAN      NOT NULL DEFAULT FALSE,
    "http_status" INTEGER      NOT NULL DEFAULT 0,
    "duration_ms" INTEGER      NOT NULL DEFAULT 0,
    "engine"      JSONB        NULL,
    "error"       VARCHAR(500) NOT NULL DEFAULT '',
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL);

        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_643_usage" (
    "id"                BIGSERIAL PRIMARY KEY,
    "part_id"           BIGINT       NOT NULL DEFAULT 0,
    "revision_id"       BIGINT       NOT NULL DEFAULT 0,
    "job_id"            BIGINT       NOT NULL DEFAULT 0,
    "phase"             VARCHAR(60)  NOT NULL DEFAULT '',
    "model"             VARCHAR(160) NOT NULL DEFAULT '',
    "prompt_tokens"     INTEGER      NOT NULL DEFAULT 0,
    "completion_tokens" INTEGER      NOT NULL DEFAULT 0,
    "duration_ms"       INTEGER      NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL);

        $this->run_query(
            'CREATE UNIQUE INDEX IF NOT EXISTS "nj_643_revisions_uniq"'
            .' ON "nj_643_revisions" ("part_id", "rev")');

        $this->run_query(
            'CREATE INDEX IF NOT EXISTS "nj_643_parts_user_idx"'
            .' ON "nj_643_parts" ("user_id", "id" DESC)');

        $this->run_query(
            'CREATE INDEX IF NOT EXISTS "nj_643_parts_status_idx"'
            .' ON "nj_643_parts" ("status", "id" DESC)');

        $this->run_query(
            'CREATE INDEX IF NOT EXISTS "nj_643_files_part_idx"'
            .' ON "nj_643_files" ("part_id", "kind", "id")');

        $this->run_query(
            'CREATE INDEX IF NOT EXISTS "nj_643_runs_idx"'
            .' ON "nj_643_runs" ("kind", "id" DESC)');

        $this->run_query(
            'CREATE INDEX IF NOT EXISTS "nj_643_usage_part_idx"'
            .' ON "nj_643_usage" ("part_id", "id" DESC)');

        $this->run_query("COMMENT ON TABLE \"nj_643_parts\" IS 'nj643_cad: детали — описание, код модели, параметры, состояние'");
        $this->run_query("COMMENT ON TABLE \"nj_643_files\" IS 'nj643_cad: файлы ревизий (step/stl/glb/png-ракурсы)'");
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_643_usage"');
        $this->run_query('DROP TABLE IF EXISTS "nj_643_runs"');
        $this->run_query('DROP TABLE IF EXISTS "nj_643_files"');
        $this->run_query('DROP TABLE IF EXISTS "nj_643_revisions"');
        $this->run_query('DROP TABLE IF EXISTS "nj_643_parts"');
    }
}
