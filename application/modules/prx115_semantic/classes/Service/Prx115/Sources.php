<?php defined('SYSPATH') or die('No direct script access.');

/** Внутренний компонент prx115; внешние потребители используют Semantic. */
final class Service_Prx115_Sources
{
    public static function sources()
    {
        $out = [];

        foreach ((array) Kohana::$config->load('prx115.sources') as $key => $source)
        {
            $source = (array) $source;

            $collection = trim((string) (isset($source['collection']) ? $source['collection'] : ''));
            $entity     = trim((string) (isset($source['entity']) ? $source['entity'] : ''));
            $sql        = trim((string) (isset($source['sql']) ? $source['sql'] : ''));

            if ($collection === '' OR $entity === '' OR $sql === '')
            {
                continue;   // неполный источник не роняет панель, просто пропускается
            }

            $out[(string) $key] = [
                'key'        => (string) $key,
                'title'      => (string) (isset($source['title']) ? $source['title'] : $collection),
                'collection' => $collection,
                'entity'     => $entity,
                'sql'        => $sql,
            ];
        }

        return $out;
    }

    public static function coverage($only_collection = NULL)
    {
        $out = [];

        foreach (self::sources() as $source)
        {
            if ($only_collection !== NULL AND $source['collection'] !== (string) $only_collection)
            {
                continue;
            }

            $collection = (string) $source['collection'];
            $entity     = (string) $source['entity'];

            $sql = "WITH src AS (".$source['sql']."),
                        col AS (SELECT id FROM prx_115_collections WHERE name = :collection),
                        vec AS (SELECT v.entity_id, v.content_hash
                                FROM prx_115_vectors v, col
                                WHERE v.collection_id = col.id AND v.entity = :entity)
                    SELECT (SELECT COUNT(*) FROM src) AS total,
                           (SELECT COUNT(*) FROM vec) AS have,
                           (SELECT COUNT(*) FROM vec JOIN src ON src.id::text = vec.entity_id
                             WHERE vec.content_hash = md5(src.content)) AS fresh,
                           (SELECT COUNT(*) FROM vec LEFT JOIN src ON src.id::text = vec.entity_id
                             WHERE src.id IS NULL) AS orphans";

            try
            {
                $row = (array) Prx_Db::one($sql, [':collection' => $collection, ':entity' => $entity]);
            }
            catch (Throwable $e)
            {
                // Источника может не быть в этой базе (чужая инсталляция). Это
                // честная проблема в списке, а не молчаливые «100 %».
                $out[] = [
                    'key' => $source['key'], 'title' => $source['title'],
                    'collection' => $source['collection'], 'entity' => $source['entity'],
                    'total' => 0, 'have' => 0, 'fresh' => 0, 'missing' => 0, 'stale' => 0,
                    'orphans' => 0, 'coverage' => NULL,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ];
                continue;
            }

            $total   = (int) $row['total'];
            $have    = (int) $row['have'];
            $fresh   = (int) $row['fresh'];
            $orphans = (int) $row['orphans'];

            $out[] = [
                'key'        => $source['key'],
                'title'      => $source['title'],
                'collection' => $source['collection'],
                'entity'     => $source['entity'],
                'total'      => $total,
                'have'       => $have,
                'fresh'      => $fresh,
                'missing'    => max(0, $total - $have),
                'stale'      => max(0, $have - $fresh),
                'orphans'    => $orphans,
                'coverage'   => $total > 0 ? round($fresh * 100 / $total, 1) : 100.0,
                'error'      => '',
            ];
        }

        return $out;
    }
}
