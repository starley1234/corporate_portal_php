<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646/006: прежние ключи товара (`nj_646_products.key_aliases`).
 *
 * Ключ товара считается от названия и бренда, а название правят руками в
 * админке и уточняет модель задачей `title`. После такой правки следующий
 * разбор ТОЙ ЖЕ этикетки считал другой ключ и заводил ВТОРОЙ товар: разборы,
 * счётчик встреч и пищевая ценность оставались на первом, а человек видел
 * дубль и шёл в «слияние дублей» чинить то, что сломалось само (поймано на
 * живом стенде 03.10.2026).
 *
 * Теперь прежний ключ остаётся в `key_aliases`, и поиск товара идёт
 * «ключ → псевдоним»; GIN-индекс нужен, чтобы `@>` не читал таблицу целиком.
 *
 * DDL идентичен `Service_Nj646_Store::_indexes_ddl()` (образец — миграции
 * 003–005): миграция и `ensure_indexes()` обязаны описывать одну схему.
 */
class pgMigration_Nj_646_006_ProductKeyAliases extends pgMigration
{
    public function up()
    {
        $this->run_query(Service_Nj646_Store::_indexes_ddl());
    }

    public function down()
    {
        $this->run_query('DROP INDEX IF EXISTS "nj_646_products_aliases_idx";');
        $this->run_query('ALTER TABLE "nj_646_products" DROP COLUMN IF EXISTS "key_aliases";');
    }
}
