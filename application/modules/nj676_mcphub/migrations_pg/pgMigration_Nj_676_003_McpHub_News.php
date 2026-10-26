<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Новости платформы о заметной возможности (правило nj604): каталог MCP теперь
 * собирается сам, а агенты подключаются к нему одним адресом.
 *
 * Идемпотентно: повторный deploy не создаёт дубликаты (проверка по заголовку).
 */
class pgMigration_Nj_676_003_McpHub_News extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
INSERT INTO "nj_604_news" (kind, title, body_md, href, tag, published, published_at)
SELECT 'manual', 'Каталог MCP-серверов теперь собирается сам',
       'В MCP Hub достаточно нажать «Найти и добавить»: воркер ищет проекты на GitHub и в поиске, читает README, определяет пакет, удалённый адрес и нужные ключи, пишет русское описание и проверяет удалённые серверы вызовом tools/list. Карточки публикуются сразу; необязательное поле «Тематика» сужает поиск.',
       '/mcphub', 'MCP', TRUE, now()
WHERE NOT EXISTS (
    SELECT 1 FROM "nj_604_news" WHERE title = 'Каталог MCP-серверов теперь собирается сам'
);

INSERT INTO "nj_604_news" (kind, title, body_md, href, tag, published, published_at)
SELECT 'manual', 'Один MCP-адрес для агента: поиск, вызов и настройка',
       'Агенту больше не нужны десятки конфигураций: POST /mcphub/api/rpc даёт четыре корневых инструмента — поиск возможностей, вызов, статус и настройку фильтров. Схемы остальных инструментов подгружаются по требованию, поэтому контекст не расходуется на сотни описаний. Ключи — те же, что на /mcp.',
       '/mcphub', 'ИИ-агенты', TRUE, now()
WHERE NOT EXISTS (
    SELECT 1 FROM "nj_604_news" WHERE title = 'Один MCP-адрес для агента: поиск, вызов и настройка'
);
SQL);
    }

    public function down()
    {
        $this->run_query("DELETE FROM \"nj_604_news\" WHERE title IN (
            'Каталог MCP-серверов теперь собирается сам',
            'Один MCP-адрес для агента: поиск, вызов и настройка');");
    }
}
