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
            $rows = DB::select('href', 'title', 'icon', 'sort', 'category', 'note')
                ->from('prx_110_favorites')
                ->where('user_id', '=', $user_id)
                ->order_by('sort', 'ASC')->order_by('id', 'ASC')->execute()->as_array();
        }
        catch (Exception $e)
        {
            // Колонки появились миграциями 010 (метаданные) и 012 (порядок);
            // до их применения список работает по-старому, без настроек.
            Kohana::$log->add(Log::WARNING, 'prx110: favorites без колонок 010/012 (миграции не применены?)');
            $rows = DB::select('href')->from('prx_110_favorites')
                ->where('user_id', '=', $user_id)->order_by('id', 'ASC')->execute()->as_array();
            foreach ($rows as $i => $row)
            {
                $rows[$i] += ['title' => NULL, 'icon' => NULL, 'sort' => NULL, 'category' => NULL, 'note' => NULL];
            }
        }
        if ( ! $rows) return [];

        $catalog = self::catalog_map($ctx);
        $items = [];
        foreach ($rows as $row)
        {
            $href = (string) $row['href'];
            // Переименование/иконка из prx113 сильнее каталога; остальное
            // (nav_paths, видимость) — только из каталога.
            $own_title = ($row['title'] !== NULL AND $row['title'] !== '') ? (string) $row['title'] : NULL;
            $own_icon = ($row['icon'] !== NULL AND $row['icon'] !== '') ? (string) $row['icon'] : NULL;

            if (isset($catalog[$href]))
            {
                $item = $catalog[$href];
                $item['title'] = $own_title ?? $item['title'];
                $item['icon'] = $own_icon ?? $item['icon'];
                $items[] = $item + ['sort' => $row['sort'], 'category' => $row['category'], 'note' => $row['note']];
                continue;
            }
            // Подстраница (звезда в page_header): метаданные — сохранённые
            // при закреплении; доступ проверит сам маршрут при переходе.
            if ( ! self::valid_href($href)) continue;
            $items[] = [
                'href' => $href,
                'title' => $own_title ?? $href,
                'icon' => $own_icon ?? 'fas fa-star',
                'nav_paths' => [],
                'sort' => $row['sort'],
                'category' => $row['category'],
                'note' => $row['note'],
            ];
        }
        return $items;
    }

    /**
     * Сохранить состояние Избранного из страницы управления (prx113):
     * порядок, переименование, иконка, категория, заметка.
     *
     * @param  int   $user_id
     * @param  array $list    упорядоченный список
     *                        [[href, title, icon, category, note], …]
     * @return string|NULL     NULL — ок, иначе человекочитаемая причина
     */
    public static function reorder(int $user_id, array $list): ?string
    {
        if ($user_id <= 0) return 'Требуется вход';

        try
        {
            foreach (array_values($list) as $i => $entry)
            {
                $entry = (array) $entry;
                $href = is_string($entry['href'] ?? NULL) ? (string) $entry['href'] : '';
                if ( ! self::valid_href($href)) return 'Некорректная ссылка: '.mb_substr($href, 0, 80);

                $title = mb_substr(trim((string) ($entry['title'] ?? '')), 0, 200);
                $icon = mb_substr(trim((string) ($entry['icon'] ?? '')), 0, 64);
                $icon = preg_match('/^[a-z0-9][a-z0-9 -]{0,63}$/i', $icon) ? $icon : NULL;
                $category = mb_substr(trim((string) ($entry['category'] ?? '')), 0, 80);
                $note = mb_substr(trim((string) ($entry['note'] ?? '')), 0, 300);

                DB::query(Database::UPDATE, 'UPDATE prx_110_favorites
                    SET title = :title, icon = :icon, category = :category, note = :note, sort = :sort
                    WHERE user_id = :uid AND href = :href')
                    ->parameters([
                        ':title' => $title !== '' ? $title : NULL,
                        ':icon' => $icon,
                        ':category' => $category !== '' ? $category : NULL,
                        ':note' => $note !== '' ? $note : NULL,
                        ':sort' => ($i + 1) * 10,
                        ':uid' => $user_id,
                        ':href' => $href,
                    ])->execute();
            }
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::WARNING, 'prx110: favorites reorder не удался: :e', [':e' => $e->getMessage()]);
            return 'Таблица Избранного ещё без колонок порядка — примените миграцию prx110/012';
        }

        return NULL;
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
                // Новый пин — в конец списка (шаг 10, как в меню).
                $sort = (int) (Prx_Db::one(
                    'SELECT COALESCE(max(sort), 0) + 10 AS s FROM prx_110_favorites WHERE user_id = :uid',
                    [':uid' => $user_id])['s'] ?? 10);
                DB::query(Database::UPDATE, 'INSERT INTO prx_110_favorites (user_id, href, title, icon, sort)
                    VALUES (:uid, :href, :title, :icon, :sort)
                    ON CONFLICT (user_id, href) DO UPDATE SET title = :title, icon = :icon')
                    ->parameters([':uid' => $user_id, ':href' => $href, ':title' => $title, ':icon' => $icon, ':sort' => $sort])->execute();
            }
            catch (Exception $e)
            {
                // Миграции 010/012 ещё не применены — пин без метаданных.
                Kohana::$log->add(Log::WARNING, 'prx110: favorites set без колонок (миграции 010/012?)');
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
