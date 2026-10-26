<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Спрос агентов: что искали — и чего в каталоге не нашлось.
 *
 * Зачем. Шлюз `/mcphub/mcp` уже видит каждый запрос агента
 * (`hub_search_tools`) и знает, что именно он искал и нашлось ли что-то.
 * Раньше этот сигнал пропадал: каталог пополнялся только вручную кнопкой
 * «Найти и добавить», поэтому он рос вокруг того, что придумал редактор,
 * а не вокруг того, что реально просят агенты.
 *
 * Теперь каждый поиск — одна строка журнала:
 *   kind          — 'agent' (шлюз) или 'catalog' (поиск на сайте);
 *   query         — как написали (до 200 символов);
 *   norm          — нормализованный запрос для группировки («Погода!» → «погода»);
 *   servers_found — сколько карточек каталога нашлось;
 *   tools_found   — сколько инструментов платформы нашлось;
 *   token_hash    — sha256 ключа агента (сам ключ не пишем).
 *
 * По этим строкам строится «спрос» (Service_Nj676_Demand): запросы без
 * результата становятся темами автосборки — ровно в том порядке, в каком их
 * спрашивают агенты.
 *
 * Миграция идемпотентна: CREATE TABLE/INDEX IF NOT EXISTS.
 */
class pgMigration_Nj_676_006_McpHub_Demand extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS nj_676_search_log (
    id            BIGSERIAL PRIMARY KEY,
    kind          VARCHAR(16) NOT NULL DEFAULT 'agent'
                  CHECK (kind IN ('agent', 'catalog')),
    query         VARCHAR(200) NOT NULL DEFAULT '',
    norm          VARCHAR(200) NOT NULL DEFAULT '',
    servers_found INTEGER NOT NULL DEFAULT 0,
    tools_found   INTEGER NOT NULL DEFAULT 0,
    token_hash    VARCHAR(64) NOT NULL DEFAULT '',
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
SQL);

        // Журнал читают двумя способами: «последние запросы» и «сгруппировать
        // по norm за период». Оба обязаны быть дешёвыми даже на сотнях тысяч строк.
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_search_log_created_idx
            ON nj_676_search_log (created_at DESC);');
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_search_log_norm_idx
            ON nj_676_search_log (norm, created_at DESC);');
    }

    public function down()
    {
        // Журнал — источник тем автосборки: откат удалил бы историю спроса,
        // по которой каталог добирается ночью. Не откатываем.
    }
}
