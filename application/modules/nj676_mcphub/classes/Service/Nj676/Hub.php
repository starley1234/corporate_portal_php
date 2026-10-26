<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инструменты каталога MCP для агентов (config/mcp.php модуля nj676).
 *
 * Зачем отдельный сервис: каталог должны уметь читать и агенты, подключённые
 * к /mcp/api/rpc, а не только люди в браузере. Ответы короткие и текстовые —
 * слабая модель должна понять их с первого раза.
 */
class Service_Nj676_Hub
{
    /** mcp_catalog_search: поиск серверов словами задачи. */
    public static function catalog_search(array $args)
    {
        $query = mb_substr(trim(self::_string($args['query'] ?? '')), 0, 160);

        if ($query === '')
        {
            return ['error' => 'Передайте query: что должен уметь сервер. Например: «поиск в интернете».'];
        }

        $limit = max(1, min(8, (int) ($args['limit'] ?? 5)));
        $filters = ['q' => $query, 'per' => $limit];

        $category = self::_string($args['category'] ?? '');
        if (isset(Service_Nj676_McpHub::CATEGORIES[$category]))
        {
            $filters['category'] = $category;
        }

        if ( ! empty($args['no_auth']))
        {
            $filters['auth'] = 'none';
        }

        $result = Service_Nj676_McpHub::search($filters);
        $servers = [];

        foreach ($result['servers'] as $server)
        {
            $servers[] = [
                'slug' => $server['slug'],
                'name' => $server['display_name'] ?? $server['name'],
                'original_name' => $server['name'],
                'what' => mb_substr($server['display_description'] ?? $server['description'], 0, 300),
                'transport' => $server['transport'],
                'auth' => $server['auth_mode'],
                'health' => $server['health_status'],
                'readiness' => $server['readiness']['readiness'] ?? '',
                'trust' => (int) ($server['trust'] ?? 0),
                'stars' => $server['stars'],
                'install' => $server['package_hint'],
                'page' => '/mcphub/'.$server['slug'],
            ];
        }

        if ($servers === [])
        {
            return [
                'found' => 0,
                'hint' => 'Ничего не нашлось по «'.$query.'». Попробуйте проще: «поиск», «база данных», '
                    .'«файлы», «почта», «браузер».',
            ];
        }

        return [
            'found' => count($servers),
            'total_in_catalog' => $result['total'],
            'how_to_use' => 'Выберите slug и вызовите mcp_server_plan {"slug": "…"} — получите конфигурацию. '
                .'Удалённые серверы можно вызывать через шлюз /mcphub/mcp (hub_call_tool).',
            'servers' => $servers,
        ];
    }

    /** mcp_server_plan: конфигурация и требования по одному серверу. */
    public static function server_plan(array $args)
    {
        $slug = mb_substr(trim(self::_string($args['slug'] ?? '')), 0, 100);

        if ($slug === '')
        {
            return ['error' => 'Передайте slug сервера из mcp_catalog_search.'];
        }

        $server = Service_Nj676_McpHub::get($slug);

        if ( ! $server)
        {
            return ['error' => 'Сервера «'.$slug.'» нет в опубликованном каталоге. Найдите его через mcp_catalog_search.'];
        }

        $config = Service_Nj676_McpHub::export_config($server);
        $snippet = $config === NULL ? '' : Service_Nj676_McpHub::config_snippet($server);

        $warnings = [
            'Каталог не проверяет поведение кода: это сторонний проект, запускаемый у вас.',
            'Значения переменных окружения подставьте сами — в конфигурации только плейсхолдеры.',
        ];

        if ($server['health_status'] !== 'operational')
        {
            $warnings[] = 'Сервер не проверялся живьём ('.$server['health_label'].'): возможны сюрпризы.';
        }
        if ($server['repo_archived'])
        {
            $warnings[] = 'Репозиторий помечен архивным — проект не развивается.';
        }

        return [
            'slug' => $server['slug'],
            'name' => $server['display_name'] ?? $server['name'],
            'original_name' => $server['name'],
            'what' => $server['display_description'] ?? $server['description'],
            'transport' => $server['transport'],
            'auth' => $server['auth_mode'],
            'health' => $server['health_status'],
            'readiness' => $server['readiness']['readiness'] ?? '',
            'trust' => (int) ($server['trust'] ?? 0),
            'stars' => $server['stars'],
            'feedback_summary' => Service_Nj676_Telemetry::feedback_summary((string) $server['slug']),
            'recent_feedback' => Service_Nj676_Telemetry::recent_feedback((string) $server['slug'], 5),
            'license' => $server['license'],
            'env_keys' => $server['env_keys'],
            'page' => '/mcphub/'.$server['slug'],
            'config' => $snippet !== '' ? $snippet : NULL,
            'config_file' => $server['is_remote']
                ? 'любой MCP-клиент (url)'
                : (Service_Nj676_McpHub::client_targets()['claude']['file'] ?? 'claude_desktop_config.json'),
            'note' => $snippet === ''
                ? 'Готовой конфигурации нет: не указан проверенный адрес или закреплённая версия пакета. '
                    .'Смотрите README проекта.'
                : 'Вставьте блок mcpServers в файл конфигурации клиента.',
            'warnings' => $warnings,
        ];
    }

    protected static function _string($value)
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
