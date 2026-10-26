<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Новость о сервисе «CAD-детали» (/cad, модуль nj643_cad).
 *
 * INSERT идемпотентен: повторный deploy не создаёт дубли.
 */
class pgMigration_Nj_604_007_Cad_News extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
INSERT INTO "nj_604_news" (kind, title, body_md, href, tag, published, published_at)
SELECT 'manual', 'CAD-детали: 3D-модель по описанию словами',
       'Новый сервис «CAD-детали»: опишите деталь словами — ИИ напишет параметрическую модель (build123d), внешний CAD-движок проверит геометрию и крайние значения размеров и соберёт STEP, STL, GLB и ракурсы. Размеры потом крутятся ползунками с пересборкой, а правки можно просить словами («сделай отверстия Ø6»). Файлы хранятся на сайте, ссылкой на деталь можно поделиться.',
       '/cad', 'сервисы', TRUE, now()
WHERE NOT EXISTS (
    SELECT 1 FROM "nj_604_news" WHERE title = 'CAD-детали: 3D-модель по описанию словами'
);
SQL);
    }

    public function down()
    {
        $this->run_query("DELETE FROM \"nj_604_news\" WHERE title = 'CAD-детали: 3D-модель по описанию словами';");
    }
}
