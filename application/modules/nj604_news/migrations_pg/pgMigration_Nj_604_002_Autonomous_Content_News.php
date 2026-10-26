<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Первые новости о возможностях, которые должны быть видны после миграции.
 * INSERT идемпотентен: повторный deploy не создаёт дубли.
 */
class pgMigration_Nj_604_002_Autonomous_Content_News extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
INSERT INTO "nj_604_news" (kind, title, body_md, href, tag, published, published_at)
SELECT 'manual', 'Контент-конвейер работает автономно',
       'Пустая тема теперь запускает мониторинг свежих технических, научных и коммерческих трендов. Воркер сам ищет источники, читает их через webread и готовит статью с источниками.',
       '/content', 'автоматизация', TRUE, now()
WHERE NOT EXISTS (
    SELECT 1 FROM "nj_604_news" WHERE title = 'Контент-конвейер работает автономно'
);

INSERT INTO "nj_604_news" (kind, title, body_md, href, tag, published, published_at)
SELECT 'manual', 'Контент-конвейер получил память RAG',
       'Перед написанием статьи конвейер сверяется с накопленными заметками N2J, а после успешной сборки сохраняет новую заметку в базе знаний. Свежие источники остаются главным источником фактов, поэтому база растёт без зацикливания.',
       '/content', 'база знаний', TRUE, now()
WHERE NOT EXISTS (
    SELECT 1 FROM "nj_604_news" WHERE title = 'Контент-конвейер получил память RAG'
);
SQL);
    }

    public function down()
    {
        $this->run_query("DELETE FROM \"nj_604_news\" WHERE title IN ('Контент-конвейер работает автономно', 'Контент-конвейер получил память RAG');");
    }
}
