<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx103_platform: состояние исполнителя расписаний манифестов (schedule:run).
 *
 * Одна строка на ключ расписания (`schedule.<ключ>` в config/prx_module.php
 * модуля-владельца). Ключ — часть состояния, поэтому в манифестах его не
 * переименовывают. Строка создаётся при первом запуске; удалённое из манифеста
 * расписание остаётся строкой истории (на /prx100/selfcheck его не видно).
 */
class pgMigration_Prx_103_001_Schedule extends pgMigration
{
    public function up()
    {
        $this->run_query("
            CREATE TABLE IF NOT EXISTS prx_103_schedule_runs (
                key                  VARCHAR(100) PRIMARY KEY,
                module               VARCHAR(100) NOT NULL DEFAULT '',
                status               VARCHAR(16)  NOT NULL DEFAULT 'never'
                                     CHECK (status IN ('never', 'running', 'ok', 'failed', 'timeout')),
                started_at           TIMESTAMPTZ,
                finished_at          TIMESTAMPTZ,
                exit_code            INTEGER,
                duration_ms          INTEGER,
                output               TEXT NOT NULL DEFAULT '',
                runs                 INTEGER NOT NULL DEFAULT 0,
                failures             INTEGER NOT NULL DEFAULT 0,
                consecutive_failures INTEGER NOT NULL DEFAULT 0,
                updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ");
        $this->run_query("COMMENT ON TABLE prx_103_schedule_runs IS 'Последний запуск каждого расписания манифестов (bin/console schedule:run)'");
        $this->run_query("COMMENT ON COLUMN prx_103_schedule_runs.output IS 'Хвост вывода последнего запуска (обрезан), для диагностики'");
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS prx_103_schedule_runs');
    }
}
