<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ИИ-анализ каталога MCP: снимок и отчёт модели.
 *
 * Зачем своя таблица. Вопрос владельца 24.10.2026: «какие онлайн-серверы у нас
 * реально доступны и чего не хватает?» — на него не отвечает ни один счётчик.
 * Счётчики говорят «готовы 17», но не говорят, что из этого чинить, кому нужен
 * ключ и какие темы агенты спрашивают впустую. Такой ответ даёт модель — а
 * значит, нужен снимок (что именно ей показали) и отчёт (что она сказала),
 * чтобы результат можно было перепроверить через сутки.
 *
 * Почему не в `nj_676_runs`. Прогон — это работа по сборке каталога: тема,
 * добавлено/обновлено/пропущено. Анализ ничего не меняет в каталоге и живёт по
 * своим правилам (снимок + структурированный отчёт + история сравнений).
 * Смешивать их — значит опять показывать владельцу числа без причины.
 *
 * Миграция идемпотентна: CREATE TABLE IF NOT EXISTS / CREATE INDEX IF NOT
 * EXISTS, после применения не редактируется.
 */
class pgMigration_Nj_676_016_McpHub_Analysis extends pgMigration
{
    public function up()
    {
        $this->run_query("CREATE TABLE IF NOT EXISTS nj_676_analysis (
            id          BIGSERIAL PRIMARY KEY,
            created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
            started_at  TIMESTAMPTZ,
            finished_at TIMESTAMPTZ,
            -- queued | running | done | fail — тот же словарь, что у прогонов.
            status      TEXT NOT NULL DEFAULT 'queued',
            -- both | live | gaps — что именно просили проанализировать.
            mode        TEXT NOT NULL DEFAULT 'both',
            job_id      BIGINT,
            created_by  INTEGER,
            model       TEXT NOT NULL DEFAULT '',
            -- Снимок данных, который видел модель: без него отчёт не проверить.
            snapshot    JSONB,
            -- Ответ модели в структурированном виде (вердикт, поломки, пробелы).
            report      JSONB,
            error       TEXT NOT NULL DEFAULT '',
            ms          INTEGER NOT NULL DEFAULT 0
        )");

        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_analysis_created_idx
            ON nj_676_analysis (created_at DESC)');

        // «Есть ли уже идущий анализ» — отдельный маленький индекс по статусу.
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_676_analysis_status_idx
            ON nj_676_analysis (status)
            WHERE status IN ('queued', 'running')");
    }

    public function down()
    {
        $this->run_query('DROP INDEX IF EXISTS nj_676_analysis_status_idx');
        $this->run_query('DROP INDEX IF EXISTS nj_676_analysis_created_idx');
        $this->run_query('DROP TABLE IF EXISTS nj_676_analysis');
    }
}
