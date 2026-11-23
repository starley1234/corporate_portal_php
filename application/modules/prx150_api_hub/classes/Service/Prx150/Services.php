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
}
