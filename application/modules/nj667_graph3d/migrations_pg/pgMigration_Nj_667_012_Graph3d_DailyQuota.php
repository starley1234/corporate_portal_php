<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 012: атомарный дневной учёт платных LLM-операций.
 *
 * Счётчик — по user_id, UTC-дню и виду платной операции. Upsert с условием
 * used < limit резервирует место конкурентно: два запроса не могут вместе
 * обойти дневную квоту. Ручные сохранения и выгрузки в эту таблицу не пишутся.
 */
class pgMigration_Nj_667_012_Graph3d_DailyQuota extends pgMigration
{
	public function up()
	{
		$this->run_query('CREATE TABLE IF NOT EXISTS nj_667_daily_usage (
			user_id    BIGINT      NOT NULL,
			usage_day  DATE        NOT NULL,
			operation  VARCHAR(32) NOT NULL,
			used       INTEGER     NOT NULL DEFAULT 0 CHECK (used >= 0),
			updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
			PRIMARY KEY (user_id, usage_day, operation)
		)');

		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_daily_usage_day_idx
			ON nj_667_daily_usage (usage_day)');
		$this->run_query('COMMENT ON TABLE nj_667_daily_usage IS
			\'NJ-667 дневные квоты: атомарный счётчик LLM-операций пользователя по UTC-дню\'');
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS nj_667_daily_usage');
	}
}
