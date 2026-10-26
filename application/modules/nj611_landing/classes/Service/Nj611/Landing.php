<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj611: сборка групп лендингов из конфига и манифестов nj110_catalog.
 *
 * Конфиг (config/nj611_landing.php) описывает тексты, быстрый старт, шаги
 * и FAQ; модули группы подтягиваются манифестами каталога (title/short/
 * icon/href). Не подключённые модули исключаются — группа никогда не
 * показывает «мёртвые» ссылки. БД не использует.
 */
class Service_Nj611_Landing {

    /** Все группы с модулями из каталога; [] — модуль не подключён. */
    public static function groups(): array
    {
        $config = (array) Kohana::$config->load('nj611_landing.groups');
        $catalog = class_exists('Service_Nj110_Catalog')
            ? Service_Nj110_Catalog::modules()
            : [];

        $by_slug = [];
        foreach ($catalog as $m)
        {
            $by_slug[(string) ($m['slug'] ?? '')] = $m;
        }

        $out = [];

        foreach ($config as $slug => $g)
        {
            $modules = [];

            foreach ((array) ($g['modules'] ?? []) as $mslug)
            {
                if (isset($by_slug[$mslug]))
                {
                    $m = $by_slug[$mslug];
                    $modules[] = [
                        'title' => (string) $m['title'],
                        'short' => (string) ($m['short'] ?? ''),
                        'icon'  => (string) ($m['icon'] ?? 'fas fa-puzzle-piece'),
                        'href'  => (string) $m['href'],
                        'badge' => (string) ($m['badge'] ?? ''),
                    ];
                }
            }

            // группа без живых модулей и без быстрого старта не публикуется
            if (empty($modules))
            {
                continue;
            }

            $out[$slug] = [
                'slug'    => (string) $slug,
                'href'    => '/s/'.$slug,
                'title'   => (string) ($g['title'] ?? $slug),
                'icon'    => (string) ($g['icon'] ?? 'fas fa-folder'),
                'h1'      => (string) ($g['h1'] ?? ''),
                'seo_title'       => (string) ($g['seo_title'] ?? ''),
                'seo_description' => (string) ($g['seo_description'] ?? ''),
                'promise' => (string) ($g['promise'] ?? ''),
                'quickstart' => (array) ($g['quickstart'] ?? []),
                'steps'   => (array) ($g['steps'] ?? []),
                'modules' => $modules,
                'stats'   => (array) ($g['stats'] ?? []),
                'faq'     => (array) ($g['faq'] ?? []),
            ];
        }

        return $out;
    }

    /** Одна группа по slug или NULL. */
    public static function group(string $slug): ?array
    {
        $groups = self::groups();

        return $groups[$slug] ?? NULL;
    }

    /**
     * Служебные страницы платформы (не инструменты): новости, справка.
     * Список slug-ов — в конфиге ('extras'); неподключённые отбрасываются.
     */
    public static function extras(): array
    {
        $config = (array) Kohana::$config->load('nj611_landing.extras');
        $catalog = class_exists('Service_Nj110_Catalog')
            ? Service_Nj110_Catalog::modules()
            : [];

        $by_slug = [];
        foreach ($catalog as $m)
        {
            $by_slug[(string) ($m['slug'] ?? '')] = $m;
        }

        $out = [];

        foreach ($config as $slug)
        {
            if (isset($by_slug[$slug]))
            {
                $out[] = [
                    'title' => (string) $by_slug[$slug]['title'],
                    'short' => (string) ($by_slug[$slug]['short'] ?? ''),
                    'icon'  => (string) ($by_slug[$slug]['icon'] ?? 'fas fa-circle-info'),
                    'href'  => (string) $by_slug[$slug]['href'],
                ];
            }
        }

        return $out;
    }

}
