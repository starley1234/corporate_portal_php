<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция NJ-130: LLM-агенты.
 *
 * nj_130_agents         — определения агентов (системный промпт, модель, параметры);
 * nj_130_agent_runs     — запуски (статус, вход, результат, job_id в очереди);
 * nj_130_agent_messages — расшифровка диалога запуска (user/assistant/...).
 */
class pgMigration_Nj_130_001_Agents_CreateTables extends pgMigration {

    public function up()
    {
        // 1. Определения агентов
        $this->run_query("CREATE TABLE IF NOT EXISTS nj_130_agents
        (
          id            bigserial PRIMARY KEY,
          slug          varchar(64) NOT NULL,
          name          varchar(128) NOT NULL DEFAULT '',
          system_prompt text NOT NULL DEFAULT '',
          model         varchar(128) NOT NULL DEFAULT '',   -- провайдер::модель; пусто = модель по умолчанию
          temperature   real NOT NULL DEFAULT 0.2,
          max_tokens    integer,
          config        jsonb NOT NULL DEFAULT '{}'::jsonb, -- прочие параметры (инструменты и т.п.)
          enabled       boolean NOT NULL DEFAULT TRUE,
          created_at    timestamptz NOT NULL DEFAULT now(),
          updated_at    timestamptz NOT NULL DEFAULT now(),
          CONSTRAINT nj_130_agents_slug_key UNIQUE (slug)
        );");

        // 2. Запуски
        $this->run_query("CREATE TABLE IF NOT EXISTS nj_130_agent_runs
        (
          id          bigserial PRIMARY KEY,
          agent_id    bigint NOT NULL,
          user_id     integer,
          status      varchar(16) NOT NULL DEFAULT 'pending',  -- pending|running|done|failed|cancelled
          input       jsonb NOT NULL DEFAULT '{}'::jsonb,
          result      jsonb,
          error       text,
          job_id      bigint,                                   -- задача в очереди nj_120_jobs
          model_used  varchar(128) NOT NULL DEFAULT '',
          tokens      integer NOT NULL DEFAULT 0,
          created_at  timestamptz NOT NULL DEFAULT now(),
          started_at  timestamptz,
          finished_at timestamptz
        );");

        $this->run_query("CREATE INDEX IF NOT EXISTS nj_130_agent_runs_agent_idx
            ON nj_130_agent_runs (agent_id, created_at);");
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_130_agent_runs_user_idx
            ON nj_130_agent_runs (user_id, created_at);");

        // 3. Сообщения запуска (диалог)
        $this->run_query("CREATE TABLE IF NOT EXISTS nj_130_agent_messages
        (
          id         bigserial PRIMARY KEY,
          run_id     bigint NOT NULL,
          role       varchar(16) NOT NULL,      -- user|assistant|system|tool
          content    text NOT NULL DEFAULT '',
          meta       jsonb NOT NULL DEFAULT '{}'::jsonb,
          created_at timestamptz NOT NULL DEFAULT now()
        );");

        $this->run_query("CREATE INDEX IF NOT EXISTS nj_130_agent_messages_run_idx
            ON nj_130_agent_messages (run_id, id);");
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS nj_130_agent_messages;");
        $this->run_query("DROP TABLE IF EXISTS nj_130_agent_runs;");
        $this->run_query("DROP TABLE IF EXISTS nj_130_agents;");
    }
}
