<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Новость о 10 новых инженерных сервисах (nj623–nj632).
 * INSERT идемпотентен: повторный deploy не создаёт дубли.
 */
class pgMigration_Nj_604_003_DevTools_News extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
INSERT INTO "nj_604_news" (kind, title, body_md, href, tag, published, published_at)
SELECT 'manual', 'Набор инженерных инструментов пополнился', 'Десять новых одностраничных сервисов: римские числа, системы счисления, единицы информации, Unicode-анализатор, тестовые данные с корректными контрольными цифрами, проверка контрольных сумм, конвертер цветов, IP-калькулятор подсетей, текстовые операции и CSV ↔ JSON. У каждого — мгновенный расчёт в браузере и серверный JSON API для интеграций.', '/s/dev', 'инструменты', TRUE, now()
WHERE NOT EXISTS (
    SELECT 1 FROM "nj_604_news" WHERE title = 'Набор инженерных инструментов пополнился'
);
SQL);
    }

    public function down()
    {
        $this->run_query("DELETE FROM \"nj_604_news\" WHERE title = 'Набор инженерных инструментов пополнился';");
    }
}
