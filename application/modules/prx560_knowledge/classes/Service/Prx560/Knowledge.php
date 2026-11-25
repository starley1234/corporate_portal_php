<?php defined('SYSPATH') or die('No direct script access.');

/**
 * База знаний PRX (PRX-560) — централизованный комбинированный RAG
 * по наработкам воркеров всех модулей.
 *
 * Зачем:
 *  - не заставлять воркеры (и LLM за ними) трудиться над одной и той же
 *    похожей задачей: remember() дедуплицирует по точному ключу, а поиск
 *    позволяет находить похожие наработки до постановки задачи;
 *  - копить материал для будущих пайплайнов: дообучение/примеры/аналитика.
 *
 * Комбинированный поиск: точный ключ (SQL) + текстовый (ILIKE) + векторный
 * (prx115_semantic, коллекция из конфига; индексация — через очередь
 * semantic.index, поэтому HTTP-запрос не тормозит).
 *
 * Модули используют так (обычно из reconcile после успешной задачи):
 *   Service_Prx560_Knowledge::remember('prx540', 'review_reply', $key,
 *       $title, $content, ['tone' => ..., 'sentiment' => ...]);
 *
 * Все методы безопасны: сбой БЗ не должен ломать работу модулей —
 * вызывающий код оборачивает в try/catch (см. prx540/prx550).
 */
class Service_Prx560_Knowledge {

    protected static function _cfg($key, $default = NULL)
    {
        static $cfg = NULL;

        if ($cfg === NULL)
        {
            $cfg = (array) Kohana::$config->load('prx560_knowledge');
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
        $key  = (string) $key;
        $meta = $meta ?: NULL;
        $params = [
            ':source' => (string) $source, ':kind' => (string) $kind, ':key' => $key,
            ':title' => (string) $title, ':content' => (string) $content,
            ':summary' => ($summary === '' OR $summary === NULL) ? NULL : (string) $summary,
            ':meta' => $meta,
        ];

        $row = Prx_Db::one(
            'UPDATE prx_560_docs
             SET hits = hits + 1, last_seen_at = now(), title = :title,
                 content = :content,
                 summary = :summary,
                 meta = prx_560_docs.meta || COALESCE(:meta::jsonb, \'{}\'::jsonb)
             WHERE source_module = :source AND entity_key = :key
             RETURNING id', $params);

        if ($row)
        {
            return ['status' => 'known', 'id' => (int) $row['id']];
        }

        $row = Prx_Db::one(
            'INSERT INTO prx_560_docs (source_module, kind, entity_key, title, content, summary, meta)
             VALUES (:source, :kind, :key,
                     :title, :content,
                     :summary, COALESCE(:meta::jsonb, \'{}\'::jsonb))
             RETURNING id', $params);

        if ( ! $row)
        {
            return ['status' => 'error', 'id' => 0];
        }

        $id = (int) $row['id'];

        // векторная индексация — через очередь (не тормозит HTTP)
        try
        {
            if ($index AND class_exists('Service_Prx115_Semantic'))
            {
                Service_Prx115_Semantic::index_texts(
                    (string) self::_cfg('collection', 'prx560_knowledge'),
                    'doc',
                    [['id' => (string) $id, 'text' => $title."\n".$content, 'metadata' => [
                        'source' => (string) $source, 'kind' => (string) $kind,
                    ]]]
                );
            }
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::WARNING, 'prx560: векторная индексация отложена: :e', [':e' => $e->getMessage()]);
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

        $row = Prx_Db::one(
            'SELECT * FROM prx_560_docs
             WHERE source_module = :source AND entity_key = :key
               AND last_seen_at > now() - interval \''.(int) $days.' days\' LIMIT 1',
            [':source' => (string) $source, ':key' => (string) $key]);

        return $row ? (array) $row : NULL;
    }

    /**
     * Комбинированный поиск: текст (ILIKE) + опционально векторный (prx115).
     *
     * @param   string  $query
     * @param   array   $opts  source, kind, limit, semantic (bool)
     * @return  array   ['text' => [...], 'semantic' => [...]]
     */
    public static function search($query, array $opts = [])
    {
        $limit = max(1, min(50, (int) ($opts['limit'] ?? 10)));
        $like  = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $query)).'%';
        $out   = ['text' => [], 'semantic' => []];

        $params = [':like' => $like];
        $where = 'WHERE (title ILIKE :like OR content ILIKE :like OR summary ILIKE :like)';

        if ( ! empty($opts['source']))
        {
            $where .= ' AND source_module = :source';
            $params[':source'] = (string) $opts['source'];
        }

        if ( ! empty($opts['kind']))
        {
            $where .= ' AND kind = :kind';
            $params[':kind'] = (string) $opts['kind'];
        }

        $out['text'] = Prx_Db::select(
            'SELECT id, source_module, kind, title, summary,
                    LEFT(content, 5000) AS content, hits, created_at, last_seen_at
             FROM prx_560_docs '.$where.' ORDER BY last_seen_at DESC LIMIT '.$limit, $params);

        if ( ! empty($opts['semantic']) AND class_exists('Service_Prx115_Semantic'))
        {
            try
            {
                $rows = Service_Prx115_Semantic::search(
                    (string) self::_cfg('collection', 'prx560_knowledge'), (string) $query, $limit, 'doc');

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
                Kohana::$log->add(Log::WARNING, 'prx560: семантический поиск недоступен: :e', [':e' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /** Статистика для админки. */
    public static function stats()
    {
        $totals = Prx_Db::one(
            'SELECT COUNT(*) AS docs, COALESCE(SUM(hits), 0) AS hits,
                    COUNT(*) FILTER (WHERE last_seen_at > now() - interval \'7 days\') AS fresh
             FROM prx_560_docs');

        $by_source = Prx_Db::select(
            'SELECT source_module, kind, COUNT(*) AS cnt
             FROM prx_560_docs GROUP BY source_module, kind ORDER BY cnt DESC LIMIT 20');

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
        return Prx_Db::select(
            'SELECT id, source_module, kind, title, summary, hits, created_at, last_seen_at
             FROM prx_560_docs ORDER BY last_seen_at DESC LIMIT '.max(1, (int) $limit));
    }

    /** Удалить документ (и его векторы). */
    public static function delete($id)
    {
        try
        {
            if (class_exists('Service_Prx115_Semantic'))
            {
                Service_Prx115_Semantic::delete_entity(
                    (string) self::_cfg('collection', 'prx560_knowledge'), 'doc', [(string) (int) $id]);
            }
        }
        catch (Exception $e)
        {
            // вектор мог не успеть проиндексироваться — не мешаем удалить документ
        }

        Prx_Db::execute('DELETE FROM prx_560_docs WHERE id = '.(int) $id);

        return ['ok' => TRUE];
    }


    /** Очистка по возрасту (для cron/Minion и кнопки в админке). */
    public static function cleanup($days = NULL)
    {
        $days = (int) ($days ?? self::_cfg('retention_days', 180));

        $rows = Prx_Db::select(
            "SELECT id FROM prx_560_docs WHERE last_seen_at < now() - interval '".(int) $days."' days'");

        foreach ($rows as $row)
        {
            self::delete((int) $row['id']);
        }

        return count($rows);
    }

}
