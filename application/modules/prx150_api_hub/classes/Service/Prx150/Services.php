<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Реестр scope внешнего API: собирается из манифестов модулей.
 *
 * Модуль объявляет в `config/prx_module.php`:
 *
 *   'api_scopes' => [
 *       'prx450.parse' => ['title' => 'Распознавание документов', 'rate_limit' => 60],
 *   ],
 *
 * Центрального списка нет: владелец сервиса сам решает, какие права выдаёт.
 * Префикс scope обязан совпадать с ID модуля, чтобы нельзя было заявить чужое.
 */
class Service_Prx150_Services
{
    /** @return array<string, array{module:string,title:string,description:string,rate_limit:int}> */
    public static function scopes(): array
    {
        $out = [];

        foreach (Manifest::all() as $module => $manifest)
        {
            $declared = $manifest['api_scopes'] ?? [];

            if ( ! is_array($declared))
            {
                continue;
            }

            $prefix = isset($manifest['id']) ? 'prx'.(int) $manifest['id'].'.' : '';

            foreach ($declared as $scope => $meta)
            {
                $scope = (string) $scope;

                if ($prefix === '' OR strpos($scope, $prefix) !== 0
                    OR ! preg_match('/^prx\d+\.[a-z0-9_.]+$/D', $scope) OR ! is_array($meta))
                {
                    if (Kohana::$log)
                    {
                        Kohana::$log->add(Log::WARNING, 'prx150: некорректный api_scopes в :module: :scope', [
                            ':module' => $module, ':scope' => $scope,
                        ]);
                    }
                    continue;
                }

                if (isset($out[$scope]))
                {
                    if (Kohana::$log)
                    {
                        Kohana::$log->add(Log::WARNING, 'prx150: scope :scope объявлен дважды (:first и :module)', [
                            ':scope' => $scope, ':first' => $out[$scope]['module'], ':module' => $module,
                        ]);
                    }
                    continue;
                }

                $out[$scope] = [
                    'module'      => (string) $module,
                    'title'       => (string) ($meta['title'] ?? $scope),
                    'description' => (string) ($meta['description'] ?? ''),
                    'rate_limit'  => max(1, (int) ($meta['rate_limit'] ?? 60)),
                ];
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Каталог API-сервисов для вкладки «Сервисы»: владельцы scope, сгруппированные
     * по модулям, с состоянием публикации — тем же, что у витрины /services
     * (одобрен = approved). По умолчанию страница показывает только одобренные,
     * «все» — по явному запросу.
     *
     * @return array[] module, title, short, icon, href, state, scopes[]
     */
    public static function catalog(): array
    {
        $by_module = [];

        foreach (self::scopes() as $scope => $meta)
        {
            $by_module[(string) $meta['module']][$scope] = $meta;
        }

        $out = [];

        foreach ($by_module as $module => $list)
        {
            $m = Manifest::get($module);

            $out[] = [
                'module' => (string) $module,
                'title'  => (string) ($m['title'] ?? $module),
                'short'  => (string) ($m['short'] ?? ''),
                'icon'   => (string) ($m['icon'] ?? 'fas fa-code'),
                'href'   => (string) ($m['href'] ?? ''),
                'state'  => self::_publication_state($m),
                'scopes' => $list,
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return $out;
    }

    /**
     * Состояние публикации модуля-владельца: решение витрины каталога, если
     * prx110 подключён; иначе — только манифест (status/catalog).
     */
    protected static function _publication_state(array $m): string
    {
        if (class_exists('Service_Prx110_Catalog'))
        {
            try
            {
                return (string) Service_Prx110_Catalog::resolve_state($m);
            }
            catch (Throwable $e) { /* решение витрины недоступно — берём манифест */ }
        }

        if ((string) ($m['status'] ?? '') === 'deprecated')
        {
            return 'archived';
        }

        if (($m['catalog'] ?? TRUE) === FALSE)
        {
            return 'unpublished';
        }

        return (string) ($m['status'] ?? '') === 'stable' ? 'approved' : 'wip';
    }
}
