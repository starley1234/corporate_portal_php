<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646/004: товар — главная сущность списков (nj646_label).
 *
 * Владелец (03.10.2026): «Ты не определяешь товар… в списках показывать разбор
 * ТОВАРА, а не „63/100 · нейтрально“». Появляются:
 *   * `nj_646_analyses.title_auto` — название придумано модулем («Мыло без
 *     названия»), а не найдено на этикетке; по нему списки показывают пометку,
 *     а `analysis_title_set()` меняет только рабочее имя;
 *   * у `nj_646_products` — тип продукта, тот же признак придуманного названия
 *     и итог последнего разбора (`last_analysis_id/last_score/last_verdict`),
 *     счётчик разборов: строка списка собирается по товару, без джойна к
 *     последней записи разбора;
 *   * индекс `nj_646_products_kind_idx` — отбор товаров по типу.
 *
 * DDL идентичен `Service_Nj646_Store::_indexes_ddl()` (образец — nj594: миграция
 * и ensure_tables описывают одну и ту же схему). Накат идемпотентен; 003 в
 * одиночку уже накатанным базам новые колонки не приносит.
 */
class pgMigration_Nj_646_004_Products extends pgMigration
{
    public function up()
    {
        $this->run_query(Service_Nj646_Store::_indexes_ddl());
    }

    public function down()
    {
        $this->run_query('DROP INDEX IF EXISTS "nj_646_products_kind_idx";');

        foreach (['product_kind', 'name_auto', 'last_analysis_id', 'last_score', 'last_verdict', 'analyses_count'] as $column)
        {
            $this->run_query('ALTER TABLE "nj_646_products" DROP COLUMN IF EXISTS "'.$column.'";');
        }

        $this->run_query('ALTER TABLE "nj_646_analyses" DROP COLUMN IF EXISTS "title_auto";');
    }
}
