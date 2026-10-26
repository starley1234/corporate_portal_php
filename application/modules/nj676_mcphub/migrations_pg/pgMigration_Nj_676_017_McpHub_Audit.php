<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Полевое исследование MCP-серверов воркером-агентом («поиск алмазов») и
 * рейтинг на основании отзывов агентов.
 *
 * Зачем:
 *   1. В каталоге тысячи MCP-серверов, и одних лишь `tools/list` и звёзд GitHub
 *      недостаточно, чтобы отличить действительно ценные и безопасные серверы
 *      («алмазы») от пустышек, скрытых пейволлов или небезопасных эндпоинтов.
 *      Воркер-агент (канал `nj676.audit`) заходит на доступные открытые MCP-серверы,
 *      проверяет схемы и безопасность, выполняет безопасный пробный вызов и
 *      составляет структурированный отчёт: оценка пользы (`utility_score`),
 *      безопасности (`safety_score`), вердикт (`gem` / `useful` / `niche` /
 *      `questionable` / `broken`), сценарии реального применения (`use_cases`)
 *      и наблюдения (`observations`).
 *   2. Отзывы агентов из `nj_676_feedback` складываются в честный агрегированный
 *      рейтинг `agent_rating` (0.00–5.00) и `agent_reviews_count` прямо в карточке
 *      сервера — для мгновенной сортировки и фильтрации в каталоге и шлюзе.
 *
 * Миграция идемпотентна: ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS /
 * CREATE INDEX IF NOT EXISTS.
 */
class pgMigration_Nj_676_017_McpHub_Audit extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS audit_score SMALLINT NOT NULL DEFAULT 0');
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS safety_score SMALLINT NOT NULL DEFAULT 0');
        $this->run_query("ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS audit_verdict VARCHAR(24) NOT NULL DEFAULT ''");
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS is_gem BOOLEAN NOT NULL DEFAULT FALSE');
        $this->run_query("ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS audit_summary TEXT NOT NULL DEFAULT ''");
        $this->run_query("ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS audit_report JSONB NOT NULL DEFAULT '{}'::jsonb");
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS audited_at TIMESTAMPTZ');
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS agent_rating NUMERIC(3,2) NOT NULL DEFAULT 0');
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS agent_reviews_count INTEGER NOT NULL DEFAULT 0');

        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS nj_676_audits (
    id            BIGSERIAL PRIMARY KEY,
    server_slug   VARCHAR(120) NOT NULL DEFAULT '',
    run_id        BIGINT,
    status        VARCHAR(24) NOT NULL DEFAULT 'done',
    utility_score SMALLINT NOT NULL DEFAULT 0,
    safety_score  SMALLINT NOT NULL DEFAULT 0,
    verdict       VARCHAR(24) NOT NULL DEFAULT 'useful',
    is_gem        BOOLEAN NOT NULL DEFAULT FALSE,
    summary       TEXT NOT NULL DEFAULT '',
    use_cases     JSONB NOT NULL DEFAULT '[]'::jsonb,
    safety_notes  TEXT NOT NULL DEFAULT '',
    observations  TEXT NOT NULL DEFAULT '',
    tested_tools  JSONB NOT NULL DEFAULT '[]'::jsonb,
    latency_ms    INTEGER NOT NULL DEFAULT 0,
    model         VARCHAR(120) NOT NULL DEFAULT '',
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
SQL);

        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_audits_server_idx
            ON nj_676_audits (server_slug, created_at DESC)');
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_audits_utility_idx
            ON nj_676_audits (utility_score DESC, created_at DESC)');
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_servers_audit_idx
            ON nj_676_servers (is_gem DESC, audit_score DESC, safety_score DESC)');
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_servers_agent_rating_idx
            ON nj_676_servers (agent_rating DESC, agent_reviews_count DESC)');
    }

    public function down()
    {
        $this->run_query('DROP INDEX IF EXISTS nj_676_servers_agent_rating_idx');
        $this->run_query('DROP INDEX IF EXISTS nj_676_servers_audit_idx');
        $this->run_query('DROP INDEX IF EXISTS nj_676_audits_utility_idx');
        $this->run_query('DROP INDEX IF EXISTS nj_676_audits_server_idx');
        $this->run_query('DROP TABLE IF EXISTS nj_676_audits');
    }
}
