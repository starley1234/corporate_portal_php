<?php defined('SYSPATH') or die('No direct script access.');

class Service_Prx112_Plan
{
    public const STATES = ['all', 'approved', 'wip', 'unpublished', 'archived', 'planned'];

    public static function counts(array $domains): array
    {
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($domains as $services)
        {
            foreach ($services as $service)
            {
                $counts['all']++;
                $state = $service['state'];
                if ($state !== 'all' && isset($counts[$state]))
                {
                    $counts[$state]++;
                }
            }
        }
        return $counts;
    }

    public static function filter(array $domains, string $state): array
    {
        if ($state === 'all' || ! in_array($state, self::STATES, TRUE))
        {
            return $domains;
        }

        $filtered = [];
        foreach ($domains as $title => $services)
        {
            $matches = array_filter($services, static function (array $service) use ($state): bool {
                return $service['state'] === $state;
            });
            if ($matches)
            {
                $filtered[$title] = $matches;
            }
        }
        return $filtered;
    }

    public static function domains(Prx_Actor $actor): array
    {
        $ctx = Service_Prx110_Catalog::user_context();
        $modules = array_filter(Service_Prx110_Catalog::plan_modules($ctx), static function (array $module): bool {
            return ($module['kind'] ?? 'module') === 'module';
        });
        $visible = array_column($modules, NULL, 'module_name');
        $can_approve = $actor->can('prx112.approve');
        $domains = [];
        $seen = [];

        $titles = [];

        foreach ((array) Kohana::$config->load('portal')->get('domains') as $domain)
        {
            $title = $domain['title'];
            foreach ($domain['services'] as $service)
            {
                $name = $service['module'];
                $seen[$name] = TRUE;
                $titles[$name] = isset($visible[$name]) ? (string) $visible[$name]['title'] : (string) $service['title'];
                if (isset($visible[$name]))
                {
                    $domains[$title][] = self::module_item($visible[$name],
                        $can_approve && ! in_array($name, Service_Prx110_Publication::PROTECTED_MODULES, TRUE));
                }
                elseif (Service_Prx110_Catalog::get($name) === NULL)
                {
                    $domains[$title][] = [
                        'title' => $service['title'],
                        'module' => NULL,
                        'state' => $service['status'] === 'archived' ? 'archived' : 'planned',
                    ];
                }
            }
        }

        foreach ($visible as $name => $module)
        {
            if (isset($seen[$name]))
            {
                continue;
            }
            $domains[$module['group']][] = self::module_item($module,
                $can_approve && ! in_array($name, Service_Prx110_Publication::PROTECTED_MODULES, TRUE));
        }

        foreach ((array) Kohana::$config->load('enterprise_plan')->get('phases') as $phase)
        {
            foreach ($phase['pipelines'] as $pipeline)
            {
                $domains[$phase['title']][] = [
                    'title' => $pipeline['title'], 'state' => 'planned', 'module' => NULL,
                    'description' => $pipeline['flow'], 'result' => $pipeline['result'],
                    // Какие сервисы плана закрывают этап — названия из portal.php/манифеста.
                    'services' => array_values(array_filter(array_map(
                        static fn ($m) => $titles[$m] ?? NULL, (array) ($pipeline['services'] ?? []))))
                ];
            }
        }
        return $domains;
    }

    private static function module_item(array $module, bool $can_approve): array
    {
        // Здесь только модули, уже прошедшие аудиторию и ACL в plan_modules().
        $eligible = Service_Prx110_Publication::eligible($module);
        $meta = Service_Prx110_Publication::decision_meta($module['module_name']);
        if (($meta['state'] ?? NULL) !== $module['state'])
        {
            $meta = NULL; // Технический архив манифеста не является решением администратора.
        }
        return [
            'title' => $module['title'],
            'module' => $module['module_name'],
            'description' => $module['short'],
            'keywords' => $module['keywords'],
            'state' => $module['state'],
            'manifest_state' => Service_Prx110_Catalog::resolve_manifest_state($module),
            'href' => $eligible && in_array($module['state'], ['approved', 'wip'], TRUE) ? $module['href'] : NULL,
            'icon' => $module['icon'],
            'decided_at' => $meta['decided_at'] ?? NULL,
            'decided_by_name' => $meta['decided_by_name'] ?? NULL,
            'note' => $meta['note'] ?? '',
            'publication_module' => $can_approve && $eligible
                ? $module['module_name'] : NULL,
        ];
    }
}
