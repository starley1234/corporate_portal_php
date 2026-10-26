<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Наблюдаемость шлюза: проверка схем, журнал вызовов и отзывы.
 *
 * Зачем. Реальный тест агентами (23.10.2026) показал три вещи, которые нельзя
 * было увидеть по коду:
 *   1. сервер без валидной схемы `tools/list` (например, `properties: []`)
 *      отключает у строгого клиента ВСЕ инструменты — это надо проверять при
 *      приёмке в каталог, а не после жалобы;
 *   2. агент не знает, сработал ли вызов, и не может сказать «этот сервер
 *      врёт» — без `call_id` отзыв не привязать к реальному вызову;
 *   3. «популярность» без телеметрии — это звёзды GitHub, а не надёжность.
 *
 * Поэтому:
 *   * `nj_676_servers.schema_valid` / `schema_note` — результат проверки схем
 *     на приёмке и при каждом живом `tools/list`;
 *   * `nj_676_calls` — каждый вызов через шлюз: сервер, инструмент, успех,
 *     задержка, код ошибки, `call_id` (он же ключ для отзыва);
 *   * `nj_676_feedback` — отзыв агента строго по `call_id`, один на вызов.
 *
 * Миграция идемпотентна: ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS.
 */
class pgMigration_Nj_676_008_McpHub_Telemetry extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS schema_valid BOOLEAN');
        $this->run_query("ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS schema_note VARCHAR(200) NOT NULL DEFAULT ''");

        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS nj_676_calls (
    id          BIGSERIAL PRIMARY KEY,
    call_id     VARCHAR(40) NOT NULL UNIQUE,
    token_hash  VARCHAR(64) NOT NULL DEFAULT '',
    server_slug VARCHAR(120) NOT NULL DEFAULT '',
    tool        VARCHAR(160) NOT NULL DEFAULT '',
    ok          BOOLEAN NOT NULL DEFAULT FALSE,
    latency_ms  INTEGER NOT NULL DEFAULT 0,
    error_code  VARCHAR(32) NOT NULL DEFAULT '',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
SQL);

        // Два запроса к журналу: «как дела у этого сервера» и «что делал этот агент».
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_calls_server_idx
            ON nj_676_calls (server_slug, created_at DESC);');
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_calls_token_idx
            ON nj_676_calls (token_hash, created_at DESC);');
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_calls_created_idx
            ON nj_676_calls (created_at DESC);');

        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS nj_676_feedback (
    id         BIGSERIAL PRIMARY KEY,
    call_id    VARCHAR(40) NOT NULL UNIQUE,
    rating     SMALLINT NOT NULL DEFAULT 0,
    outcome    VARCHAR(24) NOT NULL DEFAULT '',
    tags       JSONB NOT NULL DEFAULT '[]'::jsonb,
    comment    TEXT NOT NULL DEFAULT '',
    token_hash VARCHAR(64) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
SQL);

        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_feedback_created_idx
            ON nj_676_feedback (created_at DESC);');
    }

    public function down()
    {
        // Журнал вызовов — источник надёжности в ранжировании и ключ к отзывам:
        // откат обнулил бы оба сигнала.
    }
}
