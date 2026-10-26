<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Построчный журнал прогона автосборки: что именно добавлено, обновлено,
 * пропущено и почему.
 *
 * Зачем. `/mcphub/admin?run=8` показывал четыре числа («найдено 12, добавлено
 * 9…»), но не отвечал на главный вопрос владельца: какие карточки? почему
 * одна из двенадцати пропущена? Приходилось верить счётчикам на слово.
 *
 * Кто пишет. Сайт, а не воркер: `McpHub::apply_discovery_item()` — единственное
 * место, где карточка попадает в каталог (и из автосборки, и из реестра), и
 * только оно знает настоящий итог («добавлено», «обновлено», «пропущено:
 * нужна HTTPS-ссылка»). Поэтому журнал заполняется даже тогда, когда воркер
 * упал посередине прогона.
 *
 * `run_id` намеренно без внешнего ключа: карточки ручной отладки приходят с
 * run_id = 0, и такие строки тоже полезны (видно, что приходило). Строки
 * удалённого прогона чистятся приложением, а не каскадом.
 *
 * Миграция идемпотентна.
 */
class pgMigration_Nj_676_012_McpHub_RunItems extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS nj_676_run_items (
    id          BIGSERIAL PRIMARY KEY,
    run_id      BIGINT NOT NULL DEFAULT 0,
    slug        VARCHAR(100) NOT NULL DEFAULT '',
    name        VARCHAR(200) NOT NULL DEFAULT '',
    status      VARCHAR(16) NOT NULL DEFAULT 'skipped'
                CHECK (status IN ('added', 'updated', 'skipped')),
    reason      TEXT NOT NULL DEFAULT '',
    repo_url    TEXT NOT NULL DEFAULT '',
    stars       INTEGER NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
SQL);
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_run_items_run_idx
            ON nj_676_run_items (run_id, id);');
    }

    public function down()
    {
        // Журнал прогонов — история: при откате не удаляем.
    }
}
