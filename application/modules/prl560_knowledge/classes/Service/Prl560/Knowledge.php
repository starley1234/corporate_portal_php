<?php defined('SYSPATH') or die('No direct script access.');

/**
 * База знаний PRL (PRL-560) — централизованный комбинированный RAG
 * по наработкам воркеров всех модулей.
 *
 * Зачем:
 *  - не заставлять воркеры (и LLM за ними) трудиться над одной и той же
 *    похожей задачей: remember() дедуплицирует по точному ключу, а поиск
 *    позволяет находить похожие наработки до постановки задачи;
 *  - копить материал для будущих пайплайнов: дообучение/примеры/аналитика.
 *
 * Комбинированный поиск: точный ключ (SQL) + текстовый (ILIKE) + векторный
 * (prl115_semantic, коллекция из конфига; индексация — через очередь
 * semantic.index, поэтому HTTP-запрос не тормозит).
 *
 * Модули используют так (обычно из reconcile после успешной задачи):
 *   Service_Prl560_Knowledge::remember('prl540', 'review_reply', $key,
 *       $title, $content, ['tone' => ..., 'sentiment' => ...]);
 *
 * Все методы безопасны: сбой БЗ не должен ломать работу модулей —
 * вызывающий код оборачивает в try/catch (см. prl540/prl550).
 */
class Service_Prl560_Knowledge {

    /** @var Database */
    protected static $_db;

    protected static function _db()
    {
        return self::$_db !== NULL ? self::$_db : self::$_db = Database::instance();
    }

    protected static function _cfg($key, $default = NULL)
    {
        static $cfg = NULL;

        if ($cfg === NULL)
        {
            $cfg = (array) Kohana::$config->load('prl560_knowledge');
        }

        $value = $cfg;

        foreach (explode('.', $key) as $part)
        {
            if ( ! is_array($value) OR ! array_key_exists($part, $value))
            {
                return $default;
            }

            $value = $value[$part];
        }

        return $value === NULL ? $default : $value;
    }

    /** Нормализованный ключ дедупликации из текста задачи. */
    public static function make_key($text)
    {
        $norm = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $text)));

        return hash('sha256', $norm);
    }

    /**
     * Запомнить наработку. Точный дедуп: тот же ключ → hits+1, last_seen=now(),
     * контент обновляется на свежий. Векторы индексируются через очередь.
     *
     * @param   bool  $index  ставить ли векторную индексацию сразу (наполнитель
     *                        базы копит пачки и зовёт index_texts один раз на 32
     *                        документа — см. backfill; иначе на каждую запись
     *                        появлялась отдельная задача очереди и монитор
     *                        тонул в «Семантическом индексе»)
     * @return  array  ['status' => 'stored'|'known', 'id' => int]
     */
    public static function remember($source, $kind, $key, $title, $content, array $meta = [], $summary = NULL, $index = TRUE)
    {
        $db   = self::_db();
        $key  = (string) $key;
        $meta = self::_quote_json($db, $meta ?: NULL);

        $row = $db->query(Database::SELECT,
            'UPDATE prl_560_docs
             SET hits = hits + 1, last_seen_at = now(), title = '.$db->quote((string) $title).',
                 content = '.$db->quote((string) $content).',
                 summary = '.self::_quote_nullable($db, $summary).',
                 meta = prl_560_docs.meta || '.($meta === 'NULL' ? "'{}'::jsonb" : $meta).'
             WHERE source_module = '.$db->quote((string) $source).' AND entity_key = '.$db->quote($key).'
             RETURNING id', FALSE)->current();

        if ($row)
        {
            return ['status' => 'known', 'id' => (int) $row['id']];
        }

        $row = $db->query(Database::SELECT,
            'INSERT INTO prl_560_docs (source_module, kind, entity_key, title, content, summary, meta)
             VALUES ('.$db->quote((string) $source).', '.$db->quote((string) $kind).', '.$db->quote($key).',
                     '.$db->quote((string) $title).', '.$db->quote((string) $content).',
                     '.self::_quote_nullable($db, $summary).', '.($meta === 'NULL' ? "'{}'::jsonb" : $meta).')
             RETURNING id', FALSE)->current();

        if ( ! $row)
        {
            return ['status' => 'error', 'id' => 0];
        }

        $id = (int) $row['id'];

        // векторная индексация — через очередь (не тормозит HTTP)
        try
        {
            if ($index AND class_exists('Service_Prl115_Semantic'))
            {
                Service_Prl115_Semantic::index_texts(
                    (string) self::_cfg('collection', 'prl560_knowledge'),
                    'doc',
                    [['id' => (string) $id, 'text' => $title."\n".$content, 'metadata' => [
                        'source' => (string) $source, 'kind' => (string) $kind,
                    ]]]
                );
            }
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::WARNING, 'prl560: векторная индексация отложена: :e', [':e' => $e->getMessage()]);
        }

        return ['status' => 'stored', 'id' => $id];
    }

    /**
     * Точный дедуп: свежий документ по ключу (для проверки ДО постановки задачи).
     *
     * @return  array|NULL  строка документа или NULL
     */
    public static function find_exact($source, $key, $days = NULL)
    {
        $days = (int) ($days ?? self::_cfg('fresh_days', 14));

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM prl_560_docs
             WHERE source_module = '.self::_db()->quote((string) $source).' AND entity_key = '.self::_db()->quote((string) $key)."
               AND last_seen_at > now() - interval '".(int) $days." days' LIMIT 1", FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /**
     * Комбинированный поиск: текст (ILIKE) + опционально векторный (prl115).
     *
     * @param   string  $query
     * @param   array   $opts  source, kind, limit, semantic (bool)
     * @return  array   ['text' => [...], 'semantic' => [...]]
     */
    public static function search($query, array $opts = [])
    {
        $db    = self::_db();
        $limit = max(1, min(50, (int) ($opts['limit'] ?? 10)));
        $like  = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $query)).'%';
        $out   = ['text' => [], 'semantic' => []];

        $where = 'WHERE (title ILIKE '.$db->quote($like).' OR content ILIKE '.$db->quote($like).' OR summary ILIKE '.$db->quote($like).')';

        if ( ! empty($opts['source']))
        {
            $where .= ' AND source_module = '.$db->quote((string) $opts['source']);
        }

        if ( ! empty($opts['kind']))
        {
            $where .= ' AND kind = '.$db->quote((string) $opts['kind']);
        }

        $out['text'] = $db->query(Database::SELECT,
            'SELECT id, source_module, kind, title, summary,
                    LEFT(content, 5000) AS content, hits, created_at, last_seen_at
             FROM prl_560_docs '.$where.' ORDER BY last_seen_at DESC LIMIT '.$limit, FALSE)->as_array();

        if ( ! empty($opts['semantic']) AND class_exists('Service_Prl115_Semantic'))
        {
            try
            {
                $rows = Service_Prl115_Semantic::search(
                    (string) self::_cfg('collection', 'prl560_knowledge'), (string) $query, $limit, 'doc');

                foreach ((array) $rows as $r)
                {
                    $r = (array) $r;

                    $out['semantic'][] = [
                        'doc_id'   => (int) $r['entity_id'],
                        'distance' => isset($r['distance']) ? (float) $r['distance'] : NULL,
                        'content'  => mb_substr((string) ($r['content'] ?? ''), 0, 300),
                    ];
                }
            }
            catch (Exception $e)
            {
                Kohana::$log->add(Log::WARNING, 'prl560: семантический поиск недоступен: :e', [':e' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /** Статистика для админки. */
    public static function stats()
    {
        $db = self::_db();

        $totals = $db->query(Database::SELECT,
            'SELECT COUNT(*) AS docs, COALESCE(SUM(hits), 0) AS hits,
                    COUNT(*) FILTER (WHERE last_seen_at > now() - interval \'7 days\') AS fresh
             FROM prl_560_docs', FALSE)->current();

        $by_source = $db->query(Database::SELECT,
            'SELECT source_module, kind, COUNT(*) AS cnt
             FROM prl_560_docs GROUP BY source_module, kind ORDER BY cnt DESC LIMIT 20', FALSE)->as_array();

        return [
            'docs'      => (int) ($totals['docs'] ?? 0),
            'hits'      => (int) ($totals['hits'] ?? 0),
            'fresh'     => (int) ($totals['fresh'] ?? 0),
            'by_source' => $by_source,
        ];
    }

    /** Последние документы (админка). */
    public static function recent($limit = 30)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT id, source_module, kind, title, summary, hits, created_at, last_seen_at
             FROM prl_560_docs ORDER BY last_seen_at DESC LIMIT '.max(1, (int) $limit), FALSE)->as_array();
    }

    /** Удалить документ (и его векторы). */
    public static function delete($id)
    {
        try
        {
            if (class_exists('Service_Prl115_Semantic'))
            {
                Service_Prl115_Semantic::delete_entity(
                    (string) self::_cfg('collection', 'prl560_knowledge'), 'doc', [(string) (int) $id]);
            }
        }
        catch (Exception $e)
        {
            // вектор мог не успеть проиндексироваться — не мешаем удалить документ
        }

        self::_db()->query(Database::DELETE, 'DELETE FROM prl_560_docs WHERE id = '.(int) $id, FALSE);

        return ['ok' => TRUE];
    }


    /** Очистка по возрасту (для cron/Minion и кнопки в админке). */
    public static function cleanup($days = NULL)
    {
        $days = (int) ($days ?? self::_cfg('retention_days', 180));

        $rows = self::_db()->query(Database::SELECT,
            "SELECT id FROM prl_560_docs WHERE last_seen_at < now() - interval '".(int) $days." days'",
            FALSE)->as_array();

        foreach ($rows as $row)
        {
            self::delete((int) $row['id']);
        }

        return count($rows);
    }

    protected static function _quote_nullable(Database $db, $value)
    {
        return ($value === '' OR $value === NULL) ? 'NULL' : $db->quote((string) $value);
    }

    protected static function _quote_json(Database $db, $value)
    {
        return $value === NULL ? 'NULL' : $db->quote(json_encode($value, JSON_UNESCAPED_UNICODE)).'::jsonb';
    }
}
