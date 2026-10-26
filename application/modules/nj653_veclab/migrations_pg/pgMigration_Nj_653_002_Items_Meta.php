<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция NJ-653 (002): отпечаток элемента и паспорт исходной картинки.
 *
 * Зачем. Коллекция набивалась дублями: один и тот же файл, добавленный
 * дважды (а при массовой загрузке — десять раз), давал десять почти
 * одинаковых точек, и поиск начинал возвращать одно и то же. Плюс о самой
 * картинке в базе не оставалось НИЧЕГО: превью 96 px и подпись — ни имени
 * файла, ни исходного размера, ни даты съёмки.
 *
 * Что добавляем:
 *   fingerprint — sha256 содержимого (байты картинки либо нормализованный
 *                 текст). Частичный УНИКАЛЬНЫЙ индекс по (visitor,
 *                 fingerprint) не даёт положить второй такой же элемент;
 *   meta        — паспорт исходника: имя файла, тип, размер в байтах,
 *                 ширина/высота оригинала и то, что прислал браузер.
 *
 * Ширину колонки `embedding` НЕ трогаем: она задана миграцией 001 и меняется
 * только новой таблицей. Миграция идемпотентна (IF NOT EXISTS) и безопасна
 * для уже заполненной коллекции: у старых строк fingerprint пустой, и
 * уникальный индекс их не касается.
 */
class pgMigration_Nj_653_002_Items_Meta extends pgMigration {

    public function up()
    {
        $this->run_query("ALTER TABLE nj_653_items
                          ADD COLUMN IF NOT EXISTS fingerprint varchar(64) NOT NULL DEFAULT '';");
        $this->run_query("ALTER TABLE nj_653_items
                          ADD COLUMN IF NOT EXISTS meta jsonb NOT NULL DEFAULT '{}'::jsonb;");

        // Уникальность — только там, где отпечаток посчитан: строки из
        // прошлой жизни (fingerprint = '') индекс не трогает.
        $this->run_query("CREATE UNIQUE INDEX IF NOT EXISTS nj_653_items_fingerprint_uidx
                          ON nj_653_items (visitor, fingerprint)
                          WHERE fingerprint <> '';");

        $this->run_query("COMMENT ON COLUMN nj_653_items.fingerprint IS
            'sha256 содержимого (байты картинки или нормализованный текст): защита от дублей в пределах visitor';");
        $this->run_query("COMMENT ON COLUMN nj_653_items.meta IS
            'Паспорт исходника: имя файла, mime, размер в байтах, ширина/высота, сведения браузера';");
    }

    public function down()
    {
        $this->run_query('DROP INDEX IF EXISTS nj_653_items_fingerprint_uidx;');
        $this->run_query('ALTER TABLE nj_653_items DROP COLUMN IF EXISTS meta;');
        $this->run_query('ALTER TABLE nj_653_items DROP COLUMN IF EXISTS fingerprint;');
    }
}
