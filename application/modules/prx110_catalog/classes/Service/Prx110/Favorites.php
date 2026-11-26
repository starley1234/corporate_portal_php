<?php defined('SYSPATH') or die('No direct script access.');

/** Персональные закладки. Метаданные и видимость всегда берутся из каталога. */
class Service_Prx110_Favorites
{
    /** Текущие видимые закладки пользователя в порядке закрепления. */
    public static function items($user_id, $ctx = NULL)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) return [];

        try
        {
            $rows = DB::select('href', 'title', 'icon')->from('prx_110_favorites')
                ->where('user_id', '=', $user_id)->order_by('id', 'ASC')->execute()->as_array();
        }
        catch (Exception $e)
        {
            // Колонки title/icon появились миграцией 010; до её применения
            // список работает по-старому, без метаданных подстраниц.
            Kohana::$log->add(Log::WARNING, 'prx110: favorites без метаданных (миграция 010?)');
            $rows = DB::select('href')->from('prx_110_favorites')
                ->where('user_id', '=', $user_id)->order_by('id', 'ASC')->execute()->as_array();
            foreach ($rows as $i => $row) { $rows[$i]['title'] = NULL; $rows[$i]['icon'] = NULL; }
        }
        if ( ! $rows) return [];

        $catalog = self::catalog_map($ctx);
        $items = [];
        foreach ($rows as $row)
        {
            $href = (string) $row['href'];
            if (isset($catalog[$href]))
            {
                $items[] = $catalog[$href];
                continue;
            }
            // Подстраница (звезда в page_header): метаданные — сохранённые
            // при закреплении; доступ проверит сам маршрут при переходе.
            if ( ! self::valid_href($href)) continue;
            $items[] = [
                'href' => $href,
                'title' => (string) ($row['title'] !== NULL && $row['title'] !== '' ? $row['title'] : $href),
                'icon' => (string) ($row['icon'] !== NULL && $row['icon'] !== '' ? $row['icon'] : 'fas fa-star'),
                'nav_paths' => [],
            ];
        }
        return $items;
    }

    /** Идемпотентно установить явное состояние, а не переключать его. */
    public static function set($user_id, $href, $pinned, $ctx = NULL, $title = NULL, $icon = NULL)
    {
        $user_id = (int) $user_id;
        $href = is_string($href) ? $href : '';
        if ($user_id <= 0 OR ! self::valid_href($href)) return FALSE;

        // Метаданные страницы (для пинов вне каталога — подстраницы).
        $title = mb_substr(trim((string) $title), 0, 200);
        $icon = mb_substr(trim((string) $icon), 0, 64);
        $icon = preg_match('/^[a-z0-9][a-z0-9 -]{0,63}$/i', $icon) ? $icon : NULL;
        $title = $title !== '' ? $title : NULL;

        if ($pinned)
        {
            // Сервис каталога — только видимый текущей аудитории; метаданные
            // всегда свежие из каталога. Подстраница (звезда в page_header) —
            // любая внутренняя страница: доступ проверит сам её маршрут.
            $catalog = self::catalog_map($ctx);
            if (isset($catalog[$href]))
            {
                $title = $catalog[$href]['title'];
                $icon = $catalog[$href]['icon'];
            }

            try
            {
                DB::query(Database::UPDATE, 'INSERT INTO prx_110_favorites (user_id, href, title, icon)
                    VALUES (:uid, :href, :title, :icon)
                    ON CONFLICT (user_id, href) DO UPDATE SET title = :title, icon = :icon')
                    ->parameters([':uid' => $user_id, ':href' => $href, ':title' => $title, ':icon' => $icon])->execute();
            }
            catch (Exception $e)
            {
                // Миграция 010 ещё не применена — пин без метаданных.
                Kohana::$log->add(Log::WARNING, 'prx110: favorites set без метаданных (миграция 010?)');
                DB::query(Database::UPDATE, 'INSERT INTO prx_110_favorites (user_id, href)
                    VALUES (:uid, :href) ON CONFLICT (user_id, href) DO NOTHING')
                    ->parameters([':uid' => $user_id, ':href' => $href])->execute();
            }
        }
        else
        {
            // Можно удалить свою закладку даже после изменения её видимости.
            DB::delete('prx_110_favorites')->where('user_id', '=', $user_id)
                ->where('href', '=', $href)->execute();
        }
        return TRUE;
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
