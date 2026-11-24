<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Семантический поиск и хранилище эмбеддингов (модуль PRX-115).
 *
 * Схема работы:
 *   1. Индексация — АСИНХРОННО через очередь: index_texts() кладёт задачи
 *      `semantic.index`, python-воркер получает векторы с эмбеддинг-сервера
 *      и пишет их в prx_115_vectors напрямую (режим pg).
 *   2. Поиск — СИНХРОННО в запросе: вектор запроса (один вызов эмбеддинг-
 *      сервера, быстро) + KNN в PostgreSQL (pgvector <=> или prx_115_vec_dist).
 *
 * Другие модули НЕ пишут в prx_115_* напрямую — только через этот сервис.
 */
class Service_Prx115_Semantic {

    /** @var bool кэш «доступен ли pgvector» */
    protected static $_pgvector;

    /**
     * @var bool|null последний embed_query() взял вектор из кэша запросов.
     * Журналу поисков важно отличать «ответил кэш, 0 мс» от «сходили на
     * эмбеддинг-сервер»: иначе в карточке «Поисковые запросы» все строки
     * выглядят одинаково.
     */
    protected static $_embed_cached = NULL;

    // ---------------------------------------------------------------------
    // Коллекции
    // ---------------------------------------------------------------------

    /**
     * Создать коллекцию, если её нет, и вернуть её id.
     *
     * @param  string $name        короткое имя: 'prx401_tasks', 'prx501_docs'
     * @param  string $description описание (для людей)
     * @return int
     */
    public static function ensure_collection($name, $description = '')
    {
        $db = Database::instance();
        $name = (string) $name;

        $row = $db->query(Database::SELECT,
            "SELECT id FROM prx_115_collections WHERE name = ".$db->quote($name), FALSE)->current();

        if ($row)
            return (int) $row['id'];

        list($model, $dims) = self::_embedding_defaults();

        $result = $db->query(Database::SELECT,
            "INSERT INTO prx_115_collections (name, model, dims, description)
             VALUES (".$db->quote($name).", ".$db->quote($model).", ".(int) $dims.", ".$db->quote((string) $description).")
             ON CONFLICT (name) DO UPDATE SET name = EXCLUDED.name
             RETURNING id", FALSE);

        return (int) $result->current()['id'];
    }

    // ---------------------------------------------------------------------
    // Индексация (асинхронно, через очередь prx120)
    // ---------------------------------------------------------------------

    /**
     * Поставить тексты на индексацию.
     *
     * @param  string $collection имя коллекции
     * @param  string $entity     тип сущности: 'prx401_task', 'page', ...
     * @param  array  $items      [['id' => '12', 'text' => '...', 'metadata' => [...]], ...]
     * @param  array  $opts       опции задачи очереди (priority, callback_url...)
     * @return array id задач очереди
     */
    public static function index_texts($collection, $entity, array $items, array $opts = [])
    {
        $queued = self::queue_index($collection, $entity, $items, $opts);

        return $queued['job_ids'];
    }

    /**
     * Хеш содержимого вектора — ключ «текст не менялся, пересчитывать не нужно».
     *
     * Взят md5 сознательно: он есть и в PHP (md5()), и в python (hashlib.md5),
     * и в самом PostgreSQL (md5(text)), поэтому «свежий ли вектор» считается
     * прямо в SQL, без выгрузки текстов в PHP. Это ключ кэша, не защита —
     * коллизии здесь безобидны.
     */
    public static function content_hash($text)
    {
        return md5((string) $text);
    }

    /**
     * Поставить тексты на индексацию и честно посчитать, сколько ушло в работу,
     * а сколько пропущено как уже актуальное.
     *
     * Пропуск — по хешу текста: если вектор с таким же текстом уже лежит,
     * эмбеддинг-сервер не вызывается вовсе (повторная индексация не жжёт
     * токены). Векторы без хеша (записанные до миграции 002) считаются
     * устаревшими: первый проход их пересчитает и заполнит хеши.
     *
     * @param  array $opts  опции задачи + 'force' => TRUE (пересчитать всё)
     * @return array ['job_ids' => [...], 'queued' => N, 'skipped' => M]
     */
    public static function queue_index($collection, $entity, array $items, array $opts = [])
    {
        $items = array_values($items);
        $total = count($items);
        $out   = ['job_ids' => [], 'queued' => 0, 'skipped' => 0];

        if ($total === 0)
        {
            return $out;
        }

        self::ensure_collection($collection);

        if (empty($opts['force']))
        {
            $items = self::pending_items($collection, $entity, $items);
        }

        $out['queued']  = count($items);
        $out['skipped'] = $total - $out['queued'];

        if (empty($items))
        {
            return $out;
        }

        $batch = (int) (Kohana::$config->load('llm.embedding.batch_size') ?: 16);

        foreach (array_chunk($items, max(1, $batch)) as $chunk)
        {
            $out['job_ids'][] = Service_Prx120_JobQueue::push('semantic.index', [
                'collection' => (string) $collection,
                'entity'     => (string) $entity,
                'items'      => array_values($chunk),
            // Модуль-владелец задачи: по умолчанию prx115, но вызывающий
            // знает точнее (например prx676 индексирует свой каталог) —
            // поэтому его опции перекрывают значение по умолчанию.
            ], $opts + ['module' => 'prx115']);
        }

        return $out;
    }

    /**
     * Оставить только те записи, чей текст отличается от уже записанного вектора.
     *
     * Один SELECT по списку id — без выгрузки текстов: сравниваем хеши.
     */
    public static function pending_items($collection, $entity, array $items)
    {
        $db  = Database::instance();
        $by_id = [];

        foreach ($items as $item)
        {
            $id = isset($item['id']) ? (string) $item['id'] : '';

            if ($id !== '')
            {
                $by_id[$id] = $item;
            }
        }

        if (empty($by_id))
        {
            return [];
        }

        $list = implode(',', array_map([$db, 'quote'], array_keys($by_id)));
        $stored = [];

        try
        {
            foreach ($db->query(Database::SELECT,
                "SELECT v.entity_id, v.content_hash
                 FROM prx_115_vectors v
                 JOIN prx_115_collections c ON c.id = v.collection_id
                 WHERE c.name = ".$db->quote((string) $collection)."
                   AND v.entity = ".$db->quote((string) $entity)."
                   AND v.entity_id IN (".$list.")", FALSE)->as_array() as $row)
            {
                $row = (array) $row;
                $stored[(string) $row['entity_id']] = (string) $row['content_hash'];
            }
        }
        catch (Throwable $e)
        {
            // таблиц может не быть до миграции — тогда ничего не пропускаем
            return array_values($by_id);
        }

        $pending = [];

        foreach ($by_id as $id => $item)
        {
            $hash = self::content_hash(isset($item['text']) ? (string) $item['text'] : '');

            if (isset($stored[$id]) AND $stored[$id] !== '' AND $stored[$id] === $hash)
            {
                continue;   // текст не менялся — вектор актуален
            }

            $pending[] = $item;
        }

        return $pending;
    }

    /**
     * Удалить векторы сущности (при удалении объекта в модуле-владельце).
     */
    public static function delete_entity($collection, $entity, array $entity_ids)
    {
        $db = Database::instance();
        if (empty($entity_ids))
            return;

        $list = implode(',', array_map([$db, 'quote'], array_map('strval', $entity_ids)));

        $db->query(Database::DELETE,
            "DELETE FROM prx_115_vectors v
             USING prx_115_collections c
             WHERE c.id = v.collection_id
               AND c.name = ".$db->quote((string) $collection)."
               AND v.entity = ".$db->quote((string) $entity)."
               AND v.entity_id IN (".$list.")");
    }

    // ---------------------------------------------------------------------
    // Поиск (синхронно: один запрос к эмбеддинг-серверу + KNN в PostgreSQL)
    // ---------------------------------------------------------------------

    /**
     * Семантический поиск по текстовому запросу.
     *
     * @param  string $collection коллекция
     * @param  string $query      запрос на естественном языке
     * @param  int    $limit
     * @param  string $entity     фильтр по типу сущности (опционально)
     * @return array  строки: entity, entity_id, content, metadata, distance
     */
    public static function search($collection, $query, $limit = 10, $entity = NULL, array $opts = [])
    {
        $query = trim((string) $query);

        if ($query === '')
        {
            return [];
        }

        $opts = $opts + [
            'max_distance' => self::_search_cfg('max_distance'),
            'hybrid'       => (bool) self::_search_cfg('hybrid'),
        ];

        // Один запрос — один вызов эмбеддинг-сервера: повторные запросы берём
        // из кэша (prx_115_query_cache), он переживает перезапуск процессов.
        $vector = self::embed_query($query);

        if (empty($vector))
        {
            throw new Kohana_Exception('Эмбеддинг-сервер не вернул вектор для запроса (проверьте EMBEDDING_API_URL)');
        }

        $rows = self::search_by_vector($collection, $vector, $limit, $entity, $opts);

        // Гибрид: вектор «не уверен» (в пределах порога пусто) — показываем хотя
        // бы буквальные совпадения по тексту и помечаем их match = 'text', чтобы
        // страница могла честно сказать «это не семантика, это совпадение слов».
        if (empty($rows) AND ! empty($opts['hybrid']))
        {
            $rows = self::fulltext_search($collection, $query, $limit, $entity);
        }

        return $rows;
    }

    /**
     * Вектор поискового запроса — с кэшем в базе (таблица prx_115_query_cache).
     *
     * Ключ кэша = md5(модель + текст запроса): при смене модели старые векторы
     * не подставляются. Кэш — необязательный: его отсутствие или ошибка не
     * мешает поиску (просто пойдём на эмбеддинг-сервер).
     */
    public static function embed_query($text)
    {
        $text = (string) $text;
        list($model, $_dims) = self::_embedding_defaults();

        $cfg     = (array) Kohana::$config->load('prx115.cache');
        $enabled = (( ! isset($cfg['enabled']) OR $cfg['enabled']) AND class_exists('Database'));
        $db      = Database::instance();
        $qhash   = md5($model."\n".$text);

        if ($enabled)
        {
            try
            {
                $row = $db->query(Database::SELECT,
                    "SELECT vector FROM prx_115_query_cache WHERE qhash = ".$db->quote($qhash), FALSE)->current();

                if ($row)
                {
                    $cached = json_decode((string) $row['vector'], TRUE);

                    if (is_array($cached) AND ! empty($cached))
                    {
                        $db->query(Database::UPDATE,
                            "UPDATE prx_115_query_cache SET hits = hits + 1, used_at = now()
                             WHERE qhash = ".$db->quote($qhash), FALSE);

                        self::$_embed_cached = TRUE;

                        return array_map('floatval', $cached);
                    }
                }
            }
            catch (Throwable $e)
            {
                // нет таблицы (до миграции) — кэш просто выключен
            }
        }

        self::$_embed_cached = FALSE;
        $vector = Llm::get_embedding($text);

        if (empty($vector))
        {
            self::$_embed_cached = NULL;

            return [];
        }

        if ($enabled)
        {
            try
            {
                $json = json_encode(array_map('floatval', array_values($vector)));

                $db->query(Database::INSERT,
                    "INSERT INTO prx_115_query_cache (qhash, query, model, vector, dims, hits)
                     VALUES (".$db->quote($qhash).", ".$db->quote(mb_substr($text, 0, 1000)).",
                             ".$db->quote($model).", ".$db->quote($json)."::jsonb,
                             ".(int) count($vector).", 1)
                     ON CONFLICT (qhash) DO UPDATE SET
                        vector = EXCLUDED.vector, model = EXCLUDED.model,
                        dims = EXCLUDED.dims, used_at = now()", FALSE);

                self::_cache_sweep($cfg);
            }
            catch (Throwable $e)
            {
                // кэш — ускоритель, а не условие работы
            }
        }

        return $vector;
    }

    /**
     * Поиск по тексту (ILIKE) — запасной путь, когда вектор ничего не нашёл.
     */
    public static function fulltext_search($collection, $query, $limit = 10, $entity = NULL)
    {
        $query = trim((string) $query);

        if (mb_strlen($query) < max(1, (int) (self::_search_cfg('fulltext_min_length') ?: 3)))
        {
            return [];
        }

        $db     = Database::instance();
        $needle = str_replace(['\\', '%', '_'], ['', '\\%', '\\_'], $query);
        $like   = '%'.$needle.'%';

        $where = "WHERE c.name = ".$db->quote((string) $collection)
                 ." AND v.content ILIKE ".$db->quote($like);

        if ($entity !== NULL)
        {
            $where .= " AND v.entity = ".$db->quote((string) $entity);
        }

        $rows = [];

        foreach ($db->query(Database::SELECT,
            "SELECT v.entity, v.entity_id, v.content, v.metadata, v.model
             FROM prx_115_vectors v
             JOIN prx_115_collections c ON c.id = v.collection_id
             ".$where."
             ORDER BY v.updated_at DESC
             LIMIT ".max(1, (int) $limit), FALSE)->as_array() as $row)
        {
            $row = (array) $row;

            if (isset($row['metadata']) AND is_string($row['metadata']))
            {
                $row['metadata'] = json_decode($row['metadata'], TRUE);
            }

            $row['distance'] = NULL;
            $row['match']    = 'text';
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Поиск по готовому вектору (если вектор уже есть).
     */
    public static function search_by_vector($collection, array $vector, $limit = 10, $entity = NULL, array $opts = [])
    {
        $db = Database::instance();
        $limit = max(1, (int) $limit);

        $where = "WHERE c.name = ".$db->quote((string) $collection);
        if ($entity !== NULL)
        {
            $where .= " AND v.entity = ".$db->quote((string) $entity);
        }

        $literal = self::_vector_literal($vector);

        if (self::_has_pgvector())
        {
            $order = "v.embedding <=> '".$literal."'::vector";
        }
        else
        {
            $order = "prx_115_vec_dist(v.embedding, '".$literal."'::real[])";
        }

        // Порог расстояния: без него поиск «находил» что угодно — первые N строк
        // независимо от того, похожи они на запрос или нет. Порог задаётся
        // конфигом (application/config/prx115.php → search.max_distance).
        $max_distance = array_key_exists('max_distance', $opts) ? $opts['max_distance'] : NULL;

        if ($max_distance !== NULL AND $max_distance !== '' AND (float) $max_distance < 1)
        {
            $where .= " AND (".$order.") <= ".(float) $max_distance;
        }

        $result = $db->query(Database::SELECT,
            "SELECT v.entity, v.entity_id, v.content, v.metadata, v.model,
                    ".$order." AS distance
             FROM prx_115_vectors v
             JOIN prx_115_collections c ON c.id = v.collection_id
             ".$where."
             ORDER BY ".$order." ASC
             LIMIT ".$limit, FALSE);

        $rows = [];
        foreach ($result as $row)
        {
            $row = (array) $row;
            if (isset($row['metadata']) AND is_string($row['metadata']))
            {
                $row['metadata'] = json_decode($row['metadata'], TRUE);
            }
            $row['distance'] = $row['distance'] !== NULL ? (float) $row['distance'] : NULL;
            $row['match']    = 'vector';
            $rows[] = $row;
        }
        return $rows;
    }

    // ---------------------------------------------------------------------
    // Журнал поисков (prx_115_searches): «поиск реально находит?»
    // ---------------------------------------------------------------------

    /**
     * Взят ли вектор последнего запроса из кэша (TRUE — ответил кэш).
     * NULL — вектор ещё не считали.
     */
    public static function last_embed_cached()
    {
        return self::$_embed_cached;
    }

    /**
     * Записать поисковый запрос в журнал — что искали и что нашлось.
     *
     * Зачем отдельная таблица, если есть кэш запросов: кэш говорит «запрос
     * повторялся», но не говорит, С КАКИМ РЕЗУЛЬТАТОМ. Владельцу нужен ответ
     * «поиск находит или все запросы пустые» — его даёт только журнал.
     *
     * Журнал вспомогательный: его недоступность (нет таблицы до миграции,
     * ошибка прав) не имеет права ломать сам поиск — поэтому всё в try/catch.
     *
     * @param  string $collection коллекция (prx560_knowledge)
     * @param  string $query      текст запроса
     * @param  array  $results    строки ответа (по ним считаем смысл/слова)
     * @param  array  $meta       entity, ms, user_id, error
     * @return void
     */
    public static function log_search($collection, $query, array $results, array $meta = [])
    {
        return Service_Prx115_SearchLog::log_search($collection, $query, $results, $meta);
    }

    /**
     * Последние поисковые запросы — для карточки «Поисковые запросы» на /prx115.
     *
     * @return array
     */
    public static function recent_searches($limit = 10)
    {
        return Service_Prx115_SearchLog::recent_searches($limit);
    }

    /**
     * Сводка по журналу за N часов: сколько запросов, сколько пустых, сколько
     * с ошибкой, сколько ответил кэш и среднее время «живого» поиска.
     *
     * @return array
     */
    public static function search_stats($hours = 24)
    {
        return Service_Prx115_SearchLog::search_stats($hours);
    }

    /**
     * Чистка журнала: держим только последние `searches.keep` записей.
     * Журнал нужен «на посмотреть», а не как архив.
     */


    // ---------------------------------------------------------------------
    // Источники: покрытие, догоняющая индексация, уборка мусора
    // ---------------------------------------------------------------------

    /** Сколько записей источника берём за один проход «Догнать». */
    const REINDEX_MAX = 1000;

    /**
     * Источники индексации (реестр в application/config/prx115.php → sources).
     *
     * Источник отвечает на вопрос «где лежат тексты, которые ДОЛЖНЫ быть
     * векторами». Без него модуль умеет только «прими то, что прислали», и
     * проверить, всё ли проиндексировано, не может ни панель, ни владелец.
     *
     * @return array ['key' => ['title', 'collection', 'entity', 'sql'], ...]
     */
    public static function sources()
    {
        return Service_Prx115_Sources::sources();
    }

    /**
     * Покрытие: сколько записей источника проиндексировано и насколько свежо.
     *
     * Один запрос на источник: всего записей, сколько векторов, сколько из них
     * с актуальным хешем текста и сколько векторов осиротело (записи в
     * источнике больше нет — это мусор для уборки).
     */
    public static function coverage($only_collection = NULL)
    {
        return Service_Prx115_Sources::coverage($only_collection);
    }

    /**
     * Догоняющая индексация: найти в источниках записи, чей вектор отсутствует
     * или устарел, и поставить их в очередь пачками.
     *
     * @param  string $only_collection ограничить одной коллекцией (NULL — все)
     * @param  int    $limit           сколько записей брать с источника за проход
     * @return array  ['items', 'queued', 'skipped', 'jobs', 'sources' => [...]]
     */
    public static function reindex($only_collection = NULL, $limit = 200, array $opts = [])
    {
        $db = Database::instance();

        // Потолок на один проход: слишком большая пачка = сотни задач в очереди
        // и часы нагрузки на эмбеддинг-сервер. Лучше несколько осознанных кликов.
        $limit  = min(self::REINDEX_MAX, max(1, (int) $limit));
        $result = ['items' => 0, 'queued' => 0, 'skipped' => 0, 'jobs' => 0, 'sources' => []];

        foreach (self::sources() as $source)
        {
            if ($only_collection !== NULL AND $source['collection'] !== (string) $only_collection)
            {
                continue;
            }

            $collection = $db->quote($source['collection']);
            $entity     = $db->quote($source['entity']);

            try
            {
                $rows = $db->query(Database::SELECT,
                    "WITH src AS (".$source['sql'].")
                     SELECT src.id::text AS id,
                            src.content AS content,
                            COALESCE(src.metadata, '{}'::jsonb) AS metadata
                     FROM src
                     LEFT JOIN prx_115_collections c ON c.name = ".$collection."
                     LEFT JOIN prx_115_vectors v ON v.collection_id = c.id
                                               AND v.entity = ".$entity."
                                               AND v.entity_id = src.id::text
                     WHERE v.id IS NULL OR v.content_hash = '' OR v.content_hash <> md5(src.content)
                     ORDER BY src.id
                     LIMIT ".$limit, FALSE)->as_array();
            }
            catch (Throwable $e)
            {
                // Один сломанный источник (нет таблицы, не применена миграция 002)
                // не должен мешать догонять остальные: причина — в отчёте.
                $result['sources'][] = [
                    'key' => $source['key'], 'title' => $source['title'],
                    'items' => 0, 'queued' => 0, 'jobs' => 0,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ];
                $result['errors'] = (int) (isset($result['errors']) ? $result['errors'] : 0) + 1;
                continue;
            }

            $items = [];

            foreach ($rows as $row)
            {
                $row  = (array) $row;
                $meta = $row['metadata'];

                if (is_string($meta))
                {
                    $meta = json_decode($meta, TRUE);
                }

                $items[] = [
                    'id'       => (string) $row['id'],
                    'text'     => (string) $row['content'],
                    'metadata' => is_array($meta) ? $meta : [],
                ];
            }

            if (empty($items))
            {
                $result['sources'][] = [
                    'key' => $source['key'], 'title' => $source['title'],
                    'items' => 0, 'queued' => 0, 'jobs' => 0, 'error' => '',
                ];
                continue;
            }

            $queued = self::queue_index($source['collection'], $source['entity'], $items, $opts);

            $result['items']   += count($items);
            $result['queued']  += (int) $queued['queued'];
            $result['skipped'] += (int) $queued['skipped'];
            $result['jobs']    += count($queued['job_ids']);

            $result['sources'][] = [
                'key' => $source['key'], 'title' => $source['title'],
                'items' => count($items), 'queued' => (int) $queued['queued'],
                'jobs' => count($queued['job_ids']), 'error' => '',
            ];
        }

        return $result;
    }

    /**
     * Уборка мусора: векторы, чьей записи в источнике больше нет.
     *
     * $older_days — предохранитель: трогаем только векторы, не обновлявшиеся
     * дольше этого срока. Если источник по ошибке окажется отфильтрованным
     * (модуль чистит свою таблицу), свежие векторы не пострадают.
     *
     * @return int сколько векторов удалено
     */
    public static function prune($only_collection = NULL, $older_days = 7)
    {
        $db   = Database::instance();
        $days = max(0, (int) $older_days);
        $gone = 0;

        foreach (self::sources() as $source)
        {
            if ($only_collection !== NULL AND $source['collection'] !== (string) $only_collection)
            {
                continue;
            }

            $where = "c.name = ".$db->quote($source['collection'])."
                        AND v.entity = ".$db->quote($source['entity'])."
                        AND v.updated_at < now() - ".$days." * interval '1 day'
                        AND NOT EXISTS (SELECT 1 FROM (".$source['sql'].") s WHERE s.id::text = v.entity_id)";

            try
            {
                $row = (array) $db->query(Database::SELECT,
                    "SELECT COUNT(*) AS cnt
                     FROM prx_115_vectors v
                     JOIN prx_115_collections c ON c.id = v.collection_id
                     WHERE ".$where, FALSE)->current();

                $cnt = (int) $row['cnt'];

                if ($cnt === 0)
                {
                    continue;
                }

                $db->query(Database::DELETE,
                    "DELETE FROM prx_115_vectors v
                     USING prx_115_collections c
                     WHERE v.collection_id = c.id AND ".$where, FALSE);

                $gone += $cnt;
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'prx115: уборка мусора «:t»: :e',
                    [':t' => $source['title'], ':e' => $e->getMessage()]);
            }
        }

        return $gone;
    }

    // ---------------------------------------------------------------------
    // Панель /prx115 (видно глазами): коллекции, очередь, проверка сервера
    // ---------------------------------------------------------------------

    /**
     * Данные для страницы /prx115 — одним вызовом.
     *
     * Владелец (28.09): «займись модулем prx115, чтобы он полноценно работал» —
     * до этого у модуля был только JSON-API, и понять «работает ли семантика»
     * можно было только по логам. Здесь всё, что нужно странице: коллекции с
     * числом векторов и свежестью, состояние очереди канала semantic.index,
     * режим поиска (pgvector или real[]), настройки эмбеддинг-сервера.
     *
     * @return array
     */
    public static function panel()
    {
        return Service_Prx115_Diagnostics::panel(self::engine(), self::_embedding_defaults(), self::search_config());
    }

    /**
     * Режим поиска: pgvector (`<=>`) или vanilla PostgreSQL (`prx_115_vec_dist`).
     */
    public static function engine()
    {
        return self::_has_pgvector() ? 'pgvector' : 'real[]';
    }

    /**
     * Настройки поиска для панели: порог расстояния, гибрид, мин. длина
     * текстового запроса (application/config/prx115.php → search).
     */
    public static function search_config()
    {
        return [
            'max_distance'        => self::_search_cfg('max_distance'),
            'hybrid'              => (bool) self::_search_cfg('hybrid'),
            'fulltext_min_length' => (int) (self::_search_cfg('fulltext_min_length') ?: 3),
        ];
    }

    /**
     * Состояние модуля одной структурой: движок, настройки эмбеддингов, объём
     * хранилища, покрытие источников, последняя задача канала и проблемы
     * человеческим языком. Используют панель /prx115 и точка GET /prx115/api/health.
     */
    public static function health()
    {
        return Service_Prx115_Diagnostics::health(self::engine(), self::_embedding_defaults(), self::search_config());
    }

    /**
     * Проверка эмбеддинг-сервера: один вызов, замер времени и размерности.
     *
     * Кнопка «Проверить» на /prx115: владелец должен видеть не «наверное,
     * работает», а «1024 измерения, 240 мс» либо внятную причину отказа.
     *
     * @return array {ok, dims, ms, model, error}
     */
    public static function embedding_check()
    {
        list($model, $dims) = self::_embedding_defaults();

        $out = ['ok' => FALSE, 'dims' => 0, 'ms' => 0, 'model' => (string) $model, 'error' => ''];

        $started = microtime(TRUE);

        try
        {
            $vector = Llm::get_embedding('проверка эмбеддинг-сервера');
        }
        catch (Throwable $e)
        {
            $out['error'] = $e->getMessage();
            $out['ms']    = (int) round((microtime(TRUE) - $started) * 1000);

            return $out;
        }

        $out['ms'] = (int) round((microtime(TRUE) - $started) * 1000);

        if (empty($vector))
        {
            $out['error'] = 'Сервер ответил пустым вектором — проверьте EMBEDDING_API_URL и модель';

            return $out;
        }

        $out['ok']   = TRUE;
        $out['dims'] = count($vector);

        if ($dims > 0 AND $out['dims'] !== $dims)
        {
            $out['error'] = 'Размерность вектора '.$out['dims'].' не совпадает с EMBEDDING_DIMENSIONS='
                .$dims.' — существующие векторы с этой моделью искать нельзя';
        }

        return $out;
    }

    /**
     * Записать готовые векторы (мост для воркера в HTTP-режиме).
     *
     * Зачем: в режиме pg воркер пишет в базу сам. Если у воркера доступа к базе
     * нет (HTTP-режим), задача раньше завершалась «Готово: skipped» — снаружи
     * успех, а поиск пустой. Теперь обработчик считает векторы и присылает их
     * сюда: POST /prx115/api/vectors с токеном воркера.
     *
     * @param  string $collection имя коллекции
     * @param  string $entity     тип сущности
     * @param  array  $items      [['id' => '12', 'vector' => [0.1, …], 'text' => '…', 'metadata' => []]]
     * @param  string $model      имя модели эмбеддингов (для журнала и поиска)
     * @return int сколько векторов записано
     */
    public static function store_vectors($collection, $entity, array $items, $model = '')
    {
        if (empty($items))
        {
            return 0;
        }

        $db = Database::instance();
        $collection_id = self::ensure_collection($collection);
        $dims_expected = 0;

        $row = $db->query(Database::SELECT,
            "SELECT dims FROM prx_115_collections WHERE id = ".(int) $collection_id, FALSE)->current();

        if ($row)
        {
            $dims_expected = (int) $row['dims'];
        }

        $has_pgvector = self::_has_pgvector();
        $written = 0;

        foreach ($items as $item)
        {
            $id     = isset($item['id']) ? (string) $item['id'] : '';
            $vector = isset($item['vector']) && is_array($item['vector']) ? $item['vector'] : [];

            if ($id === '' OR empty($vector))
            {
                continue;
            }

            // Вектор чужой размерности в ту же коллекцию писать нельзя: поиск
            // по нему даст мусор, а ошибку потом искать долго.
            if ($dims_expected > 0 AND count($vector) !== $dims_expected)
            {
                throw new Kohana_Exception('Вектор размерности :got не подходит коллекции :c (:want)',
                    [':got' => count($vector), ':c' => (string) $collection, ':want' => $dims_expected]);
            }

            $literal   = self::_vector_literal($vector);
            $cast      = $has_pgvector ? '::vector' : '::real[]';
            $content   = isset($item['text']) ? (string) $item['text'] : '';
            $metadata  = isset($item['metadata']) && is_array($item['metadata'])
                ? json_encode($item['metadata'], JSON_UNESCAPED_UNICODE)
                : '{}';

            $db->query(Database::INSERT,
                "INSERT INTO prx_115_vectors
                    (collection_id, entity, entity_id, content, metadata, embedding, model, dims, content_hash)
                 VALUES (".(int) $collection_id.", ".$db->quote((string) $entity).", ".$db->quote($id).",
                         ".$db->quote($content).", ".$db->quote((string) $metadata)."::jsonb,
                         ".$db->quote($literal).$cast.", ".$db->quote((string) $model).", ".(int) count($vector).",
                         ".$db->quote(self::content_hash($content)).")
                 ON CONFLICT (collection_id, entity, entity_id) DO UPDATE SET
                    content   = EXCLUDED.content,
                    metadata  = EXCLUDED.metadata,
                    embedding = EXCLUDED.embedding,
                    model     = EXCLUDED.model,
                    dims      = EXCLUDED.dims,
                    content_hash = EXCLUDED.content_hash,
                    updated_at = now()", FALSE);

            $written++;
        }

        // Новая модель — запоминаем в коллекции: в панели видно, чем считали.
        if ($model !== '')
        {
            $db->query(Database::UPDATE,
                "UPDATE prx_115_collections SET model = ".$db->quote((string) $model)."
                  WHERE id = ".(int) $collection_id." AND model = ''", FALSE);
        }

        return $written;
    }

    // ---------------------------------------------------------------------
    // Служебное
    // ---------------------------------------------------------------------

    /**
     * Значение из config/prx115.php → search (или NULL, если не задано).
     */
    protected static function _search_cfg($key)
    {
        $cfg = (array) Kohana::$config->load('prx115.search');

        return isset($cfg[$key]) ? $cfg[$key] : NULL;
    }

    /**
     * Уборка кэша запросов: сначала по сроку, потом по количеству (самые давно
     * неиспользованные). Две дешёвые команды — кэш не должен расти молча.
     */
    protected static function _cache_sweep(array $cfg)
    {
        $db  = Database::instance();
        $ttl = max(1, (int) (isset($cfg['ttl_days']) ? $cfg['ttl_days'] : 30));
        $max = max(50, (int) (isset($cfg['max_rows']) ? $cfg['max_rows'] : 500));

        $db->query(Database::UPDATE,
            "DELETE FROM prx_115_query_cache WHERE used_at < now() - ".$ttl." * interval '1 day'", FALSE);

        $db->query(Database::UPDATE,
            "DELETE FROM prx_115_query_cache WHERE qhash IN (
                SELECT qhash FROM prx_115_query_cache ORDER BY used_at DESC OFFSET ".$max.")", FALSE);
    }

    /**
     * Статистика по коллекциям.
     */
    public static function stats()
    {
        return Service_Prx115_Diagnostics::stats();
    }

    /**
     * Есть ли pgvector (иначе — режим real[] с prx_115_vec_dist).
     */
    protected static function _has_pgvector()
    {
        if (self::$_pgvector === NULL)
        {
            $db = Database::instance();
            $row = $db->query(Database::SELECT,
                "SELECT data_type FROM information_schema.columns
                 WHERE table_name = 'prx_115_vectors' AND column_name = 'embedding'", FALSE)->current();

            self::$_pgvector = ($row AND (array) $row['data_type'] !== NULL AND strtolower((string) $row['data_type']) !== 'array');
        }
        return self::$_pgvector;
    }

    /**
     * Литерал вектора для SQL: [1,2,3] (vector) или {1,2,3} (real[]).
     * Числа форматируются независимо от локали.
     */
    protected static function _vector_literal(array $vector)
    {
        $floats = [];
        foreach ($vector as $value)
        {
            $floats[] = sprintf('%.7F', (float) $value);
        }

        return self::_has_pgvector()
            ? '['.implode(',', $floats).']'
            : '{'.implode(',', $floats).'}';
    }

    /**
     * [model, dims] из конфига эмбеддинг-сервера.
     */
    protected static function _embedding_defaults()
    {
        $cfg = (array) Kohana::$config->load('llm.embedding');

        return [
            isset($cfg['model']) ? (string) $cfg['model'] : '',
            isset($cfg['dimensions']) ? (int) $cfg['dimensions'] : 0,
        ];
    }
}
