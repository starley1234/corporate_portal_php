<?php defined('SYSPATH') or die('No direct script access.');

/** MCP Hub public catalogue; seed rows are discovery candidates, never health-verified. */
class pgMigration_Nj_676_001_McpHub_Catalog extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS nj_676_servers (
    id                  BIGSERIAL PRIMARY KEY,
    slug                VARCHAR(100) NOT NULL UNIQUE,
    name                VARCHAR(160) NOT NULL,
    description         TEXT NOT NULL DEFAULT '',
    repo_url            TEXT NOT NULL,
    homepage_url        TEXT NOT NULL DEFAULT '',
    source              VARCHAR(16) NOT NULL DEFAULT 'github'
                        CHECK (source IN ('github', 'npm', 'pypi', 'manual', 'native')),
    transport           VARCHAR(24) NOT NULL DEFAULT 'unknown'
                        CHECK (transport IN ('unknown', 'stdio', 'sse', 'streamable_http')),
    auth_mode           VARCHAR(16) NOT NULL DEFAULT 'unknown'
                        CHECK (auth_mode IN ('unknown', 'none', 'api_key', 'oauth')),
    categories          JSONB NOT NULL DEFAULT '[]'::jsonb,
    health_status       VARCHAR(16) NOT NULL DEFAULT 'unverified'
                        CHECK (health_status IN ('unverified', 'operational', 'degraded', 'offline')),
    health_checked_at   TIMESTAMPTZ,
    package_manager     VARCHAR(8) NOT NULL DEFAULT ''
                        CHECK (package_manager IN ('', 'npm', 'pypi')),
    package_name        VARCHAR(200) NOT NULL DEFAULT '',
    package_entrypoint  VARCHAR(100) NOT NULL DEFAULT '',
    env_keys            JSONB NOT NULL DEFAULT '[]'::jsonb,
    tools               JSONB NOT NULL DEFAULT '[]'::jsonb,
    security_report     JSONB,
    is_published        BOOLEAN NOT NULL DEFAULT FALSE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
SQL);
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_servers_published_idx ON nj_676_servers (is_published, health_status, name);');
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_servers_categories_gin_idx ON nj_676_servers USING GIN (categories);');
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_676_servers_search_gin_idx ON nj_676_servers USING GIN (to_tsvector('simple'::regconfig, coalesce(name, '') || ' ' || coalesce(description, '') || ' ' || coalesce(repo_url, '') || ' ' || tools::text));");

        // Небольшой стартовый список публичных кандидатов. Источники — ссылки
        // на репозитории; транспорт, auth, schemas и здоровье не выдумываем.
        $this->run_query(<<<'SQL'
INSERT INTO nj_676_servers
    (slug, name, description, repo_url, source, categories, health_status, is_published)
VALUES
    ('apify-mcp-server', 'Apify MCP Server', 'Связка AI-агентов с Apify Actors для веб-данных и автоматизации.', 'https://github.com/apify/apify-mcp-server', 'github', '["web","research"]', 'unverified', TRUE),
    ('exa-mcp-server', 'Exa MCP Server', 'MCP-сервер Exa для веб-поиска и извлечения страниц.', 'https://github.com/exa-labs/exa-mcp-server', 'github', '["web","research"]', 'unverified', TRUE),
    ('notion-mcp-server', 'Notion MCP Server', 'Официальный MCP-сервер Notion.', 'https://github.com/makenotion/notion-mcp-server', 'github', '["productivity"]', 'unverified', TRUE),
    ('cloudflare-mcp-server', 'Cloudflare MCP Servers', 'Набор MCP-серверов для продуктов Cloudflare.', 'https://github.com/cloudflare/mcp-server-cloudflare', 'github', '["cloud","development"]', 'unverified', TRUE),
    ('chrome-devtools-mcp', 'Chrome DevTools MCP', 'Инструменты Chrome DevTools для coding agents.', 'https://github.com/ChromeDevTools/chrome-devtools-mcp', 'github', '["development","web"]', 'unverified', TRUE),
    ('browserbase-mcp-server', 'Browserbase MCP Server', 'Управление браузером через Browserbase и Stagehand.', 'https://github.com/browserbase/mcp-server-browserbase', 'github', '["web","development"]', 'unverified', TRUE),
    ('docs-mcp-server', 'Docs MCP Server', 'Индексированная документация для AI coding agents.', 'https://github.com/arabold/docs-mcp-server', 'github', '["development","research"]', 'unverified', TRUE),
    ('kubernetes-mcp-server', 'Kubernetes MCP Server', 'MCP-сервер для операций с Kubernetes.', 'https://github.com/Flux159/mcp-server-kubernetes', 'github', '["cloud","development"]', 'unverified', TRUE)
ON CONFLICT (slug) DO NOTHING;
SQL);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS nj_676_servers;');
    }
}
