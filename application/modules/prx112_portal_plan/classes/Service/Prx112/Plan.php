<?php defined('SYSPATH') or die('No direct script access.');

class Service_Prx112_Plan
{
    public static function domains(Prx_Actor $actor): array
    {
        $ctx = Service_Prx110_Catalog::user_context();
        $visible = array_column(Service_Prx110_Catalog::plan_modules($ctx), NULL, 'module_name');
        $can_approve = $actor->can('prx112.approve');
        $domains = [];
        $seen = [];

        foreach ((array) Kohana::$config->load('portal')->get('domains') as $domain)
        {
            $title = $domain['title'];
            foreach ($domain['services'] as $service)
            {
                $name = $service['module'];
                $seen[$name] = TRUE;
                if (isset($visible[$name]))
                {
                    $domains[$title][] = self::module_item($visible[$name], $ctx, $can_approve);
                }
                elseif (Service_Prx110_Catalog::get($name) === NULL)
                {
                    $domains[$title][] = [
                        'title' => $service['title'],
                        'state' => $service['status'] === 'archived' ? 'archived' : 'planned',
                    ];
                }
            }
        }

        foreach ($visible as $name => $module)
        {
            if (isset($seen[$name]) || in_array($name, ['prx110_catalog', 'prx112_portal_plan'], TRUE)
                || ($module['kind'] ?? 'module') !== 'module')
            {
                continue;
            }
            $domains[$module['group']][] = self::module_item($module, $ctx, $can_approve);
        }

        foreach ((array) Kohana::$config->load('enterprise_plan')->get('phases') as $phase)
        {
            foreach ($phase['pipelines'] as $pipeline)
            {
                $domains[$phase['title']][] = [
                    'title' => $pipeline['title'], 'state' => 'planned',
                    'description' => $pipeline['flow'], 'result' => $pipeline['result'],
                ];
            }
        }
        return $domains;
    }

    private static function module_item(array $module, array $ctx, bool $can_approve): array
    {
        return [
            'title' => $module['title'],
            'description' => $module['short'],
            'state' => $module['state'],
            'manifest_state' => Service_Prx110_Catalog::resolve_manifest_state($module),
            'href' => Service_Prx110_Catalog::can_see($module, $ctx) ? $module['href'] : NULL,
            'icon' => $module['icon'],
            'publication_module' => $can_approve && Service_Prx110_Publication::eligible($module)
                ? $module['module_name'] : NULL,
        ];
    }
}
