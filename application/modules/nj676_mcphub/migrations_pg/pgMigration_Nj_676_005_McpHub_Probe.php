<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Честный статус удалённых серверов: счётчик неудачных проверок.
 *
 * Зачем: одна сетевая икота (DNS, таймаут на стороне воркера) не должна
 * превращать живой сервер в «недоступен» — по такому статусу агент перестаёт
 * его предлагать, а человек видит мёртвый каталог. Поэтому:
 *   1-я неудача  → degraded («сбоит», сервер остаётся в выдаче);
 *   2-я подряд   → offline;
 *   любой успех  → счётчик сбрасывается.
 *
 * Миграция идемпотентна: ADD COLUMN IF NOT EXISTS.
 */
class pgMigration_Nj_676_005_McpHub_Probe extends pgMigration
{
    public function up()
    {
        foreach ([
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS probe_failures SMALLINT NOT NULL DEFAULT 0",
            "ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS probe_tools_at TIMESTAMPTZ",
        ] as $sql)
        {
            $this->run_query($sql.';');
        }

        // Планировщику проверок нужен быстрый доступ к «кого давно не трогали».
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_676_servers_probe_idx
            ON nj_676_servers (health_checked_at NULLS FIRST)
            WHERE is_published = TRUE AND remote_url <> '';");
    }

    public function down()
    {
        // Колонки нужны каталогу: откат оставил бы карточки без счётчика.
    }
}
