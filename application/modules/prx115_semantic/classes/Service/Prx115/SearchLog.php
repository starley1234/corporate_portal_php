<?php defined('SYSPATH') or die('No direct script access.');

/** Внутренний компонент prx115; внешние потребители используют Semantic. */
final class Service_Prx115_SearchLog
{
    public static function log_search($collection, $query, array $results, array $meta = [])
    {
        try
        {
            $db = Database::instance();

            $vector_hits = 0;
            $text_hits   = 0;

            foreach ($results as $row)
            {
                $row = (array) $row;

                if (isset($row['match']) AND $row['match'] === 'text')
                {
                    $text_hits++;
                }
                else
                {
                    $vector_hits++;
                }
            }

            $db->query(Database::INSERT,
                "INSERT INTO prx_115_searches
                    (collection, entity, query, results, vector_hits, text_hits, cached, ms, error, user_id)
                 VALUES (".$db->quote((string) $collection).",
                         ".$db->quote((string) (isset($meta['entity']) ? $meta['entity'] : '')).",
                         ".$db->quote(mb_substr((string) $query, 0, 500)).",
                         ".(int) count($results).",
                         ".(int) $vector_hits.",
                         ".(int) $text_hits.",
                         ".( ! empty($meta['cached']) ? 'TRUE' : 'FALSE').",
                         ".(int) (isset($meta['ms']) ? $meta['ms'] : 0).",
                         ".$db->quote(mb_substr((string) (isset($meta['error']) ? $meta['error'] : ''), 0, 500)).",
                         ".( ! empty($meta['user_id']) ? (int) $meta['user_id'] : 'NULL').")", FALSE);

            self::_searches_sweep();
        }
        catch (Throwable $e)
        {
            // журнал — не условие работы поиска: молча пропускаем
        }
    }

    public static function recent_searches($limit = 10)
    {
        $limit = max(1, (int) $limit);
        $out   = [];

        try
        {
            $rows = Database::instance()->query(Database::SELECT,
                "SELECT collection, entity, query, results, vector_hits, text_hits,
                        cached, ms, error, created_at
                 FROM prx_115_searches
                 ORDER BY id DESC LIMIT ".$limit, FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            return [];   // журнала нет (до миграции) — панель просто без него
        }

        foreach ($rows as $row)
        {
            $row = (array) $row;

            $out[] = [
                'collection'  => (string) $row['collection'],
                'entity'      => (string) (isset($row['entity']) ? $row['entity'] : ''),
                'query'       => (string) $row['query'],
                'results'     => (int) $row['results'],
                'vector_hits' => (int) $row['vector_hits'],
                'text_hits'   => (int) $row['text_hits'],
                'cached'      => in_array($row['cached'], [TRUE, 't', 'true', 1, '1'], TRUE), // PG 'f' не является TRUE
                'ms'          => (int) $row['ms'],
                'error'       => (string) (isset($row['error']) ? $row['error'] : ''),
                'created_at'  => (string) (isset($row['created_at']) ? $row['created_at'] : ''),
            ];
        }

        return $out;
    }

    public static function search_stats($hours = 24)
    {
        $empty = ['total' => 0, 'empty' => 0, 'errors' => 0, 'cached' => 0, 'avg_ms' => 0, 'last_at' => ''];

        try
        {
            $row = Database::instance()->query(Database::SELECT,
                "SELECT COUNT(*) AS total,
                        COUNT(*) FILTER (WHERE results = 0 AND error = '') AS empty,
                        COUNT(*) FILTER (WHERE error <> '') AS errors,
                        COUNT(*) FILTER (WHERE cached) AS cached,
                        COALESCE(AVG(ms) FILTER (WHERE NOT cached), 0) AS avg_ms,
                        MAX(created_at) AS last_at
                 FROM prx_115_searches
                 WHERE created_at > now() - ".(int) max(1, (int) $hours)." * interval '1 hour'", FALSE)->current();
        }
        catch (Throwable $e)
        {
            return $empty;
        }

        if ( ! $row)
        {
            return $empty;
        }

        $row = (array) $row;

        return [
            'total'   => (int) $row['total'],
            'empty'   => (int) $row['empty'],
            'errors'  => (int) $row['errors'],
            'cached'  => (int) $row['cached'],
            'avg_ms'  => (int) round((float) $row['avg_ms']),
            'last_at' => (string) (isset($row['last_at']) ? $row['last_at'] : ''),
        ];
    }

    protected static function _searches_sweep()
    {
        $cfg  = (array) Kohana::$config->load('prx115.searches');
        $keep = (int) (isset($cfg['keep']) ? $cfg['keep'] : 200);

        if ($keep <= 0)
        {
            return;
        }

        Database::instance()->query(Database::DELETE,
            "DELETE FROM prx_115_searches
              WHERE id NOT IN (SELECT id FROM prx_115_searches ORDER BY id DESC LIMIT ".$keep.")", FALSE);
    }
}
