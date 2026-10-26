<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646/002: товары и производители с этикеток (nj646_label).
 *
 * Владелец (03.10.2026): «сопутствующую информацию о производителе и товаре с
 * этикетки тоже в БД сохраняй (для проекта в целом)».
 *
 * Что появляется:
 *   * `nj_646_producers` — организации с этикетки (изготовитель, импортёр):
 *     одна запись на организацию (`name_key`), реквизиты проверяются
 *     контрольными цифрами, связь с онтологией предприятий (nj607) и
 *     справочником организаций (nj594) — по ИНН/ОГРН.
 *   * `nj_646_products` — товары: штрихкод/EAN, масса, ГОСТ или ТУ, срок
 *     годности, условия хранения, страна. Ключ — штрихкод, иначе «бренд +
 *     название».
 *   * колонки `producer_id`, `product_id`, `about` у `nj_646_analyses` —
 *     привязка разбора к товару и снимок того, что было на этикетке.
 *
 * DDL идентичен `Service_Nj646_Store::_goods_ddl()` (образец — nj594: миграция
 * и ensure_tables описывают одну и ту же схему). Накат идемпотентен.
 */
class pgMigration_Nj_646_002_Goods extends pgMigration
{
    public function up()
    {
        $this->run_query(Service_Nj646_Store::_goods_ddl());
    }

    public function down()
    {
        foreach (['nj_646_products', 'nj_646_producers'] as $table)
        {
            $this->run_query('DROP TABLE IF EXISTS "'.$table.'" CASCADE;');
        }

        foreach (['producer_id', 'product_id', 'about'] as $column)
        {
            $this->run_query('ALTER TABLE "nj_646_analyses" DROP COLUMN IF EXISTS "'.$column.'";');
        }
    }
}
