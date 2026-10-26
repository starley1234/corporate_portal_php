<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646/003: тип продукта и индексы под рост данных (nj646_label).
 *
 * Владелец (03.10.2026): «в БД — чтобы не тормозило, когда ингредиентов будут
 * тысячи и сотни продуктов и сотни пользователей». Появляются:
 *   * `nj_646_analyses.product_kind` — тип продукта, который модуль определяет
 *     сам («зубная паста», «печенье», «пиво»): ярлык для списка разборов,
 *     статистики и будущих сравнений товаров;
 *   * индексы под конкретные выборки модуля: разборы посетителя, часто
 *     встречающиеся вещества и пресеты справочника, товары по встречаемости,
 *     очередь изучения. Индексы по товару, производителю и названиям уже стоят
 *     в миграции 002 — второй раз их не создаём.
 *
 * DDL идентичен `Service_Nj646_Store::_indexes_ddl()` (образец — nj594: миграция
 * и ensure_tables описывают одну и ту же схему). Накат идемпотентен.
 */
class pgMigration_Nj_646_003_Scale extends pgMigration
{
    public function up()
    {
        $this->run_query(Service_Nj646_Store::_indexes_ddl());
    }

    public function down()
    {
        foreach ([
            'nj_646_analyses_person_idx', 'nj_646_analyses_kind_idx',
            'nj_646_ingredients_status_idx', 'nj_646_ingredients_category_idx',
            'nj_646_ingredients_risk_idx', 'nj_646_ingredients_source_idx',
            'nj_646_products_hits_idx', 'nj_646_unknowns_queue_idx',
        ] as $index)
        {
            $this->run_query('DROP INDEX IF EXISTS "'.$index.'";');
        }

        $this->run_query('ALTER TABLE "nj_646_analyses" DROP COLUMN IF EXISTS "product_kind";');
    }
}
