<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Русские названия и описания карточек каталога MCP.
 *
 * Зачем. Сайт русскоязычный, а имя и описание MCP-сервера приходят из
 * репозитория на английском («Playwright MCP Server», «Browser automation for
 * AI agents»). Агенту и человеку нужен русский текст, но выдумывать его в
 * шаблоне нельзя: название проекта — факт, перевод — редакционная работа.
 *
 * Решение. Оригинал остаётся в `name` / `description` (это то, что реально
 * написано у автора), перевод — в `name_ru` / `description_ru`. Пустая строка
 * означает «перевода нет», и тогда интерфейс показывает оригинал. Кто и когда
 * сделал перевод — `ru_source` ('llm' | 'manual') и `ru_at`: видно, можно ли
 * доверять тексту и не устарел ли он.
 *
 * Миграция идемпотентна: ADD COLUMN IF NOT EXISTS.
 */
class pgMigration_Nj_676_011_McpHub_Russian extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS name_ru VARCHAR(160)');
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS description_ru TEXT');
        $this->run_query("ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS ru_source VARCHAR(16) NOT NULL DEFAULT ''");
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS ru_at TIMESTAMPTZ');

        // «Сколько карточек ещё без перевода» — самый частый вопрос админки,
        // поэтому частичный индекс именно по пустым значениям.
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_676_servers_no_ru_idx
            ON nj_676_servers (updated_at DESC)
            WHERE is_published = TRUE AND (name_ru IS NULL OR name_ru = '')");
    }

    public function down()
    {
        // Перевод — редакционный актив: при откате не удаляем.
    }
}
