<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646/005: пищевая ценность товара (nj646_label).
 *
 * Владелец (03.10.2026): «добавь функционал сохранения пищевой ценности (для
 * продуктов)». Таблица «белки/жиры/углеводы/калорийность» есть почти на каждой
 * упаковке еды и отвечает на половину вопросов покупателя быстрее состава.
 * Появляются две колонки у `nj_646_products`:
 *
 *   * `nutrition` JSONB — сама таблица: основа («на 100 г»/«на 100 мл»/порция),
 *     размер порции и значения полей (ккал, кДж, белки, жиры, насыщенные,
 *     углеводы, сахара, волокна, соль, натрий). JSONB, потому что полей
 *     десяток и половина обычно пустая — десять колонок были бы пустым местом;
 *   * `nutrition_source` — `label` (разобрано с этикетки без модели,
 *     `Service_Nj646_About::nutrition()`) или `manual` (заполнено админом).
 *     Правку руками этикетка больше не перетирает: OCR ошибается, человек —
 *     последнее слово.
 *
 * DDL идентичен `Service_Nj646_Store::_indexes_ddl()` (образец — миграции 003 и
 * 004: миграция и ensure_* описывают одну и ту же схему). Накат идемпотентен.
 */
class pgMigration_Nj_646_005_Nutrition extends pgMigration
{
    public function up()
    {
        $this->run_query(Service_Nj646_Store::_indexes_ddl());
    }

    public function down()
    {
        foreach (['nutrition', 'nutrition_source'] as $column)
        {
            $this->run_query('ALTER TABLE "nj_646_products" DROP COLUMN IF EXISTS "'.$column.'";');
        }
    }
}
