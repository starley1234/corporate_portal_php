<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Кеш ответов внешнего НСИ (prx_400_catalog_cache). payload — то, что
 * вернул провайдер после нормализации: {"items": [...]} или {"item": {...}|null}.
 */
class Model_Prx400_CatalogCache
{
    /** @return array{payload: array, age: int}|null возраст — секунды с получения */
    public static function get(string $key): ?array
    {
        $rows = Prx_Db::select(
            'SELECT payload, CAST(EXTRACT(EPOCH FROM (now() - fetched_at)) AS BIGINT) AS age
               FROM prx_400_catalog_cache WHERE cache_key = :k',
            [':k' => $key]);
        if ($rows === []) return NULL;
        $payload = is_array($rows[0]['payload']) ? $rows[0]['payload'] : json_decode((string) $rows[0]['payload'], TRUE);

        return is_array($payload) ? ['payload' => $payload, 'age' => max(0, (int) $rows[0]['age'])] : NULL;
    }

    public static function put(string $key, array $payload): void
    {
        Prx_Db::execute(
            'INSERT INTO prx_400_catalog_cache (cache_key, payload, fetched_at) VALUES (:k, CAST(:p AS jsonb), now())
             ON CONFLICT (cache_key) DO UPDATE SET payload = EXCLUDED.payload, fetched_at = EXCLUDED.fetched_at',
            [':k' => $key, ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    /** Удалить записи старше $seconds; 0 — весь кеш. */
    public static function purge(int $seconds): int
    {
        return Prx_Db::execute(
            'DELETE FROM prx_400_catalog_cache WHERE fetched_at < now() - make_interval(secs => :s)',
            [':s' => max(0, $seconds)]);
    }
}
