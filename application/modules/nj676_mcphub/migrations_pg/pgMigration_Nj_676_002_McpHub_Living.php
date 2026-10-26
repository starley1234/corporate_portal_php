<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Живой каталог MCP: метаданные GitHub, пакеты, найденный удалённый адрес,
 * происхождение записи и журнал автосборки.
 *
 * Миграция идемпотентна: 001 уже создала nj_676_servers, здесь только
 * расширяем её и добавляем таблицу прогонов. Поля со значениями по умолчанию,
 * поэтому старые карточки не ломаются.
 */
class pgMigration_Nj_676_002_McpHub_Living extends pgMigration
{
    public function up()
    {
        foreach ([
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS github_id BIGINT",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS owner_login VARCHAR(120) NOT NULL DEFAULT ''",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS stars INTEGER NOT NULL DEFAULT 0",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS forks INTEGER NOT NULL DEFAULT 0",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS language VARCHAR(60) NOT NULL DEFAULT ''",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS license VARCHAR(80) NOT NULL DEFAULT ''",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS topics JSONB NOT NULL DEFAULT '[]'::jsonb",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS highlights JSONB NOT NULL DEFAULT '[]'::jsonb",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS use_cases JSONB NOT NULL DEFAULT '[]'::jsonb",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS remote_url TEXT NOT NULL DEFAULT ''",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS remote_kind VARCHAR(16) NOT NULL DEFAULT ''",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS packages JSONB NOT NULL DEFAULT '[]'::jsonb",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS origin VARCHAR(16) NOT NULL DEFAULT 'manual'",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS trust SMALLINT NOT NULL DEFAULT 0",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS views INTEGER NOT NULL DEFAULT 0",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS repo_pushed_at TIMESTAMPTZ",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS repo_archived BOOLEAN NOT NULL DEFAULT FALSE",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS latency_ms INTEGER",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS health_note TEXT NOT NULL DEFAULT ''",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS run_id BIGINT",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS search_text TEXT NOT NULL DEFAULT ''",
        ] as $sql)
        {
            $this->run_query($sql.';');
        }

        // Поиск: одно поле вместо склейки на каждый запрос. Заполняется сервисом
        // при сохранении (name + description + категории + инструменты).
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_servers_search_tsv_idx
            ON nj_676_servers USING GIN (to_tsvector(\'simple\'::regconfig, search_text));');
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_servers_popular_idx
            ON nj_676_servers (is_published, stars DESC, name);');
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_servers_topics_gin_idx
            ON nj_676_servers USING GIN (topics);');

        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS nj_676_runs (
    id          BIGSERIAL PRIMARY KEY,
    status      VARCHAR(16) NOT NULL DEFAULT 'running'
                CHECK (status IN ('running', 'done', 'fail', 'cancelled')),
    theme       VARCHAR(200) NOT NULL DEFAULT '',
    requested   INTEGER NOT NULL DEFAULT 12,
    found       INTEGER NOT NULL DEFAULT 0,
    added       INTEGER NOT NULL DEFAULT 0,
    updated     INTEGER NOT NULL DEFAULT 0,
    skipped     INTEGER NOT NULL DEFAULT 0,
    probed      INTEGER NOT NULL DEFAULT 0,
    progress    TEXT NOT NULL DEFAULT '',
    error       TEXT NOT NULL DEFAULT '',
    job_id      BIGINT,
    user_id     INTEGER,
    started_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at TIMESTAMPTZ
);
SQL);
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_runs_started_idx ON nj_676_runs (started_at DESC);');

        // Профиль агента: hub_configure сохраняет фильтры выборки для конкретного
        // ключа, чтобы «ищи только без авторизации» работало и в следующем
        // диалоге. Хранится sha256 ключа, не сам ключ.
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS nj_676_agent_profiles (
    token_hash  VARCHAR(64) PRIMARY KEY,
    filters     JSONB NOT NULL DEFAULT '{}'::jsonb,
    calls       INTEGER NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
SQL);

        // Заполняем search_text у старых карточек: поиск не должен «не видеть»
        // записи, созданные до миграции.
        $this->run_query(<<<'SQL'
UPDATE nj_676_servers
   SET search_text = trim(coalesce(name, '') || ' ' || coalesce(description, '') || ' '
        || coalesce(categories::text, '[]') || ' ' || coalesce(tools::text, '[]'))
 WHERE search_text = '';
SQL);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS nj_676_agent_profiles;');
        $this->run_query('DROP TABLE IF EXISTS nj_676_runs;');
        $this->run_query('DROP INDEX IF EXISTS nj_676_servers_search_tsv_idx;');
        $this->run_query('DROP INDEX IF EXISTS nj_676_servers_popular_idx;');
        $this->run_query('DROP INDEX IF EXISTS nj_676_servers_topics_gin_idx;');
    }
}
