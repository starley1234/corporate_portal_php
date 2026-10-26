<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Store — часть `Ingredients`: карточки веществ: чтение, поиск, черновики и публикация, счётчик встреч, выгрузка.
 *
 * Трейт класса `Service_Nj646_Store` (см. его шапку): разделы вынесены из одного
 * файла, публичные имена методов не менялись. Свойства и общие помощники
 * (`self::db()`, `self::_all()`, `self::ensure_*()`…) живут в самом классе.
 */
trait Service_Nj646_Store_Ingredients
{

    /**
     * Индекс для сопоставления: строки по slug и плоская карта ключей.
     *
     * @param bool $fresh сбросить кеш (после правок базы)
     */
    public static function index($fresh = FALSE)
    {
        if (self::$_index !== NULL AND ! $fresh)
        {
            return self::$_index;
        }

        self::ensure_tables();

        $rows = self::_all('SELECT id, slug, name_ru, name_int, kind, risk, verdict, category,
                                   e_code, summary, hits, details, synonyms, avoid, sources, source,
                                   status, confidence, hits
                            FROM nj_646_ingredients
                            WHERE status <> \'hidden\'
                            ORDER BY id');

        $out = ['rows' => [], 'map' => []];

        foreach ($rows as $row)
        {
            $row['synonyms'] = self::_json_decode($row['synonyms']);
            $row['avoid']    = self::_json_decode($row['avoid']);
            $row['sources']  = self::_json_decode($row['sources']);

            $keys = self::ingredient_keys($row);
            $row['keys'] = $keys;

            $out['rows'][$row['slug']] = $row;

            foreach ($keys as $key)
            {
                if ( ! isset($out['map'][$key]))
                {
                    $out['map'][$key] = $row['slug'];
                }
            }
        }

        return self::$_index = $out;
    }

    protected static function _json_decode($value)
    {
        if (is_array($value))
        {
            return $value;
        }

        $decoded = json_decode((string) $value, TRUE);

        return is_array($decoded) ? $decoded : [];
    }

    public static function ingredient($slug)
    {
        self::ensure_tables();

        $row = self::_one('SELECT * FROM nj_646_ingredients WHERE slug = '.self::db()->quote((string) $slug));

        if ( ! $row)
        {
            return NULL;
        }

        foreach (['synonyms', 'avoid', 'sources'] as $field)
        {
            $row[$field] = self::_json_decode($row[$field]);
        }

        return $row;
    }

    public static function ingredient_by_id($id)
    {
        self::ensure_tables();

        return self::_one('SELECT * FROM nj_646_ingredients WHERE id = '.(int) $id);
    }

    /** Поиск по базе: имя, синоним, категория, E-код. */
    public static function ingredient_search($query, $limit = 12)
    {
        self::ensure_tables();

        $query = trim((string) $query);

        if ($query === '')
        {
            return self::_all('SELECT id, slug, name_ru, name_int, kind, risk, verdict, category, e_code, summary, hits
                               FROM nj_646_ingredients WHERE status = \'published\'
                               ORDER BY hits DESC, id LIMIT '.(int) max(1, min(30, $limit)));
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($query)).'%';

        return self::_all('SELECT id, slug, name_ru, name_int, kind, risk, verdict, category, e_code, summary, hits
                           FROM nj_646_ingredients
                           WHERE status = \'published\'
                             AND (lower(name_ru) LIKE '.self::db()->quote($like).'
                                  OR lower(name_int) LIKE '.self::db()->quote($like).'
                                  OR lower(category) LIKE '.self::db()->quote($like).'
                                  OR lower(e_code) LIKE '.self::db()->quote($like).'
                                  OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(synonyms) s
                                             WHERE lower(s) LIKE '.self::db()->quote($like).'))
                           ORDER BY hits DESC, id LIMIT '.(int) max(1, min(30, $limit)));
    }

    /**
     * Засев/обновление карточки из конфига. Пользовательские и ИИ-карточки
     * (source = user|llm) конфигом не перетираются — их правит админка.
     */
    public static function seed_ingredient(array $row)
    {
        self::ensure_tables();

        $slug = (string) ($row['slug'] ?? '');

        if ($slug === '')
        {
            return NULL;
        }

        $db = self::db();

        $sql = 'INSERT INTO nj_646_ingredients
                    (slug, name_ru, name_int, kind, risk, verdict, category, e_code,
                     summary, details, synonyms, avoid, sources, source, status, confidence, updated_at)
                VALUES (
                    '.$db->quote($slug).', '.$db->quote((string) ($row['ru'] ?? '')).',
                    '.$db->quote((string) ($row['int'] ?? '')).', '.$db->quote((string) ($row['kind'] ?? 'both')).',
                    '.(int) ($row['risk'] ?? 1).', '.$db->quote((string) ($row['verdict'] ?? 'neutral')).',
                    '.$db->quote((string) ($row['cat'] ?? '')).', '.$db->quote((string) ($row['e'] ?? '')).',
                    '.$db->quote(mb_substr((string) ($row['sum'] ?? ''), 0, 400)).',
                    '.$db->quote((string) ($row['det'] ?? '')).',
                    '.self::_json($row['syn'] ?? []).', '.self::_json($row['avoid'] ?? []).',
                    '.self::_json($row['src'] ?? []).', \'seed\', \'published\', 100, now())
                ON CONFLICT ("slug") DO UPDATE SET
                    name_ru = EXCLUDED.name_ru,
                    name_int = EXCLUDED.name_int,
                    kind = EXCLUDED.kind,
                    risk = EXCLUDED.risk,
                    verdict = EXCLUDED.verdict,
                    category = EXCLUDED.category,
                    e_code = EXCLUDED.e_code,
                    summary = EXCLUDED.summary,
                    details = EXCLUDED.details,
                    synonyms = EXCLUDED.synonyms,
                    avoid = EXCLUDED.avoid,
                    sources = EXCLUDED.sources,
                    updated_at = now()
                WHERE nj_646_ingredients.source = \'seed\'';

        DB::query(NULL, $sql)->execute();

        return self::ingredient($slug);
    }

    /**
     * Новая карточка вещества — из импорта словаря (CSV).
     *
     * Отличие от `seed_ingredient()`: тот засевает стартовый набор из конфига и
     * переписывает только свои карточки (`source = 'seed'`), а здесь карточка
     * создаётся один раз и живёт сама. Отличие от `ingredient_draft()`: это не
     * черновик модели, а осознанная строка файла владельца, поэтому статус
     * приходит снаружи (по умолчанию `published` — админ уже посмотрел план).
     *
     * @param  array $row поля карточки (slug обязателен)
     * @return int|null id созданной карточки (NULL — слаг занят или пуст)
     */
    public static function ingredient_create(array $row)
    {
        self::ensure_tables();

        $slug = trim((string) ($row['slug'] ?? ''));

        if ($slug === '' OR ! preg_match('/^[a-z0-9._-]{1,120}$/i', $slug))
        {
            return NULL;
        }

        $kind    = in_array(($row['kind'] ?? ''), ['food', 'cosmetic', 'both'], TRUE) ? (string) $row['kind'] : 'both';
        $verdict = in_array(($row['verdict'] ?? ''), ['useful', 'neutral', 'caution', 'risky', 'avoid'], TRUE)
            ? (string) $row['verdict'] : 'neutral';
        $status  = in_array(($row['status'] ?? ''), ['published', 'draft', 'hidden'], TRUE) ? (string) $row['status'] : 'published';

        $db = self::db();

        $sql = 'INSERT INTO nj_646_ingredients
                    (slug, name_ru, name_int, kind, risk, verdict, category, e_code,
                     summary, details, synonyms, avoid, sources, source, status, confidence, updated_at)
                VALUES (
                    '.$db->quote($slug).', '.$db->quote(mb_substr((string) ($row['name_ru'] ?? ''), 0, 200)).',
                    '.$db->quote(mb_substr((string) ($row['name_int'] ?? ''), 0, 200)).',
                    '.$db->quote($kind).', '.(int) ($row['risk'] ?? 1).', '.$db->quote($verdict).',
                    '.$db->quote(mb_substr((string) ($row['category'] ?? ''), 0, 60)).',
                    '.$db->quote(mb_substr((string) ($row['e_code'] ?? ''), 0, 16)).',
                    '.$db->quote(mb_substr((string) ($row['summary'] ?? ''), 0, 400)).',
                    '.$db->quote((string) ($row['details'] ?? '')).',
                    '.self::_json($row['synonyms'] ?? []).', '.self::_json($row['avoid'] ?? []).',
                    '.self::_json($row['sources'] ?? []).', \'csv\', '.$db->quote($status).',
                    '.(int) ($row['confidence'] ?? 100).', now())
                ON CONFLICT ("slug") DO NOTHING
                RETURNING id';

        // INSERT … RETURNING выполняется как SELECT-запрос (грабли модуля):
        // иначе драйвер вернёт число строк, а не саму строку.
        $res = $db->query(Database::SELECT, $sql, FALSE)->current();

        self::$_index = NULL;

        return $res ? (int) $res['id'] : NULL;
    }
}
