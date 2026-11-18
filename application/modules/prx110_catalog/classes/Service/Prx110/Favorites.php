<?php defined('SYSPATH') or die('No direct script access.');

/** Персональные закладки. Метаданные и видимость всегда берутся из каталога. */
class Service_Prx110_Favorites
{
    const IMPORT_LIMIT = 100;

    /** Текущие видимые закладки пользователя в порядке закрепления. */
    public static function items($user_id, $ctx = NULL)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) return [];

        $rows = DB::select('href')->from('prx_110_favorites')
            ->where('user_id', '=', $user_id)->order_by('id', 'ASC')->execute()->as_array();
        if ( ! $rows) return [];

        $catalog = self::catalog_map($ctx);
        $items = [];
        foreach ($rows as $row)
        {
            $href = (string) $row['href'];
            if (isset($catalog[$href])) $items[] = $catalog[$href];
        }
        return $items;
    }

    /** Идемпотентно установить явное состояние, а не переключать его. */
    public static function set($user_id, $href, $pinned, $ctx = NULL)
    {
        $user_id = (int) $user_id;
        $href = is_string($href) ? $href : '';
        if ($user_id <= 0 OR ! self::valid_href($href)) return FALSE;

        if ($pinned)
        {
            // Нельзя закрепить произвольный URL или сервис, не видимый текущей
            // аудитории. Проверка маршрута сервисом по-прежнему обязательна.
            $catalog = self::catalog_map($ctx);
            if ( ! isset($catalog[$href])) return FALSE;

            DB::query(Database::UPDATE, 'INSERT INTO prx_110_favorites (user_id, href)
                VALUES (:uid, :href) ON CONFLICT (user_id, href) DO NOTHING')
                ->parameters([':uid' => $user_id, ':href' => $href])->execute();
        }
        else
        {
            // Можно удалить свою закладку даже после изменения её видимости.
            DB::delete('prx_110_favorites')->where('user_id', '=', $user_id)
                ->where('href', '=', $href)->execute();
        }
        return TRUE;
    }

    /**
     * По явному выбору пользователя объединить старые браузерные адреса с
     * аккаунтом. Один INSERT атомарен; недоступные адреса не импортируются.
     */
    public static function import($user_id, array $hrefs, $ctx = NULL)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0 OR count($hrefs) > self::IMPORT_LIMIT)
        {
            throw new InvalidArgumentException('Некорректный список Избранного');
        }

        $catalog = self::catalog_map($ctx);
        $valid = [];
        $seen = [];
        $skipped = 0;
        foreach ($hrefs as $value)
        {
            $href = is_string($value) ? $value : '';
            if (isset($seen[$href])) continue;
            $seen[$href] = TRUE;
            if ( ! self::valid_href($href) OR ! isset($catalog[$href]))
            {
                $skipped++;
                continue;
            }
            $valid[] = $href;
        }

        $added = 0;
        if ($valid)
        {
            $values = [];
            $params = [':uid' => $user_id];
            foreach ($valid as $i => $href)
            {
                $key = ':href'.$i;
                $values[] = '(:uid, '.$key.')';
                $params[$key] = $href;
            }
            $added = (int) DB::query(Database::UPDATE,
                'INSERT INTO prx_110_favorites (user_id, href) VALUES '.implode(', ', $values)
                .' ON CONFLICT (user_id, href) DO NOTHING')->parameters($params)->execute();
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /** Разметка активной закладки учитывает вложенные страницы и nav_paths. */
    public static function mark_active(array $items, $uri)
    {
        $path = rtrim((string) $uri, '/') ?: '/';
        $best = NULL;
        $length = -1;
        foreach ($items as $i => $item)
        {
            foreach (array_merge([(string) $item['href']], (array) ($item['nav_paths'] ?? [])) as $candidate)
            {
                $target = rtrim((string) $candidate, '/') ?: '/';
                if (($path === $target OR ($target !== '/' AND strpos($path, $target.'/') === 0))
                    AND strlen($target) > $length)
                {
                    $best = $i;
                    $length = strlen($target);
                }
            }
        }
        foreach ($items as $i => &$item)
        {
            $item['active'] = ($i === $best);
            $href = rtrim((string) $item['href'], '/') ?: '/';
            $item['aria_current'] = $item['active'] ? ($path === $href ? 'page' : 'location') : '';
        }
        unset($item);
        return $items;
    }

    /** Локальный адрес из манифеста, без протокольного URL и управляющих символов. */
    protected static function valid_href($href)
    {
        return is_string($href) AND $href !== '' AND strlen($href) <= 512
            AND $href !== '/services' AND $href[0] === '/' AND substr($href, 0, 2) !== '//'
            AND strpos($href, '\\') === FALSE AND preg_match('/[\x00-\x20\x7f]/', $href) !== 1;
    }

    /** Только минимальные данные для sidebar, отфильтрованные каталогом. */
    protected static function catalog_map($ctx)
    {
        $map = [];
        foreach (Service_Prx110_Catalog::search_json($ctx)['items'] as $item)
        {
            $href = (string) ($item['href'] ?? '');
            if ( ! self::valid_href($href)) continue;
            $map[$href] = [
                'href' => $href,
                'title' => (string) ($item['title'] ?? ''),
                'icon' => (string) ($item['icon'] ?? ''),
                'nav_paths' => (array) ($item['nav_paths'] ?? []),
            ];
        }
        return $map;
    }
}
