<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Локальный справочник prx_400_catalog. Небольшой — для партии строк
 * читается целиком (точный перебор), большой — предотбор pg_trgm на строку;
 * без pg_trgm большой справочник честно перебирается целиком (+ журнал).
 */
final class Prx400_Catalog_Local implements Prx400_Catalog_Provider
{
    public function name(): string
    {
        return 'local';
    }

    public function writable(): bool
    {
        return TRUE;
    }

    public function count(): ?int
    {
        return Model_Prx400_Catalog::count();
    }

    public function batch(): callable
    {
        $t = Service_Prx400_Limits::match();
        $count = Model_Prx400_Catalog::count();
        if ($count <= $t['full_scan_max'] OR ! Model_Prx400_Catalog::trigram_available())
        {
            if ($count > $t['full_scan_max'])
            {
                Log_Plain::event(Log::WARNING, 'prx400 stage=match full_scan catalog=:n (pg_trgm недоступен)', [':n' => $count]);
            }
            $all = Model_Prx400_Catalog::all();

            return static fn (string $query): array => $all;
        }

        return static fn (string $query): array => Model_Prx400_Catalog::candidates($query, $t['candidates']);
    }

    public function search(string $query, int $limit): array
    {
        $query = trim($query);
        if ($query === '') return [];
        $exact = Model_Prx400_Catalog::by_code($query);
        $found = Model_Prx400_Catalog::page(Service_Prx400_Catalog::words($query), $limit, 0)['rows'];
        if (count($found) < $limit AND Model_Prx400_Catalog::trigram_available())
        {
            $found = array_merge($found, Model_Prx400_Catalog::candidates($query, $limit));
        }
        $out = [];
        foreach (array_merge($exact !== NULL ? [$exact] : [], $found) as $item)
        {
            $out[(string) $item['code']] = $item;
        }

        return array_slice(array_values($out), 0, $limit);
    }

    public function by_code(string $code): ?array
    {
        return Model_Prx400_Catalog::by_code($code);
    }
}
