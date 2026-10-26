<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Store — часть `Votes`: голоса посетителей «согласен / спорно» — ими карточка помечается спорной.
 *
 * Трейт класса `Service_Nj646_Store` (см. его шапку): разделы вынесены из одного
 * файла, публичные имена методов не менялись. Свойства и общие помощники
 * (`self::db()`, `self::_all()`, `self::ensure_*()`…) живут в самом классе.
 */
trait Service_Nj646_Store_Votes
{

    /** Голос/правка посетителя: одна на вещество. */
    public static function vote($ingredient_id, $visitor, $value, $note = '')
    {
        self::ensure_tables();

        $value = $value >= 0 ? 1 : -1;
        $db    = self::db();

        DB::query(NULL, 'INSERT INTO nj_646_votes (ingredient_id, visitor, value, note)
                         VALUES ('.(int) $ingredient_id.', '.$db->quote((string) $visitor).',
                                 '.(int) $value.', '.$db->quote(mb_substr((string) $note, 0, 400)).')
                         ON CONFLICT ("ingredient_id", "visitor") DO UPDATE SET
                             value = EXCLUDED.value, note = EXCLUDED.note, created_at = now()')->execute();
    }

    /** Сводка правок по веществу. */
    public static function votes($ingredient_id)
    {
        return self::_one('SELECT
                COUNT(*) FILTER (WHERE value > 0) AS agree,
                COUNT(*) FILTER (WHERE value < 0) AS dispute,
                COUNT(*) AS total
            FROM nj_646_votes WHERE ingredient_id = '.(int) $ingredient_id, NULL);
    }

    public static function votes_recent($limit = 20)
    {
        self::ensure_tables();

        return self::_all('SELECT v.*, i.name_ru, i.slug
                           FROM nj_646_votes v JOIN nj_646_ingredients i ON i.id = v.ingredient_id
                           ORDER BY v.id DESC LIMIT '.(int) max(1, min(80, $limit)));
    }
}
