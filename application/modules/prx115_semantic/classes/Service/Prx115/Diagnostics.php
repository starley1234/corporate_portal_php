<?php defined('SYSPATH') or die('No direct script access.');

/** Внутренний компонент prx115; внешние потребители используют Semantic. */
final class Service_Prx115_Diagnostics
{
    public static function panel($engine, array $embedding, array $search)
    {
        $db = Database::instance();

        // 1. Коллекции: сколько векторов, сколько сущностей, когда обновлялись.
        $collections = [];

        try
        {
            foreach ($db->query(Database::SELECT,
                "SELECT c.id, c.name, c.description, c.model, c.dims, c.created_at,
                        COUNT(v.id) AS vectors,
                        COUNT(DISTINCT v.entity) AS entities,
                        MAX(v.updated_at) AS updated_at,
                        MAX(v.dims) AS vector_dims
                 FROM prx_115_collections c
                 LEFT JOIN prx_115_vectors v ON v.collection_id = c.id
                 GROUP BY c.id, c.name, c.description, c.model, c.dims, c.created_at
                 ORDER BY COUNT(v.id) DESC, c.name ASC", FALSE)->as_array() as $row)
            {
                $row = (array) $row;
                $collections[] = [
                    'id'          => (int) $row['id'],
                    'name'        => (string) $row['name'],
                    'description' => (string) ($row['description'] ?? ''),
                    'model'       => (string) ($row['model'] ?? ''),
                    'dims'        => (int) ($row['dims'] ?? 0),
                    'vectors'     => (int) $row['vectors'],
                    'entities'    => (int) $row['entities'],
                    'updated_at'  => (string) ($row['updated_at'] ?? ''),
                    'created_at'  => (string) ($row['created_at'] ?? ''),
                ];
            }
        }
        catch (Throwable $e) { /* таблиц может не быть до миграции */ }

        $vectors = 0;
        foreach ($collections as $c)
        {
            $vectors += $c['vectors'];
        }

        // 2. Очередь канала: что в работе, что упало, последние задачи.
        $queue  = ['pending' => 0, 'processing' => 0, 'done_24h' => 0, 'failed_24h' => 0,
                   'queued_recent' => 0, 'jobs' => [], 'ok' => FALSE, 'bridge' => 0];
        $bridge = 0;

        if (class_exists('Service_Prx120_JobQueue'))
        {
            try
            {
                $q = Service_Prx120_JobQueue::instance();

                foreach ($q->channel_jobs('semantic.index', 12) as $row)
                {
                    $row = (array) $row;
                    $payload = $row['payload'];
                    if (is_string($payload))
                    {
                        $payload = json_decode($payload, TRUE);
                    }

                    $queue['jobs'][] = [
                        'id'         => (int) $row['id'],
                        'status'     => (string) $row['status'],
                        'attempts'   => (int) $row['attempts'],
                        'max'        => (int) $row['max_attempts'],
                        'collection' => (string) ($payload['collection'] ?? ''),
                        'entity'     => (string) ($payload['entity'] ?? ''),
                        'items'      => is_array($payload) && ! empty($payload['items']) ? count($payload['items']) : 0,
                        'worker'     => (string) ($row['claimed_by'] ?? ''),
                        'created_at' => (string) $row['created_at'],
                        'finished_at'=> (string) ($row['finished_at'] ?? ''),
                        'error'      => (string) ($row['error'] ?? ''),
                    ];
                }

                $queue['ok'] = TRUE;

                $metrics = $q->channel_metrics('semantic.index');
                foreach ($metrics as $key => $value) $queue[$key] = $value;
                $bridge = $metrics['bridge'];
            }
            catch (Throwable $e) { /* очередь недоступна — панель всё равно открывается */ }
        }

        return [
            'collections' => $collections,
            'totals'      => [
                'collections' => count($collections),
                'vectors'     => $vectors,
                'entities'    => array_sum(array_column($collections, 'entities')),
            ],
            'queue'       => $queue,
            // Последние поисковые запросы и сводка за сутки: по ним видно,
            // ПОИСК РЕАЛЬНО НАХОДИТ, или все запросы пустые и надо догонять
            // индексацию. Журнал вспомогательный — его ошибки панель не роняют.
            'searches'     => Service_Prx115_SearchLog::recent_searches(10),
            'search_stats' => Service_Prx115_SearchLog::search_stats(24),
            // Полное состояние модуля (движок, эмбеддинги, покрытие источников,
            // проблемы) плюс счётчик «сколько задач прошло мостом»: воркер в
            // HTTP-режиме пишет векторы через POST /prx115/api/vectors, и это
            // видно в панели, а не только в журнале задачи.
            'health'      => self::health($engine, $embedding, $search) + ['bridge_used' => $bridge],
        ];
    }

    public static function health($engine, array $embedding, array $search)
    {
        $problems = [];

        list($model, $dims) = $embedding;
        $url = trim((string) (getenv('EMBEDDING_API_URL') ?: ''));

        if ($url === '')
        {
            $problems[] = 'EMBEDDING_API_URL не задан — векторы считает локальная модель (медленнее и хуже качеством)';
        }

        $tables      = TRUE;
        $hash_column = TRUE;
        $collections = 0;
        $vectors     = 0;
        $no_hash     = 0;

        try
        {
            $row = (array) Database::instance()->query(Database::SELECT,
                "SELECT (SELECT COUNT(*) FROM prx_115_collections) AS collections,
                        (SELECT COUNT(*) FROM prx_115_vectors) AS vectors", FALSE)->current();

            $collections = (int) $row['collections'];
            $vectors     = (int) $row['vectors'];
        }
        catch (Throwable $e)
        {
            $tables     = FALSE;
            $problems[] = 'таблиц prx_115_* нет — выполните миграции (bin/console db:migrate)';
        }

        // Колонка content_hash появилась миграцией 002. Её отсутствие — тоже
        // «примените миграции», но это НЕ «таблиц нет»: смешивать причины нельзя,
        // иначе владелец чинит не то.
        if ($tables)
        {
            try
            {
                $row = (array) Database::instance()->query(Database::SELECT,
                    "SELECT COUNT(*) AS c FROM prx_115_vectors WHERE content_hash = ''", FALSE)->current();

                $no_hash = (int) $row['c'];
            }
            catch (Throwable $e)
            {
                $hash_column = FALSE;
                $problems[]  = 'колонки content_hash нет — примените миграции (bin/console db:migrate)';
            }
        }

        $coverage     = [];
        $coverage_min = NULL;

        if ($tables)
        {
            $coverage = Service_Prx115_Sources::coverage();

            foreach ($coverage as $row)
            {
                if ($row['coverage'] !== NULL)
                {
                    $coverage_min = $coverage_min === NULL
                        ? (float) $row['coverage']
                        : min($coverage_min, (float) $row['coverage']);

                    if ($row['coverage'] < 100)
                    {
                        $problems[] = '«'.$row['title'].'»: проиндексировано '.$row['coverage']
                            .' % (без векторов '.$row['missing'].', устарело '.$row['stale']
                            .') — кнопка «Догнать»';
                    }
                }

                if ($row['orphans'] > 0)
                {
                    $problems[] = '«'.$row['title'].'»: мусорных векторов '.$row['orphans']
                        .' — кнопка «Убрать мусор»';
                }

                if ($row['error'] !== '')
                {
                    $problems[] = 'источник «'.$row['title'].'» недоступен: '.$row['error'];
                }
            }
        }

        if ($hash_column AND $no_hash > 0)
        {
            $problems[] = 'векторов без хеша текста: '.$no_hash.' — пересчитаются при «Догнать»';
        }

        $last_job = NULL;

        if (class_exists('Service_Prx120_JobQueue'))
        {
            try
            {
                $live = Service_Prx120_JobQueue::instance()->last_status('semantic.index');

                if ( ! empty($live['found']))
                {
                    $last_job = [
                        'id'     => (int) $live['job_id'],
                        'status' => (string) $live['status'],
                        'stage'  => (string) $live['stage'],
                        'at'     => (string) ($live['finished_at'] !== NULL ? $live['finished_at'] : $live['started_at']),
                    ];
                }
            }
            catch (Throwable $e)
            {
                // очередь недоступна — состояние хранилища всё равно покажем
            }
        }

        return [
            'ok'           => ($tables AND $url !== ''),
            'engine'       => $engine,
            'tables'       => $tables,
            'hash_column'  => $hash_column,
            'embed_url'    => $url,
            'embed_model'  => (string) $model,
            'embed_dims'   => (int) $dims,
            'collections'  => $collections,
            'vectors'      => $vectors,
            'no_hash'      => $no_hash,
            'coverage_min' => $coverage_min,
            'coverage'     => $coverage,
            'search'       => $search,
            'last_job'     => $last_job,
            'problems'     => $problems,
        ];
    }

    public static function stats()
    {
        $db = Database::instance();
        $result = $db->query(Database::SELECT,
            "SELECT c.name, c.model, c.dims, COUNT(v.id) AS vectors
             FROM prx_115_collections c
             LEFT JOIN prx_115_vectors v ON v.collection_id = c.id
             GROUP BY c.id, c.name, c.model, c.dims
             ORDER BY c.name", FALSE);

        $stats = [];
        foreach ($result as $row)
        {
            $row = (array) $row;
            $row['vectors'] = (int) $row['vectors'];
            $stats[] = $row;
        }
        return $stats;
    }
}
