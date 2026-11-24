<?php defined('SYSPATH') or die('No direct script access.');

/** Чипы — только маршруты текущего модуля. Межмодульные ссылки остаются в теле/каталоге. */
final class Prx_ModuleNavigation
{
    public static function local_items(array $items, string $controller, array $manifests): array
    {
        if (!preg_match('/^Prx(\d{3})(?:_|$)/i', $controller, $match)) return $items;
        $owner = (int) $match[1];
        $paths = [];
        foreach ($manifests as $manifest)
        {
            $id = (int) ($manifest['id'] ?? 0);
            foreach (array_merge([(string) ($manifest['href'] ?? ''), (string) ($manifest['admin']['href'] ?? '')],
                (array) ($manifest['nav_paths'] ?? [])) as $path)
            {
                if (is_string($path) && $path !== '' && $path[0] === '/') $paths[rtrim($path, '/')] = $id;
            }
        }
        return array_values(array_filter($items, static function ($item) use ($owner, $paths) {
            $href = (string) ($item['href'] ?? '');
            if (substr($href, 0, 1) === '#') return TRUE;
            if (substr($href, 0, 1) !== '/' || substr($href, 0, 2) === '//') return FALSE;
            $path = (string) parse_url($href, PHP_URL_PATH);
            $found = NULL; $length = -1;
            foreach ($paths as $prefix => $id)
            {
                if ($prefix !== '' && ($path === $prefix || strpos($path, $prefix.'/') === 0) && strlen($prefix) > $length)
                { $found = $id; $length = strlen($prefix); }
            }
            if ($found !== NULL) return $found === $owner;
            return preg_match('~^/prx'.sprintf('%03d', $owner).'(?:_|/|$)~', $path) === 1;
        }));
    }
}
