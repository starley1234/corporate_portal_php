<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx120: лимиты каналов очереди — «не больше N одновременно» и «не чаще
 * раза в S секунд». Нужны каналам, которые упираются во внешний ресурс
 * (LLM-провайдер с лимитом запросов, OCR-шлюз, MDM): без лимита десять
 * воркеров одновременно берут десять задач и получают десять 429.
 *
 * Строки задаёт администратор на /prx120/channels; нет строки (или нули) —
 * канал без ограничений. Захват (PHP JobQueue::claim и python PgQueue.claim)
 * читает таблицу одинаково (см. prx120 AGENTS.md «Лимиты каналов»).
 */
class pgMigration_Prx_120_008_Channel_Limits extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS prx_120_channel_limits (
    channel VARCHAR(128) PRIMARY KEY,
    max_concurrent INTEGER NOT NULL DEFAULT 0 CHECK (max_concurrent >= 0),
    min_interval_sec INTEGER NOT NULL DEFAULT 0 CHECK (min_interval_sec >= 0),
    updated_by BIGINT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
)
SQL);
        // «Когда канал последний раз брали в работу» — для min_interval_sec.
        $this->run_query('CREATE INDEX IF NOT EXISTS prx_120_jobs_channel_claimed_idx
            ON prx_120_jobs (channel, claimed_at DESC) WHERE claimed_at IS NOT NULL');
    }

    public function down()
    {
        $this->run_query('DROP INDEX IF EXISTS prx_120_jobs_channel_claimed_idx');
        $this->run_query('DROP TABLE IF EXISTS prx_120_channel_limits');
    }
}
