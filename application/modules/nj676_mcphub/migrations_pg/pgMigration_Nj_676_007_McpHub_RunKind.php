<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Тип прогона в журнале: сборка, проверка живости или импорт реестра.
 *
 * Зачем. Все три операции писали строку в nj_676_runs, различаясь только
 * текстом темы. Из-за этого ночной пинок /mcphub/api/tick считал «идущей
 * сборкой» только что поставленную проверку живости и тихо пропускал
 * автосборку — то есть каталог переставал пополняться сам. Проверка живости
 * ходит каждые несколько часов, поэтому «повезёт, если между ними» — не
 * расписание, а лотерея.
 *
 * Теперь у прогона есть машинный тип: discover | probe | registry.
 * Миграция идемпотентна: ADD COLUMN IF NOT EXISTS.
 */
class pgMigration_Nj_676_007_McpHub_RunKind extends pgMigration
{
    public function up()
    {
        $this->run_query("ALTER TABLE nj_676_runs ADD COLUMN IF NOT EXISTS kind VARCHAR(16) NOT NULL DEFAULT 'discover'");

        // Старые строки размечаем по их же теме: других признаков у них нет.
        $this->run_query("UPDATE nj_676_runs SET kind = 'probe'
            WHERE kind = 'discover' AND theme = 'проверка удалённых серверов';");
        $this->run_query("UPDATE nj_676_runs SET kind = 'registry'
            WHERE kind = 'discover' AND theme = 'реестр MCP';");

        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_runs_kind_idx
            ON nj_676_runs (kind, started_at DESC);');
    }

    public function down()
    {
        // Колонку читает автосборка: откат вернул бы пропуск ночных прогонов.
    }
}
