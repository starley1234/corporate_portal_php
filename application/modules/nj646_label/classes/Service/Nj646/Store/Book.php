<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Store — часть `Book`: справочник деревом: уровни каталога, пресеты, правка карточки, похожие вещества.
 *
 * Трейт класса `Service_Nj646_Store` (см. его шапку): разделы вынесены из одного
 * файла, публичные имена методов не менялись. Свойства и общие помощники
 * (`self::db()`, `self::_all()`, `self::ensure_*()`…) живут в самом классе.
 */
trait Service_Nj646_Store_Book
{

    /**
     * Как строить дерево справочника: режим → уровни группировки.
     *
     * Владелец (03.10.2026): «страница для админа для просмотра сформировавшегося
     * справочника веществ — удобное иерархическое дерево (выбор разных вариантов
     * построения дерева по разным критериям)… (элементов, наверное, будет
     * тысячи) — назначение просмотр справочника и удобное редактирование».
     *
     * Каждый режим — своя логика разбиения, поэтому «по каким критериям» это
     * не одна и та же выборка с разной сортировкой, а разные ветки: по
     * категории (вид → категория), по риску (риск → вердикт), по статусу
     * модерации, по источнику карточки, по встречаемости, по алфавиту и по
     * E-кодам. Уровни описываются SQL-выражением: по нему строится и группировка
     * (`GROUP BY`), и фильтр развёрнутых ветвей (равенство по предыдущим
     * уровням), поэтому новых запросов под каждый уровень писать не нужно.
     *
     * @return array режим => ['label', 'hint', 'levels' => [['expr','labels','sort']]]
     */
    public static function catalog_modes()
    {
        return [
            'category' => [
                'label'  => 'По категориям',
                'hint'   => 'вид продукта → категория → вещество',
                'levels' => [
                    [
                        'expr'   => "COALESCE(NULLIF(btrim(kind), ''), 'both')",
                        'labels' => ['food' => 'Еда', 'cosmetic' => 'Косметика', 'both' => 'Универсальные'],
                    ],
                    [
                        'expr'   => "COALESCE(NULLIF(btrim(category), ''), '— без категории')",
                        'labels' => [],
                    ],
                ],
            ],
            'risk' => [
                'label'  => 'По риску',
                'hint'   => 'балл риска → вердикт → вещество',
                'levels' => [
                    [
                        'expr'   => 'risk::text',
                        'labels' => [0 => '0 · без замечаний', 1 => '1 · скорее безопасно',
                                     2 => '2 · спорное', 3 => '3 · нежелательно', 4 => '4 · избегать'],
                        'sort'   => 'MIN(risk)',
                    ],
                    [
                        'expr'   => "COALESCE(NULLIF(btrim(verdict), ''), 'neutral')",
                        'labels' => ['useful' => 'полезное', 'neutral' => 'нейтральное',
                                     'caution' => 'осторожно', 'risky' => 'рискованное', 'avoid' => 'избегать'],
                    ],
                ],
            ],
            'status' => [
                'label'  => 'По модерации',
                'hint'   => 'статус → вид продукта → вещество (что ещё не проверено)',
                'levels' => [
                    [
                        'expr'   => "COALESCE(NULLIF(btrim(status), ''), 'draft')",
                        'labels' => ['published' => 'Опубликовано', 'draft' => 'Черновики (ждёт проверки)',
                                     'hidden' => 'Скрыто'],
                        'sort'   => "MIN(CASE status WHEN 'draft' THEN 0 WHEN 'published' THEN 1 ELSE 2 END)",
                    ],
                    [
                        'expr'   => "COALESCE(NULLIF(btrim(kind), ''), 'both')",
                        'labels' => ['food' => 'Еда', 'cosmetic' => 'Косметика', 'both' => 'Универсальные'],
                    ],
                ],
            ],
            'source' => [
                'label'  => 'По источнику',
                'hint'   => 'кто собрал карточку → статус → вещество',
                'levels' => [
                    [
                        'expr'   => "COALESCE(NULLIF(btrim(source), ''), 'seed')",
                        'labels' => ['seed' => 'Стартовая база', 'llm' => 'Изучено ИИ',
                                     'user' => 'Правки посетителей', 'admin' => 'Правки админа'],
                    ],
                    [
                        'expr'   => "COALESCE(NULLIF(btrim(status), ''), 'draft')",
                        'labels' => ['published' => 'Опубликовано', 'draft' => 'Черновик', 'hidden' => 'Скрыто'],
                        'sort'   => "MIN(CASE status WHEN 'draft' THEN 0 WHEN 'published' THEN 1 ELSE 2 END)",
                    ],
                ],
            ],
            'hits' => [
                'label'  => 'По встречаемости',
                'hint'   => 'как часто встречается в составах → статус → вещество',
                'levels' => [
                    [
                        'expr'   => "CASE WHEN hits >= 10 THEN 'hot' WHEN hits >= 3 THEN 'common' "
                                     ."WHEN hits >= 1 THEN 'rare' ELSE 'never' END",
                        'labels' => ['hot' => 'Частые (10+)', 'common' => 'Заметные (3–9)',
                                     'rare' => 'Редкие (1–2)', 'never' => 'Не встречались'],
                        'sort'   => "MIN(CASE WHEN hits >= 10 THEN 0 WHEN hits >= 3 THEN 1 "
                                     ."WHEN hits >= 1 THEN 2 ELSE 3 END)",
                    ],
                    [
                        'expr'   => "COALESCE(NULLIF(btrim(status), ''), 'draft')",
                        'labels' => ['published' => 'Опубликовано', 'draft' => 'Черновик', 'hidden' => 'Скрыто'],
                    ],
                ],
            ],
            'alpha' => [
                'label'  => 'По алфавиту',
                'hint'   => 'первая буква названия → вещество (быстро найти знакомое)',
                'levels' => [
                    [
                        'expr'   => "upper(left(COALESCE(NULLIF(btrim(name_ru), ''), btrim(name_int)), 1))",
                        'labels' => ['' => 'прочее'],
                    ],
                ],
            ],
            'ecode' => [
                'label'  => 'По E-кодам',
                'hint'   => 'есть E-код или нет → группа E → вещество',
                'levels' => [
                    [
                        'expr'   => "CASE WHEN COALESCE(btrim(e_code), '') = '' THEN 'no' ELSE 'yes' END",
                        'labels' => ['yes' => 'С E-кодом', 'no' => 'Без E-кода'],
                        'sort'   => "MIN(CASE WHEN COALESCE(btrim(e_code), '') = '' THEN 1 ELSE 0 END)",
                    ],
                    [
                        'expr'   => "upper(left(btrim(e_code), 2))",
                        'labels' => ['' => '— без E-кода'],
                    ],
                ],
            ],
        ];
    }

    /** Режим дерева или безопасный запасной (неизвестный ключ не роняет страницу). */
    protected static function _catalog_spec($mode)
    {
        $modes = self::catalog_modes();
        $mode  = (string) $mode;

        return isset($modes[$mode]) ? $modes[$mode] : $modes['category'];
    }

    /** Условие WHERE по уже развёрнутым уровням дерева (равенство по выражению). */
    protected static function _catalog_where(array $spec, array $path)
    {
        $where = [];

        foreach (array_values($path) as $i => $value)
        {
            if ( ! isset($spec['levels'][$i]))
            {
                break;
            }

            // Значение пришло из браузера: сравниваем как текст, кавычки — драйвер.
            $where[] = '('.$spec['levels'][$i]['expr'].') = '.self::db()->quote((string) $value);
        }

        return $where ? implode(' AND ', $where) : 'TRUE';
    }

    /**
     * Ветви дерева на одном уровне: подписи и размеры.
     *
     * @param string $mode  режим из catalog_modes()
     * @param array  $path  выбранные значения предыдущих уровней
     *
     * @return array [['key','label','total'], ...]
     */
    public static function catalog_groups($mode, array $path = [], $limit = 300)
    {
        self::ensure_tables();

        $spec  = self::_catalog_spec($mode);
        $level = count($path);

        if ( ! isset($spec['levels'][$level]))
        {
            return [];
        }

        $def    = $spec['levels'][$level];
        $where  = self::_catalog_where($spec, $path);
        $sort   = (string) ($def['sort'] ?? 'MIN('.$def['expr'].')');
        $labels = (array) ($def['labels'] ?? []);

        $rows = self::_all('SELECT '.$def['expr'].' AS gkey, COUNT(*) AS total
                             FROM nj_646_ingredients
                            WHERE '.$where.'
                            GROUP BY 1 ORDER BY '.$sort.' LIMIT '.(int) max(1, min(500, $limit)));

        $groups = [];

        foreach ($rows as $row)
        {
            $key   = (string) $row['gkey'];
            $label = array_key_exists($key, $labels) ? (string) $labels[$key] : ($key !== '' ? $key : 'прочее');

            $groups[] = ['key' => $key, 'label' => $label, 'total' => (int) $row['total']];
        }

        return $groups;
    }

    /**
     * Вещества одного узла дерева (лист) с постраничной выдачей.
     *
     * Тысячи строк в браузер не отдаём: страница подтягивает по
     * `limits.catalog_page` записей и догружает остальное кнопкой.
     *
     * @return array ['items' => [], 'total' => int, 'offset' => int, 'limit' => int]
     */
    public static function catalog_items($mode, array $path = [], $q = '', $offset = 0, $limit = 60)
    {
        self::ensure_tables();

        $spec   = self::_catalog_spec($mode);
        $where  = [self::_catalog_where($spec, $path)];
        $q      = trim((string) $q);

        if ($q !== '')
        {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)).'%';

            $where[] = '(lower(name_ru) LIKE '.self::db()->quote($like).'
                          OR lower(name_int) LIKE '.self::db()->quote($like).'
                          OR lower(category) LIKE '.self::db()->quote($like).'
                          OR lower(e_code) LIKE '.self::db()->quote($like).'
                          OR lower(summary) LIKE '.self::db()->quote($like).'
                          OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(synonyms) s
                                     WHERE lower(s) LIKE '.self::db()->quote($like).'))';
        }

        $where  = implode(' AND ', $where);
        $limit  = max(1, min(200, (int) $limit));
        $offset = max(0, (int) $offset);

        $total = (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_ingredients WHERE '.$where, 'c');

        $items = self::_all('SELECT id, slug, name_ru, name_int, kind, risk, verdict, category,
                                    e_code, status, source, confidence, hits, summary, details,
                                    synonyms, avoid, sources
                               FROM nj_646_ingredients
                              WHERE '.$where.'
                              ORDER BY name_ru, id LIMIT '.$limit.' OFFSET '.$offset);

        // jsonb-колонки драйвер отдаёт по-разному (строкой или массивом), а
        // карточка справочника показывает их списками и правит — приводим к
        // массиву здесь, чтобы странице не пришлось угадывать формат.
        foreach ($items as $i => $item)
        {
            foreach (['synonyms', 'avoid', 'sources'] as $key)
            {
                $items[$i][$key] = self::_json_decode($item[$key] ?? []);
            }
        }

        return ['items' => $items, 'total' => $total, 'offset' => $offset, 'limit' => $limit];
    }

    /**
     * Счётчики для быстрых пресетов справочника: что требует внимания.
     *
     * Одним запросом, потому что пресеты показываются рядом с деревом и должны
     * отвечать на вопрос «сколько там» до того, как админ туда пойдёт: черновики
     * (не проверено), скрытые, ничего не встречавшиеся (кандидаты на удаление),
     * высокий риск и карточки ИИ с низкой уверенностью (перепроверить).
     *
     * @return array ['drafts','hidden','never','risky','llm','unsure']
     */
    public static function catalog_counts()
    {
        self::ensure_tables();

        $c = self::_one("SELECT
            (SELECT COUNT(*) FROM nj_646_ingredients WHERE status = 'draft') AS drafts,
            (SELECT COUNT(*) FROM nj_646_ingredients WHERE status = 'hidden') AS hidden,
            (SELECT COUNT(*) FROM nj_646_ingredients WHERE source = 'llm') AS llm,
            (SELECT COUNT(*) FROM nj_646_ingredients WHERE hits = 0) AS never,
            (SELECT COUNT(*) FROM nj_646_ingredients WHERE risk >= 4) AS risky,
            (SELECT COUNT(*) FROM nj_646_ingredients
              WHERE source = 'llm' AND confidence < 60) AS unsure");

        return [
            'drafts' => (int) ($c['drafts'] ?? 0),
            'hidden' => (int) ($c['hidden'] ?? 0),
            'llm'    => (int) ($c['llm'] ?? 0),
            'never'  => (int) ($c['never'] ?? 0),
            'risky'  => (int) ($c['risky'] ?? 0),
            'unsure' => (int) ($c['unsure'] ?? 0),
        ];
    }

    /** Сколько всего веществ в справочнике (подпись к дереву). */
    public static function ingredients_count()
    {
        self::ensure_tables();

        return (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_ingredients', 'c');
    }

    /**
     * Удалить карточку вещества.
     *
     * Связанные незнакомцы возвращаются в очередь (`new`): вещество снова
     * можно изучить, а «висящая» ссылка на удалённую карточку ломала бы
     * показ статуса. Строку из очереди не удаляем — история встреч остаётся.
     *
     * @return array|null ['name','source'] удалённой карточки (NULL — не нашли)
     */
    public static function ingredient_delete($id)
    {
        self::ensure_tables();

        $row = self::ingredient_by_id($id);

        if ( ! $row)
        {
            return NULL;
        }

        DB::query(NULL, 'UPDATE nj_646_unknowns
                            SET status = \'new\', ingredient_id = NULL, run_id = NULL, last_seen = now()
                          WHERE ingredient_id = '.(int) $id)->execute();

        DB::query(NULL, 'DELETE FROM nj_646_ingredients WHERE id = '.(int) $id)->execute();

        self::$_index = NULL;

        return ['name' => (string) $row['name_ru'], 'source' => (string) $row['source']];
    }

    /**
     * Переизучить вещество: поставить модель заново и ЗАМЕНИТЬ карточку.
     *
     * Владелец (03.10.2026): «удобное редактирование (удалить, переизучить и
     * т.п.)». Обычное изучение карточку не трогает (`ingredient_draft` не
     * перезаписывает существующую) — иначе повторный разбор затирал бы правки
     * человека. Поэтому переизучение помечает прогон флагом `refresh`: приёмка
     * обновит карточку, только если её собрала модель (`source = llm`), а
     * правки админа и посев останутся нетронутыми.
     *
     * @return array ['ok','code','message','run_id','name'] — как у study_push()
     */
    public static function ingredient_restudy($id, array $options = [])
    {
        self::ensure_tables();

        $row = self::ingredient_by_id($id);

        if ( ! $row)
        {
            return ['ok' => FALSE, 'code' => 'unknown', 'run_id' => 0,
                    'name' => '', 'message' => 'Карточка не найдена — обновите список.'];
        }

        $name = (string) $row['name_ru'];
        $kind = (string) (($row['kind'] ?? '') !== '' ? $row['kind'] : 'both');

        $touch = self::unknown_touch($name, $kind, 1);

        if ( ! $touch)
        {
            return ['ok' => FALSE, 'code' => 'error', 'run_id' => 0, 'name' => $name,
                    'message' => 'Не удалось вернуть вещество в очередь изучения.'];
        }

        // Строку очереди сбрасываем: изучение снова должно пройти целиком.
        DB::query(NULL, 'UPDATE nj_646_unknowns
                            SET status = \'new\', ingredient_id = NULL, run_id = NULL, last_seen = now()
                          WHERE id = '.(int) $touch['id'])->execute();

        $push = self::study_push((int) $touch['id'], $name, $kind, [
            'manual'      => TRUE,
            'visitor'     => (string) ($options['visitor'] ?? 'admin'),
            'user_id'     => $options['user_id'] ?? NULL,
            'refresh'     => TRUE,
            'daily_limit' => $options['daily_limit'] ?? NULL,
            'limit_key'   => $options['limit_key'] ?? 'NJ646_STUDY_PER_DAY',
        ]);

        $source = (string) ($row['source'] ?? '');

        // Говорим прямо, что станет с карточкой: модель перезаписывает ТОЛЬКО
        // свои черновики, ручную работу и посев не трогает.
        $push['message'] = (string) $push['message']
            .($source === 'llm'
                ? ' Карточка будет заменена новой, когда модель ответит.'
                : ' Карточку собрал не ИИ (посев или правка человека) — она останется как есть,'
                    .' а результат изучения придёт отдельным черновиком на проверку.');

        return $push;
    }

    /** Карточка от ИИ-изучения: черновик на модерацию. */
    public static function ingredient_draft(array $row, $refresh = FALSE)
    {
        self::ensure_tables();

        $slug = (string) ($row['slug'] ?? '');

        if ($slug === '')
        {
            $slug = 'llm-'.substr(hash('sha256', mb_strtolower((string) ($row['name_ru'] ?? ''))), 0, 12);
        }

        $db = self::db();

        // Существующую карточку ИИ не перезаписывает: только дополняет пробелы.
        $existing = self::ingredient($slug);

        if ($existing)
        {
            // Переизучение (кнопка в справочнике) — единственный случай, когда
            // модель перезаписывает карточку. И то лишь свою: правки человека
            // (`source` = user|admin) и стартовая база не перетираются никогда.
            if ($refresh AND (string) ($existing['source'] ?? '') === 'llm')
            {
                // ingredient_update() ничего не возвращает — карточку читаем
                // заново: приёмке нужен её id/slug, чтобы отметить незнакомца.
                self::ingredient_update((int) $existing['id'], [
                    'name_ru'    => (string) ($row['name_ru'] ?? ''),
                    'name_int'   => (string) ($row['name_int'] ?? ''),
                    'kind'       => (string) ($row['kind'] ?? 'both'),
                    'risk'       => (int) ($row['risk'] ?? 2),
                    'verdict'    => (string) ($row['verdict'] ?? 'caution'),
                    'category'   => (string) ($row['category'] ?? ''),
                    'e_code'     => (string) ($row['e_code'] ?? ''),
                    'summary'    => (string) ($row['summary'] ?? ''),
                    'details'    => (string) ($row['details'] ?? ''),
                    'synonyms'   => (array) ($row['synonyms'] ?? []),
                    'avoid'      => (array) ($row['avoid'] ?? []),
                    'sources'    => (array) ($row['sources'] ?? []),
                    'confidence' => (int) ($row['confidence'] ?? 60),
                    'status'     => 'draft',
                    'source'     => 'llm',
                ]);

                return self::ingredient($slug);
            }

            return $existing;
        }

        $sql = 'INSERT INTO nj_646_ingredients
                    (slug, name_ru, name_int, kind, risk, verdict, category, e_code,
                     summary, details, synonyms, avoid, sources, source, status, confidence)
                VALUES (
                    '.$db->quote($slug).', '.$db->quote(mb_substr((string) ($row['name_ru'] ?? ''), 0, 200)).',
                    '.$db->quote(mb_substr((string) ($row['name_int'] ?? ''), 0, 200)).',
                    '.$db->quote((string) ($row['kind'] ?? 'both')).', '.(int) ($row['risk'] ?? 2).',
                    '.$db->quote((string) ($row['verdict'] ?? 'caution')).',
                    '.$db->quote(mb_substr((string) ($row['category'] ?? ''), 0, 60)).',
                    '.$db->quote(mb_substr((string) ($row['e_code'] ?? ''), 0, 16)).',
                    '.$db->quote(mb_substr((string) ($row['summary'] ?? ''), 0, 400)).',
                    '.$db->quote((string) ($row['details'] ?? '')).',
                    '.self::_json(array_slice((array) ($row['synonyms'] ?? []), 0, 12)).',
                    '.self::_json(array_slice((array) ($row['avoid'] ?? []), 0, 12)).',
                    '.self::_json(array_slice((array) ($row['sources'] ?? []), 0, 8)).',
                    \'llm\', \'draft\', '.(int) max(0, min(100, (int) ($row['confidence'] ?? 60))).')
                ON CONFLICT ("slug") DO NOTHING';

        DB::query(NULL, $sql)->execute();

        self::$_index = NULL;

        return self::ingredient($slug);
    }

    /**
     * Карточки по списку slug — одним запросом (страница разбора).
     *
     * В `nj_646_analyses.items` лежит СНИМОК разбора: slug, имя, риск, вердикт
     * на тот момент. Показывать старый снимок неправильно (карточку могли
     * изучить, поправить или скрыть после) — поэтому страница разбора берёт
     * сегодняшнее состояние базы по slug'ам, а снимок служит ей подстраховкой,
     * если вещество уже удалили.
     *
     * @param array $slugs слаги из items разбора
     *
     * @return array ['slug' => строка карточки] (jsonb-поля уже массивами)
     */
    public static function ingredients_by_slugs(array $slugs)
    {
        self::ensure_tables();

        $clean = [];

        foreach ((array) $slugs as $slug)
        {
            $slug = trim((string) $slug);

            // Слаг — только то, что мы сами генерируем; чужой ввод не пропускаем.
            if ($slug !== '' AND preg_match('/^[a-z0-9._-]{1,120}$/i', $slug))
            {
                $clean[$slug] = self::db()->quote($slug);
            }
        }

        if ( ! $clean)
        {
            return [];
        }

        $rows = self::_all('SELECT id, slug, name_ru, name_int, kind, risk, verdict, category, e_code,
                                   summary, details, synonyms, avoid, sources, status, source,
                                   confidence, hits
                              FROM nj_646_ingredients
                             WHERE slug IN ('.implode(',', array_values($clean)).')');

        $out = [];

        foreach ($rows as $row)
        {
            foreach (['synonyms', 'avoid', 'sources'] as $field)
            {
                $row[$field] = self::_json_decode($row[$field] ?? []);
            }

            // Те же ключи, что и в индексе: по ним совет ищет аллергены.
            $row['keys'] = self::ingredient_keys($row);

            $out[(string) $row['slug']] = $row;
        }

        return $out;
    }

    /**
     * Ключи карточки для сопоставления с названиями из состава.
     *
     * Один и тот же набор нужен и свежему разбору (`index()`), и странице
     * разбора: совет по аллергенам ищет по этим ключам, поэтому карточки со
     * страницы разбора обязаны нести те же ключи, что и в индексе.
     *
     * @param array $row строка карточки (synonyms уже раскодирован)
     *
     * @return array нормализованные ключи без повторов
     */
    public static function ingredient_keys(array $row)
    {
        $keys = [];

        foreach ([$row['name_ru'], $row['name_int'], str_replace('-', ' ', (string) $row['slug'])] as $name)
        {
            $key = Service_Nj646_Label::normalize($name);

            if ($key !== '')
            {
                $keys[] = $key;
            }
        }

        foreach ((array) ($row['synonyms'] ?? []) as $syn)
        {
            $key = Service_Nj646_Label::normalize($syn);

            if ($key !== '')
            {
                $keys[] = $key;
            }
        }

        if ( ! empty($row['e_code']))
        {
            $keys[] = strtolower(str_replace(' ', '', (string) $row['e_code']));
        }

        return array_values(array_unique($keys));
    }

    /** +1 к числу встреч вещества (накопление «что реально кладут»). */
    public static function ingredient_hit($id)
    {
        DB::query(NULL, 'UPDATE nj_646_ingredients SET hits = hits + 1 WHERE id = '.(int) $id)->execute();
    }

    /** Публикация/скрытие карточки (админка). */
    public static function ingredient_status($id, $status)
    {
        if ( ! in_array($status, ['published', 'draft', 'hidden'], TRUE))
        {
            $status = 'draft';
        }

        DB::query(NULL, 'UPDATE nj_646_ingredients SET status = '.self::db()->quote($status).',
                        confidence = CASE WHEN '.self::db()->quote($status).' = \'published\' THEN GREATEST(confidence, 90)
                                          ELSE confidence END,
                        updated_at = now()
                    WHERE id = '.(int) $id)->execute();

        self::$_index = NULL;
    }

    /**
     * Массовая модерация: один статус для пачки карточек.
     *
     * Владелец (03.10.2026): «сделай удобнее массовые операции для админа
     * (изучить всё, замодерировать всё и т.п.) — может через селекты». Здесь
     * ровно один UPDATE на пачку: по строкам ходить нельзя — черновиков после
     * «изучить всё» приезжает десятками, и N запросов в цикле держали бы
     * страницу. Идентификаторы чистим до int и ограничиваем пачку: админка —
     * не место для неограниченных запросов по чужому вводу.
     *
     * @return int сколько карточек реально изменилось
     */
    public static function ingredient_status_many(array $ids, $status)
    {
        self::ensure_tables();

        if ( ! in_array($status, ['published', 'draft', 'hidden'], TRUE))
        {
            $status = 'draft';
        }

        $ids = self::_clean_ids($ids);

        if ( ! $ids)
        {
            return 0;
        }

        $quoted = self::db()->quote($status);

        $result = DB::query(NULL, 'UPDATE nj_646_ingredients
                                     SET status = '.$quoted.',
                                         confidence = CASE WHEN '.$quoted.' = \'published\'
                                                           THEN GREATEST(confidence, 90) ELSE confidence END,
                                         updated_at = now()
                                   WHERE id IN ('.implode(',', $ids).')')->execute();

        self::$_index = NULL;

        return (int) $result;
    }

    /** Сколько черновиков ждёт модерации (подпись «замодерировать всё»). */
    public static function drafts_count()
    {
        self::ensure_tables();

        return (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_ingredients
                                  WHERE status = \'draft\'', 'c');
    }

    /** Идентификаторы всех черновиков (для «замодерировать всё»). */
    public static function drafts_ids($limit = 200)
    {
        self::ensure_tables();

        $ids = [];

        foreach (self::_all('SELECT id FROM nj_646_ingredients WHERE status = \'draft\'
                              ORDER BY id DESC LIMIT '.(int) max(1, min(500, $limit))) as $row)
        {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /** Правка карточки из админки. */
    public static function ingredient_update($id, array $fields)
    {
        $set = [];

        if (isset($fields['name_ru']))
        {
            $set['name_ru'] = mb_substr((string) $fields['name_ru'], 0, 200);
        }

        if (isset($fields['risk']))
        {
            $set['risk'] = max(0, min(4, (int) $fields['risk']));
        }

        if (isset($fields['verdict']))
        {
            $set['verdict'] = in_array($fields['verdict'], ['useful', 'neutral', 'caution', 'risky', 'avoid'], TRUE)
                ? $fields['verdict'] : 'caution';
        }

        if (isset($fields['category']))
        {
            $set['category'] = mb_substr((string) $fields['category'], 0, 60);
        }

        if (isset($fields['summary']))
        {
            $set['summary'] = mb_substr((string) $fields['summary'], 0, 400);
        }

        if (isset($fields['details']))
        {
            $set['details'] = (string) $fields['details'];
        }

        // Ниже — поля, которые правятся ИЗ СПРАВОЧНИКА (админка, /nj646/admin/catalog):
        // там карточку можно довести до ума, не уходя на другой экран.
        if (isset($fields['name_int']))
        {
            $set['name_int'] = mb_substr((string) $fields['name_int'], 0, 200);
        }

        if (isset($fields['e_code']))
        {
            $set['e_code'] = mb_substr((string) $fields['e_code'], 0, 16);
        }

        if (isset($fields['kind']))
        {
            $set['kind'] = in_array($fields['kind'], ['food', 'cosmetic', 'both'], TRUE)
                ? (string) $fields['kind'] : 'both';
        }

        if (isset($fields['status']))
        {
            $set['status'] = in_array($fields['status'], ['published', 'draft', 'hidden'], TRUE)
                ? (string) $fields['status'] : 'draft';
        }

        if (isset($fields['confidence']))
        {
            $set['confidence'] = max(0, min(100, (int) $fields['confidence']));
        }

        foreach (['synonyms' => 12, 'avoid' => 12, 'sources' => 8] as $key => $cap)
        {
            if (isset($fields[$key]))
            {
                // jsonb в конструкторе запроса — ТОЛЬКО через DB::expr(): обычная
                // строка экранируется второй раз и PostgreSQL её не принимает.
                $set[$key] = DB::expr(self::_json(array_slice((array) $fields[$key], 0, $cap)));
            }
        }

        if ( ! $set)
        {
            return;
        }

        // Кто правил: админка и голоса посетителей помечают карточку человеком,
        // переизучение оставляет её карточкой ИИ (source='llm').
        // `csv` — карточка пришла из словаря владельца (админка, «Импорт»).
        // Источник важен не для красоты: посев (`seed_ingredient`) переписывает
        // только свои карточки, а модель — только `llm`, поэтому импортированное
        // не сотрётся ни при обновлении конфига, ни при переизучении.
        $set['source']     = in_array(($fields['source'] ?? ''), ['seed', 'llm', 'user', 'admin', 'csv'], TRUE)
            ? (string) $fields['source'] : 'user';
        $set['updated_at'] = DB::expr('now()');

        DB::update('nj_646_ingredients')->set($set)->where('id', '=', (int) $id)->execute();

        self::$_index = NULL;
    }

    /** Черновики карточек, ждущие модерации (пополняются ИИ-изучением). */
    public static function drafts_list($limit = 40)
    {
        self::ensure_tables();

        return self::_all('SELECT id, slug, name_ru, name_int, kind, risk, verdict, category,
                                  e_code, summary, hits, details, confidence, created_at
                           FROM nj_646_ingredients
                           WHERE status = \'draft\'
                           ORDER BY id DESC LIMIT '.(int) max(1, min(100, $limit)));
    }

}
