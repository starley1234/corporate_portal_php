<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция PRX-120: таблицы очереди фоновых задач (для python-воркеров).
 *
 * prx_120_jobs       — сами задачи (канал, payload, статус, попытки, результат);
 * prx_120_job_events — журнал жизни задачи (created/claimed/done/failed...).
 */
class pgMigration_Prx_120_001_Jobs_CreateTables extends pgMigration {

    public function up()
    {
        // 1. Очередь задач
        $this->run_query("CREATE TABLE IF NOT EXISTS prx_120_jobs
        (
          id            bigserial PRIMARY KEY,
          channel       varchar(128) NOT NULL,               -- 'embeddings.index', 'llm.summarize', ...
          payload       jsonb        NOT NULL DEFAULT '{}'::jsonb,
          result        jsonb,                               -- итог работы воркера
          status        varchar(16)  NOT NULL DEFAULT 'pending',  -- pending|processing|done|failed|cancelled
          priority      integer      NOT NULL DEFAULT 100,   -- меньше = раньше
          attempts      integer      NOT NULL DEFAULT 0,
          max_attempts  integer      NOT NULL DEFAULT 3,
          timeout_sec   integer      NOT NULL DEFAULT 600,
          module        varchar(32),                         -- модуль-инициатор (prxXXX)
          created_by    integer,                             -- user_id инициатора
          callback_url  text,                                -- HTTP-колбэк по завершении
          error         text,
          claimed_by    varchar(128),                        -- имя воркера
          claimed_at    timestamptz,
          heartbeat_at  timestamptz,
          available_at  timestamptz  NOT NULL DEFAULT now(), -- отложенный запуск / backoff
          created_at    timestamptz  NOT NULL DEFAULT now(),
          started_at    timestamptz,
          finished_at   timestamptz
        );");

        $this->run_query("CREATE INDEX IF NOT EXISTS prx_120_jobs_pick_idx
            ON prx_120_jobs (status, priority, available_at);");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_120_jobs_channel_idx
            ON prx_120_jobs (channel, status);");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_120_jobs_created_idx
            ON prx_120_jobs (created_at);");

        // 2. Журнал событий задачи
        $this->run_query("CREATE TABLE IF NOT EXISTS prx_120_job_events
        (
          id          bigserial PRIMARY KEY,
          job_id      bigint      NOT NULL,
          event       varchar(32) NOT NULL,   -- created|claimed|heartbeat|done|failed|cancelled
          message     text,
          meta        jsonb       NOT NULL DEFAULT '{}'::jsonb,
          created_at  timestamptz NOT NULL DEFAULT now()
        );");

        $this->run_query("CREATE INDEX IF NOT EXISTS prx_120_job_events_job_idx
            ON prx_120_job_events (job_id);");
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS prx_120_job_events;");
        $this->run_query("DROP TABLE IF EXISTS prx_120_jobs;");
    }
}
