<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP Hub: публичный каталог, JSON-RPC шлюз для агентов и закрытая админка
 * автосборки.
 *
 * Публично (без аккаунта):
 *   GET  /mcphub                 каталог с поиском, фильтрами и сортировкой;
 *   GET  /mcphub/<slug>          карточка: что умеет, конфиг, похожие;
 *   GET  /mcphub/export/<slug>   конфигурация клиента (JSON);
 *   GET  /mcphub/api/search      JSON-поиск (не JSON-RPC);
 *   POST /mcphub/api/rpc         Meta-MCP шлюз (Bearer: ключ из /mcp).
 *
 * По токену воркера: /mcphub/api/discover-* и /mcphub/api/tick.
 * По праву nj676.admin: /mcphub/admin*, запуск автосборки.
 */
class Controller_Nj676_McpHub extends Controller_Erp100_Base
{
    /** Версия протокола, согласованная на initialize (для заголовка ответа). */
    protected static $protocol_version = '';

    public function before()
    {
        parent::before();
        $this->set_title('MCP Hub — каталог MCP-серверов и инструментов');
        $this->set_description('Живой каталог MCP-серверов: поиск по назначению, рейтинг надёжности, готовые конфиги для Claude, Cursor и Windsurf и один шлюз для ИИ-агентов.');
        $this->set_keywords('MCP каталог серверы инструменты tools list AI агенты Claude Cursor Windsurf шлюз');

        $nav = [
            ['title' => 'Каталог', 'href' => '/mcphub', 'icon' => 'fas fa-layer-group'],
            ['title' => 'Подключить агента', 'href' => '/mcp', 'icon' => 'fas fa-plug'],
        ];
        if ($this->can('nj676.admin'))
        {
            $nav[] = ['title' => 'Автосборка', 'href' => '/mcphub/admin', 'icon' => 'fas fa-wand-magic-sparkles'];
        }
        $this->set_module_nav($nav);
    }

    // ---------------------------------------------------------------------
    // Публичные страницы
    // ---------------------------------------------------------------------

    public function action_index()
    {
        $filters = $this->_filters();
        $result = Service_Nj676_McpHub::search($filters);

        // Спрос без ключа: по чему ищут на сайте. Запускать автосборку по
        // анонимному запросу нельзя (это открыло бы кошелёк любому боту), но
        // тема ложится в журнал и её заберёт плановая автосборка.
        if ($filters['q'] !== '')
        {
            Service_Nj676_Demand::log($filters['q'], 'catalog', (int) $result['total'], 0);
        }

        $this->template->content = View::factory('676/index', [
            'servers' => $result['servers'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'per' => $result['per'],
            'filters' => $filters,
            'facets' => Service_Nj676_McpHub::facets(),
            'stats' => Service_Nj676_McpHub::stats(),
            // «Готово к вызову» — честное число по всей базе: на витрине из
            // 60 популярных карточек готовых обычно нет, и цифра «3» рядом с
            // галочкой объясняет, что галочка вообще даст.
            'ready_stats' => Service_Nj676_McpHub::ready_stats(),
            // Причина, если готовность не посчиталась: витрина обязана
            // показывать сбой, а не уверенный ноль.
            'ready_error' => Service_Nj676_McpHub::ready_error(),
            'featured' => $filters['q'] === '' && $filters['category'] === '' && $filters['page'] <= 1
                ? Service_Nj676_McpHub::featured(3) : [],
            'gems' => $filters['q'] === '' && $filters['category'] === '' && empty($filters['gems']) && $filters['page'] <= 1
                ? Service_Nj676_Audit::gems(6) : [],
            'audit_stats' => Service_Nj676_Audit::backlog(),
            'live' => Service_Nj676_McpHub::live_now(8),
            'registry' => Service_Nj676_Registry::stats(),
            'gateway_url' => URL::base('https').'mcphub/mcp',
            'transports' => Service_Nj676_McpHub::TRANSPORTS,
            'auth_modes' => Service_Nj676_McpHub::AUTH,
            'health_modes' => Service_Nj676_McpHub::HEALTH,
            'categories' => Service_Nj676_McpHub::CATEGORIES,
            'category_icons' => Service_Nj676_McpHub::CATEGORY_ICONS,
            'sorts' => Service_Nj676_McpHub::SORTS,
            'can_admin' => $this->can('nj676.admin'),
        ]);
    }

    public function action_server()
    {
        $slug = (string) $this->request->param('slug');
        $server = Service_Nj676_McpHub::get($slug);

        if ( ! $server)
        {
            throw HTTP_Exception::factory(404, 'Карточка MCP-сервера не найдена.');
        }

        Service_Nj676_McpHub::record_view($slug);

        $title_name = (string) ($server['display_name'] ?? $server['name']);
        $meta_desc = (string) ($server['display_description'] ?? $server['description']);
        $this->set_title(($title_name !== '' ? $title_name : $server['name']).' — MCP-сервер');
        $this->set_description(mb_substr(strip_tags($meta_desc !== '' ? $meta_desc : $server['description']), 0, 300));

        $config = Service_Nj676_McpHub::export_config($server);
        $meta_trust = Service_Nj676_Gateway::server_trust_and_feedback($server, 8);
        $server['trust'] = $meta_trust['trust'];
        if ($meta_trust['agent_rating'] !== NULL)
        {
            $server['agent_rating'] = $meta_trust['agent_rating'];
            $server['agent_reviews_count'] = $meta_trust['agent_reviews_count'];
        }

        $this->template->content = View::factory('676/detail', [
            'server' => $server,
            'feedback_summary' => $meta_trust['feedback_summary'],
            'recent_feedback' => $meta_trust['recent_feedback'],
            'audit_history' => Service_Nj676_Audit::for_server($slug, 5),
            'audit_Queued' => $this->_query_value('audit_queued') === '1',
            'csrf' => Security::token(),
            'related' => Service_Nj676_McpHub::related($server, 4),
            'health_modes' => Service_Nj676_McpHub::HEALTH,
            'category_labels' => Service_Nj676_McpHub::CATEGORIES,
            'category_icons' => Service_Nj676_McpHub::CATEGORY_ICONS,
            'transport_labels' => Service_Nj676_McpHub::TRANSPORTS,
            'auth_labels' => Service_Nj676_McpHub::AUTH,
            'config' => $config,
            'config_text' => $config === NULL ? '' : Service_Nj676_McpHub::config_snippet($server),
            'client_targets' => Service_Nj676_McpHub::client_targets(),
            'gateway_url' => URL::base('https').'mcphub/mcp',
            'can_admin' => $this->can('nj676.admin'),
        ]);
    }

    /** Отдаёт только клиентскую конфигурацию; Hub ничего не запускает. */
    public function action_export()
    {
        $server = Service_Nj676_McpHub::get((string) $this->request->param('slug'));
        $config = $server ? Service_Nj676_McpHub::export_config($server) : NULL;

        if ($config === NULL)
        {
            throw HTTP_Exception::factory(404, 'Для этой карточки нельзя безопасно собрать конфигурацию.');
        }

        unset($config['_note']);
        // Без этого after() базового контроллера отрендерит шаблон и перезапишет
        // JSON: скачивание конфигурации отдавало HTML страницы.
        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->headers('Content-Disposition', 'attachment; filename="mcphub-'.$server['slug'].'.json"');
        $this->response->body(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** JSON-поиск для библиотек и виджетов; это не MCP JSON-RPC. */
    public function action_api_search()
    {
        $filters = $this->_filters();
        $result = Service_Nj676_McpHub::search($filters);

        if ($filters['q'] !== '')
        {
            Service_Nj676_Demand::log($filters['q'], 'catalog', (int) $result['total'], 0);
        }

        $servers = [];
        $slugs = array_map(static function ($s) { return (string) $s['slug']; }, $result['servers']);
        $telemetry = $slugs !== [] ? Service_Nj676_Telemetry::stats_by_server($slugs) : [];

        foreach ($result['servers'] as $server)
        {
            $slug = (string) $server['slug'];
            $stat = (array) ($telemetry[$slug] ?? []);
            $base_trust = (int) ($server['trust'] ?? 0);
            $fb_up = (int) ($stat['up'] ?? 0);
            $fb_down = (int) ($stat['down'] ?? 0);
            $trust = max(0, min(100, $base_trust + ($fb_up * 2) - ($fb_down * 5)));

            $servers[] = [
                'slug' => $server['slug'],
                'name' => (string) ($server['display_name'] ?? $server['name']),
                'original_name' => $server['name'],
                'description' => (string) ($server['display_description'] ?? $server['description']),
                'original_description' => $server['description'],
                'repository' => $server['repo_url'],
                'transport' => $server['transport'], 'auth' => $server['auth_mode'],
                'categories' => $server['categories'], 'health_status' => $server['health_status'],
                'health_checked_at' => $server['health_checked_at'], 'stars' => $server['stars'],
                'latency_ms' => $server['latency_ms'],
                'readiness' => $server['readiness']['readiness'] ?? 'unknown',
                'tools' => Service_Nj676_McpHub::schemas_as_objects($server['tools']),
                'install' => $server['package_hint'],
                'remote' => $server['remote_url'], 'trust' => $trust,
                'calls' => (int) ($stat['calls'] ?? 0),
                'success_rate' => ((int) ($stat['calls'] ?? 0)) > 0 ? round(100 * (float) ($stat['rate'] ?? 0), 1) : NULL,
                'feedback_count' => (int) ($stat['feedback'] ?? 0),
                'agent_rating' => $stat['agent_rating'] ?? $server['agent_rating'] ?? NULL,
                'agent_reviews_count' => max((int) ($stat['feedback'] ?? 0), (int) ($server['agent_reviews_count'] ?? 0)),
                'utility_score' => (int) ($server['audit_score'] ?? 0),
                'safety_score' => (int) ($server['safety_score'] ?? 0),
                'audit_verdict' => (string) ($server['audit_verdict'] ?? ''),
                'is_gem' => ! empty($server['is_gem']),
                'audit_summary' => (string) ($server['audit_summary'] ?? ''),
                'page_url' => '/mcphub/'.$server['slug'],
            ];
        }

        $this->_json([
            'ok' => TRUE,
            'count' => count($servers),
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'per_page' => $result['per'],
            'limit' => $result['per'],
            'sort' => $result['sort'] ?? ($filters['sort'] !== '' ? $filters['sort'] : 'popular'),
            'next_page' => $result['page'] < $result['pages'] ? $result['page'] + 1 : NULL,
            'gateway' => '/mcphub/mcp',
            'note' => 'Каталог MCP-серверов. Для вызова инструментов агентам — MCP-эндпоинт /mcphub/mcp.',
            'servers' => $servers,
        ]);
    }

    /**
     * JSON-эндпоинт чтения отзывов агентов (/mcphub/api/feedback, /mcphub/api/reviews).
     */
    public function action_api_feedback()
    {
        $slug = trim((string) ($this->request->param('slug') ?: ($this->_query_value('slug') ?: $this->_query_value('server'))));
        $limit = max(1, min(50, (int) ($this->_query_value('limit') ?: ($this->_query_value('per') ?: 20))));

        if ($slug !== '')
        {
            $server = Service_Nj676_McpHub::get($slug);
            if ( ! $server)
            {
                $this->_json([
                    'ok' => FALSE,
                    'code' => 'TOOL_NOT_FOUND',
                    'error' => 'Сервера «'.$slug.'» нет в опубликованном каталоге.',
                ], 404);

                return;
            }

            $meta = Service_Nj676_Gateway::server_trust_and_feedback($server, $limit);
            $this->_json([
                'ok' => TRUE,
                'server' => $server['slug'],
                'name' => (string) ($server['display_name'] ?? $server['name']),
                'trust' => $meta['trust'],
                'stars' => $meta['stars'],
                'readiness' => $server['readiness']['readiness'] ?? 'unknown',
                'health_status' => $meta['health_status'],
                'latency_ms' => $server['latency_ms'],
                'calls' => $meta['calls'],
                'success_rate' => $meta['success_rate'],
                'feedback_summary' => $meta['feedback_summary'],
                'count' => count($meta['recent_feedback']),
                'feedback' => $meta['recent_feedback'],
                'reviews' => $meta['recent_feedback'],
            ]);

            return;
        }

        $summary = Service_Nj676_Telemetry::feedback_summary('');
        $recent = Service_Nj676_Telemetry::recent_feedback('', $limit);
        $this->_json([
            'ok' => TRUE,
            'feedback_summary' => $summary,
            'count' => count($recent),
            'feedback' => $recent,
            'reviews' => $recent,
        ]);
    }

    /**
     * JSON-карточка одного сервера (/mcphub/api/server?slug=… или /mcphub/api/server/<slug>).
     */
    public function action_api_server()
    {
        $slug = trim((string) ($this->request->param('slug') ?: ($this->_query_value('slug') ?: $this->_query_value('server'))));
        if ($slug === '')
        {
            $this->_json([
                'ok' => FALSE,
                'code' => 'INVALID_ARGUMENT',
                'error' => 'Передайте slug сервера: /mcphub/api/server?slug=<slug>.',
            ], 400);

            return;
        }

        $server = Service_Nj676_McpHub::get($slug);
        if ( ! $server)
        {
            $this->_json([
                'ok' => FALSE,
                'code' => 'TOOL_NOT_FOUND',
                'error' => 'Сервера «'.$slug.'» нет в опубликованном каталоге.',
            ], 404);

            return;
        }

        $meta = Service_Nj676_Gateway::server_trust_and_feedback($server, 10);
        $this->_json([
            'ok' => TRUE,
            'slug' => $server['slug'],
            'name' => (string) ($server['display_name'] ?? $server['name']),
            'original_name' => $server['name'],
            'description' => (string) ($server['display_description'] ?? $server['description']),
            'original_description' => $server['description'],
            'repository' => $server['repo_url'],
            'transport' => $server['transport'],
            'auth' => $server['auth_mode'],
            'categories' => $server['categories'],
            'health_status' => $meta['health_status'],
            'health_checked_at' => $server['health_checked_at'],
            'latency_ms' => $server['latency_ms'],
            'readiness' => $server['readiness']['readiness'] ?? 'unknown',
            'trust' => $meta['trust'],
            'stars' => $meta['stars'],
            'calls' => $meta['calls'],
            'success_rate' => $meta['success_rate'],
            'agent_rating' => $meta['agent_rating'],
            'agent_reviews_count' => $meta['agent_reviews_count'],
            'utility_score' => $meta['utility_score'],
            'safety_score' => $meta['safety_score'],
            'audit_verdict' => $meta['audit_verdict'],
            'is_gem' => $meta['is_gem'],
            'audit_summary' => $meta['audit_summary'],
            'audit_report' => $meta['audit_report'],
            'feedback_summary' => $meta['feedback_summary'],
            'recent_feedback' => $meta['recent_feedback'],
            'tools' => Service_Nj676_McpHub::schemas_as_objects($server['tools']),
            'install' => $server['package_hint'],
            'remote' => $server['remote_url'],
            'page_url' => '/mcphub/'.$server['slug'],
        ]);
    }

    /**
     * JSON-витрина «алмазов» каталога (/mcphub/api/gems).
     */
    public function action_api_gems()
    {
        $limit = max(1, min(24, (int) ($this->_query_value('limit') ?: 12)));
        $gems = Service_Nj676_Audit::gems($limit);
        $backlog = Service_Nj676_Audit::backlog();

        $items = [];
        foreach ($gems as $server)
        {
            $meta = Service_Nj676_Gateway::server_trust_and_feedback($server, 3);
            $items[] = [
                'slug' => $server['slug'],
                'name' => (string) ($server['display_name'] ?? $server['name']),
                'description' => (string) ($server['display_description'] ?? $server['description']),
                'utility_score' => (int) ($server['audit_score'] ?? 0),
                'safety_score' => (int) ($server['safety_score'] ?? 0),
                'verdict' => (string) ($server['audit_verdict'] ?? 'gem'),
                'is_gem' => ! empty($server['is_gem']),
                'audit_summary' => (string) ($server['audit_summary'] ?? ''),
                'use_cases' => (array) ($server['use_cases'] ?? []),
                'audit_report' => $meta['audit_report'],
                'trust' => $meta['trust'],
                'agent_rating' => $meta['agent_rating'],
                'agent_reviews_count' => $meta['agent_reviews_count'],
                'stars' => $meta['stars'],
                'readiness' => $server['readiness']['readiness'] ?? 'unknown',
                'latency_ms' => $server['latency_ms'],
                'page_url' => '/mcphub/'.$server['slug'],
            ];
        }

        $this->_json([
            'ok' => TRUE,
            'count' => count($items),
            'stats' => $backlog,
            'gems' => $items,
        ]);
    }

    // ---------------------------------------------------------------------
    // Meta-MCP шлюз (JSON-RPC 2.0, stateless)
    // ---------------------------------------------------------------------

    /** Старый адрес шлюза: тот же обработчик, что и /mcphub/mcp. */
    public function action_api_rpc()
    {
        $this->action_mcp();
    }

    /**
     * MCP-эндпоинт (Streamable HTTP). Один адрес для любого агента.
     *
     * POST /mcphub/mcp
     *   initialize → notifications/initialized → tools/list → tools/call
     *
     * Сервер без состояния: Mcp-Session-Id — метка подключения, обратно её не
     * требуем, поэтому прокси и балансировщики не мешают. Анонимный доступ
     * разрешён только к безопасной части: поиск по каталогу, статус и вызов
     * серверов, которым не нужен ключ. Инструменты платформы — по ключу с /mcp.
     */
    public function action_mcp()
    {
        $this->auto_render = FALSE;

        // Браузерные клиенты и локальные агенты ходят с другого origin.
        $this->response->headers('Access-Control-Allow-Origin', '*');
        $this->response->headers('Access-Control-Allow-Methods', 'POST, GET, DELETE, OPTIONS');
        $this->response->headers('Access-Control-Allow-Headers', 'Content-Type, Authorization, Mcp-Session-Id, MCP-Protocol-Version');
        $this->response->headers('Access-Control-Expose-Headers',
            'Content-Type, Retry-After, Mcp-Session-Id, MCP-Protocol-Version, '
                .'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Budget, '
                .'X-RateLimit-Limit-Hour, X-RateLimit-Remaining-Hour, X-RateLimit-Limit-Minute, '
                .'X-RateLimit-Remaining-Minute, X-RateLimit-Call-Limit-Hour, X-RateLimit-Call-Limit-Minute, '
                .'X-RateLimit-Call-Remaining-Hour, X-RateLimit-Call-Remaining-Minute, '
                .'X-RateLimit-Read-Limit-Hour, X-RateLimit-Read-Limit-Minute, '
                .'X-RateLimit-Read-Remaining-Hour, X-RateLimit-Read-Remaining-Minute');
        $this->response->headers('Cache-Control', 'no-store');

        // Гигиена (замечание теста 24.10.2026): эндпоинт отдаёт
        // `Access-Control-Allow-Origin: *`, а базовый контроллер к этому моменту
        // уже стартовал сессию сайта — и каждый ответ приносил новую куку без
        // HttpOnly/Secure/SameSite. Агенту куки не нужны вовсе: личность — ключ
        // в заголовке или IP анонима, состояние — в запросе. Снимаем все
        // Set-Cookie, чтобы MCP-ответ не ставил браузерных cookie.
        if ( ! headers_sent())
        {
            header_remove('Set-Cookie');
        }

        if ($this->request->method() === Request::OPTIONS)
        {
            $this->response->status(204);

            return;
        }

        // Streamable HTTP: DELETE закрывает сессию. Шлюз stateless, поэтому
        // закрывать нечего — но клиент обязан получить успех, а не 405, иначе
        // он считает транспорт сломанным и уходит с ошибкой.
        if ($this->request->method() === Request::DELETE)
        {
            $this->response->status(204);

            return;
        }

        // Streamable HTTP: GET — канал сервер→клиент для уведомлений.
        // OpenCode не видел новые инструменты без переподключения, потому что
        // шлюз молчал о listChanged. Отдаём поток с уведомлением сразу: клиент
        // получит его и перезапросит tools/list.
        if ($this->request->method() === Request::GET
            AND mb_stripos((string) $this->request->headers('accept'), 'text/event-stream') !== FALSE)
        {
            [$authorized, $ctx, $auth_error] = $this->_authorize_agent(TRUE, NULL);

            if ( ! $authorized)
            {
                $this->_rpc_send(['jsonrpc' => '2.0', 'id' => NULL,
                    'error' => ['code' => -32001, 'message' => $auth_error]], 403);

                return;
            }

            if ( ! empty($ctx['anonymous']))
            {
                [$allowed, $message, $retry_after] = $this->_anon_limit('read');

                if ( ! $allowed)
                {
                    $this->response->headers('Retry-After', (string) $retry_after);
                    $this->_rpc_send(['jsonrpc' => '2.0', 'id' => NULL, 'error' => [
                        'code' => -32000, 'message' => $message,
                        'data' => ['code' => 'RATE_LIMITED', 'retry_after' => $retry_after],
                    ]], 429);

                    return;
                }
            }

            $this->response->headers('Content-Type', 'text/event-stream; charset=utf-8');
            $this->response->headers('Connection', 'close');
            $this->response->headers('X-Accel-Buffering', 'no');

            // Уведомление — только когда `tools/list` РЕАЛЬНО менялся с прошлого
            // подсоединения. Раньше поток всегда присылал list_changed, и клиент
            // делал лишний tools/list на каждое подключение (замечание теста
            // 24.10.2026). Отпечаток считаем по самому ответу tools/list, а не
            // по каталогу: агенту отдаются семь корневых инструментов шлюза,
            // они не зависят от карточек, и обновление каталога списка не меняет.
            // Каталог меняется — это видно через hub_search_tools, нотификация
            // для этого не нужна.
            $fingerprint = $this->_catalog_fingerprint();
            $cache = Cache::instance('file');
            $client_scope = substr(hash('sha256', (string) $this->request->headers('mcp-session-id').'|'.(string) ($ctx['token_hash'] ?? '')), 0, 16);
            $key = 'nj676_toollist_fingerprint_'.$client_scope;
            $previous = (string) $cache->get($key, '');
            $changed = ($previous !== '' AND $previous !== $fingerprint);
            $cache->set($key, $fingerprint, 7 * 86400);

            $payload = $changed
                ? 'event: message'."\n".'data: '.json_encode([
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/tools/list_changed',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n"
                // Честный комментарий-SSE: список корневых инструментов не
                // менялся, соединение живое. Клиент не перезапрашивает
                // tools/list зря. Каталог (тысячи серверов) здесь не при чём:
                // он доступен через hub_search_tools в любой момент.
                : ': tools/list не менялся — корневые инструменты шлюза стабильны; '
                    .'каталог ищется через hub_search_tools'."\n\n";

            $this->response->body('retry: 5000'."\n".$payload);
            $this->response->status(200);

            return;
        }

        if ($this->request->method() !== Request::POST)
        {
            $this->response->headers('Allow', 'POST, GET, DELETE');
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->body(json_encode([
                'error' => 'Это MCP-эндпоинт. Клиент общается с ним методом POST (JSON-RPC 2.0).',
                'endpoint' => 'POST '.URL::base('https').'mcphub/mcp',
                'tools' => Service_Nj676_Gateway::ROOT_TOOLS,
                'docs' => URL::base('https').'mcphub',
                'example' => 'curl -s -X POST '.URL::base('https').'mcphub/mcp -H "Content-Type: application/json" '
                    .'-d \'{"jsonrpc":"2.0","id":1,"method":"tools/list"}\'',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            $this->response->status(405);

            return;
        }

        // Бюджет считается после разбора тела: чтение (search/status/describe)
        // и вызовы инструментов — разные расходы, и общий счётчик 120/час
        // упирался за один рабочий прогон агента (тест 24.10.2026).
        [$authorized, $ctx, $auth_error] = $this->_authorize_agent(TRUE, NULL);

        if ( ! $authorized)
        {
            $this->_rpc_send(['jsonrpc' => '2.0', 'id' => NULL,
                'error' => ['code' => -32001, 'message' => $auth_error]], 403);

            return;
        }

        $raw = (string) $this->request->body();
        $body = json_decode($raw, TRUE);

        if ( ! is_array($body))
        {
            // -32700 — ошибка разбора, HTTP 400. Раньше отдавали 200, и клиент
            // считал, что запрос дошёл: по JSON-RPC это ошибка транспорта.
            $this->_rpc_send(['jsonrpc' => '2.0', 'id' => NULL,
                'error' => ['code' => -32700, 'message' => 'Некорректный JSON']], 400);

            return;
        }

        // Батчи удалены из протокола 2025-06-18: массив верхнего уровня — это
        // уже не MCP. Отвечаем понятной ошибкой, а не «нет method».
        if ($body !== [] AND array_is_list($body))
        {
            $this->_rpc_send(['jsonrpc' => '2.0', 'id' => NULL,
                'error' => ['code' => -32600, 'message' => 'Батчи не поддерживаются: отправьте один JSON-RPC объект.']], 400);

            return;
        }

        if ( ! empty($ctx['anonymous']))
        {
            $kind = $this->_request_kind($body);
            [$allowed, $message, $retry_after] = $this->_anon_limit($kind);

            if ( ! $allowed)
            {
                $this->response->headers('Retry-After', (string) $retry_after);
                $this->_rpc_send(['jsonrpc' => '2.0', 'id' => $body['id'] ?? NULL,
                    'error' => [
                        'code' => -32000,
                        'message' => $message,
                        'data' => [
                            'code' => 'RATE_LIMITED',
                            'retry_after' => $retry_after,
                            'budget' => $kind,
                            'hint' => 'Ключ с /mcp снимает анонимные лимиты.',
                            'request_id' => Service_Nj676_Gateway::request_id(),
                        ],
                    ]], 429);

                return;
            }
        }

        // Версия протокола в ответе: клиенты (в т.ч. строгие SDK) сверяют её
        // с тем, что запросили на initialize, и передают в MCP-Protocol-Version.
        $header_version = trim((string) $this->request->headers('mcp-protocol-version'));
        $supported_versions = ['2025-06-18', '2025-03-26', '2024-11-05'];
        $active_version = in_array($header_version, $supported_versions, TRUE)
            ? $header_version
            : (string) (self::$protocol_version ?: '2025-06-18');
        $this->response->headers('MCP-Protocol-Version', $active_version);

        [$status, $payload] = $this->_rpc_handle($body, $ctx);
        $this->_rpc_send($payload, $status);
    }

    /**
     * Один JSON-RPC запрос → [http_status, payload|null].
     * NULL означает «ответа нет» (уведомление).
     */
    protected function _rpc_handle(array $body, array $ctx)
    {
        $id = $body['id'] ?? NULL;
        $method = (string) ($body['method'] ?? '');
        $params = isset($body['params']) && is_array($body['params']) ? $body['params'] : [];

        if ($method === '')
        {
            return [200, ['jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32600, 'message' => 'Нет method']]];
        }

        if (strpos($method, 'notifications/') === 0)
        {
            // Клиент подтверждает инициализацию или сообщает о закрытии — это
            // не запрос, ответа нет. Список инструментов шлюза стабилен, а
            // изменения каталога клиент увидит по GET-потоку (SSE).
            return [202, NULL];
        }

        try
        {
            switch ($method)
            {
                case 'initialize':
                    $requested = trim((string) ($params['protocolVersion'] ?? ''));
                    $supported = ['2025-06-18', '2025-03-26', '2024-11-05'];

                    $negotiated = in_array($requested, $supported, TRUE) ? $requested : '2025-06-18';
                    self::$protocol_version = $negotiated;
                    $this->response->headers('MCP-Protocol-Version', $negotiated);

                    // Mcp-Session-Id: шлюз stateless, но клиенты (SDK) ждут этот
                    // заголовок и без него считают соединение «вне сессии».
                    // Отдаём идентификатор подключения и не требуем его обратно.
                    $session_id = trim((string) $this->request->headers('mcp-session-id'));

                    if ($session_id === '')
                    {
                        $session_id = 'mcphub-'.bin2hex(random_bytes(12));
                    }

                    $this->response->headers('Mcp-Session-Id', $session_id);
                    $this->response->headers('Access-Control-Expose-Headers',
                        'Content-Type, Retry-After, Mcp-Session-Id, MCP-Protocol-Version, '
                        .'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Budget, '
                        .'X-RateLimit-Limit-Hour, X-RateLimit-Remaining-Hour, X-RateLimit-Limit-Minute, '
                        .'X-RateLimit-Remaining-Minute, X-RateLimit-Call-Limit-Hour, X-RateLimit-Call-Limit-Minute, '
                        .'X-RateLimit-Call-Remaining-Hour, X-RateLimit-Call-Remaining-Minute, '
                        .'X-RateLimit-Read-Limit-Hour, X-RateLimit-Read-Limit-Minute, '
                        .'X-RateLimit-Read-Remaining-Hour, X-RateLimit-Read-Remaining-Minute');

                    return [200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                        'protocolVersion' => $negotiated,
                        // listChanged: TRUE — клиент перезапрашивает tools/list по
                        // уведомлению, а не только после переподключения.
                        'capabilities' => [
                            'tools' => ['listChanged' => TRUE],
                            'logging' => new stdClass,
                        ],
                        'serverInfo' => ['name' => 'n2j-mcphub', 'version' => '2.0'],
                        'instructions' => 'MCP Hub N2J — один адрес вместо сотен серверов. '
                            .'Порядок: hub_ready (что доступно) или hub_status → hub_search_tools {query} '
                            .'→ hub_describe_tool → hub_call_tool. '
                            .'Каталог содержит серверы всего мира; удалённые вызываются проксированием '
                            .'(server + tool), локальные отдаются готовой конфигурацией клиента.',
                    ]]];

                case 'ping':
                    return [200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass]];

                case 'resources/list':
                    return [200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => []]]];

                case 'prompts/list':
                    return [200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => []]]];

                case 'tools/list':
                    return [200, ['jsonrpc' => '2.0', 'id' => $id,
                        'result' => ['tools' => Service_Nj676_Gateway::tool_list()]]];

                case 'tools/call':
                    $name = trim((string) ($params['name'] ?? ''));
                    $arguments = isset($params['arguments']) && is_array($params['arguments'])
                        ? $params['arguments'] : [];

                    if ( ! in_array($name, Service_Nj676_Gateway::ROOT_TOOLS, TRUE))
                    {
                        return [200, ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
                            'code' => -32601,
                            'message' => 'Инструмент «'.$name.'» не найден.',
                            'data' => [
                                'code' => 'TOOL_NOT_FOUND',
                                'available_tools' => Service_Nj676_Gateway::ROOT_TOOLS,
                                'request_id' => Service_Nj676_Gateway::request_id(),
                            ],
                        ]]];
                    }

                    return [200, ['jsonrpc' => '2.0', 'id' => $id,
                        'result' => Service_Nj676_Gateway::call($name, $arguments, $ctx)]];

                default:
                    return [200, ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
                        'code' => -32601, 'message' => 'Метод не найден: '.$method]]];
            }
        }
        catch (Database_Exception $e)
        {
            // Ошибка БД — это НАША поломка, а не ответ агенту. 24.10.2026
            // наружу уходил сырой Postgres: «столбец "auth_required_at" не
            // существует» + фрагмент SQL с именами колонок. Это утечка схемы
            // и подсказка тому, кто ищет дыру. Наружу — короткий INTERNAL с
            // request_id, подробности — в журнал.
            return $this->_internal_error($id, $e);
        }
        catch (Kohana_Exception $e)
        {
            // Свои, осмысленные исключения отдаём как есть — но только если в
            // тексте нет следов SQL/драйвера.
            if (preg_match('/SQLSTATE|LINE \d+:|column .+ does not exist|pg_|relation .+ does not exist/iu',
                $e->getMessage()))
            {
                return $this->_internal_error($id, $e);
            }

            return [200, ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
                'code' => -32000, 'message' => $e->getMessage(),
                'data' => ['code' => 'INTERNAL', 'request_id' => Service_Nj676_Gateway::request_id()]]]];
        }
        catch (Throwable $e)
        {
            return $this->_internal_error($id, $e);
        }
    }

    /**
     * Внутренняя ошибка: наружу — код, request_id и «что делать», внутрь — всё.
     *
     * «Что делать» здесь важнее текста: агент по `INTERNAL` не должен гадать,
     * повторять ему вызов или звать человека.
     */
    protected function _internal_error($id, Throwable $e)
    {
        $request_id = Service_Nj676_Gateway::request_id();

        Log_Plain::event(Log::ERROR, 'nj676: шлюз упал (:id): :e',
            [':id' => $request_id,
             ':e' => $e->getMessage().' @ '.basename((string) $e->getFile()).':'.$e->getLine()]);

        return [200, ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
            'code' => -32603,
            'message' => 'Внутренняя ошибка шлюза. Повторите один раз; если повторится — '
                .'сообщите номер запроса: '.$request_id,
            'data' => [
                'code' => 'INTERNAL',
                'request_id' => $request_id,
                'retryable' => TRUE,
            ],
        ]]];
    }

    /** Ответ JSON-RPC с правильным статусом; NULL — пустой ответ (202). */
    protected function _rpc_send($payload, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');

        if ($payload === NULL)
        {
            $this->response->body('');

            return;
        }

        $this->response->body(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * GET /mcphub/api/selfcheck — «шлюз живой?» без подключения клиента.
     *
     * Одна ссылка вместо «поставьте MCP-клиент и посмотрите». ?http=1
     * добавляет настоящий POST на собственный эндпоинт: это проверка не только
     * кода, но и сети/веб-сервера. Ручка ограничена по частоте: каждый прогон
     * дергает два чужих сервера, а мы не должны становиться их нагрузкой.
     */
    public function action_api_selfcheck()
    {
        $per_hour = (int) getenv('NJ676_SELFCHECK_LIMIT');
        $per_hour = $per_hour > 0 ? $per_hour : 12;

        try
        {
            $cache = Cache::instance('file');
            $key = 'nj676_selfcheck_'.substr(hash('sha256', $this->_client_ip()), 0, 24);
            $count = (int) $cache->get($key, 0) + 1;
            $cache->set($key, $count, 3600);

            if ($count > $per_hour)
            {
                $this->_json([
                    'ok' => FALSE,
                    'error' => 'Слишком много самопроверок с этого адреса ('.$per_hour.' в час).',
                ], 429);

                return;
            }
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: лимит самопроверок не посчитан: :e', [':e' => $e->getMessage()]);
        }

        $with_http = $this->_query_value('http') === '1';
        $result = Service_Nj676_Gateway::selfcheck(['http' => $with_http]);

        $this->_json([
            'ok' => $result['ok'],
            'endpoint' => URL::base('https').'mcphub/mcp',
            'checked_at' => $result['checked_at'],
            'ms' => $result['ms'],
            'steps' => $result['steps'],
            'note' => 'Самопроверка: схема, статус, поиск, живой tools/list и реальный вызов. '
                .'?http=1 добавляет POST на сам эндпоинт.',
        ], $result['ok'] ? 200 : 503);
    }

    /**
     * Живые инструменты удалённого сервера: GET /mcphub/api/tools/<slug>
     * ?fresh=1 — перепроверить, иначе из кэша карточки.
     */
    public function action_api_tools()
    {
        $slug = (string) $this->request->param('slug');
        $fresh = $this->_query_value('fresh') === '1';

        $result = Service_Nj676_Gateway::remote_tools($slug, $fresh);

        $this->_json([
            'ok' => $result['ok'],
            'slug' => $slug,
            // Схемы пришли из jsonb: `{}` в PHP-массиве стал бы `[]` при
            // обратном кодировании, и строгий клиент отверг бы ответ.
            'tools' => Service_Nj676_McpHub::schemas_as_objects($result['tools']),
            'count' => count($result['tools']),
            'cached' => $result['cached'],
            'latency_ms' => $result['ms'],
            'error' => $result['error'],
        ], $result['ok'] ? 200 : 502);
    }

    // ---------------------------------------------------------------------
    // Админка: автосборка
    // ---------------------------------------------------------------------

    public function action_admin()
    {
        $this->_admin_access();

        $action = (string) $this->request->param('action');
        if ($action === 'save')
        {
            // Старый адрес формы модерации (/mcphub/admin/save) — оставляем рабочим.
            return $this->action_save();
        }
        if ($action === 'demand')
        {
            // Кнопка «Собрать по спросу»: маршрут /mcphub/admin(/<action>)
            // отдаёт сюда, действие — отдельным именем.
            return $this->action_demand();
        }
        if ($action === 'maintain')
        {
            return $this->action_maintain();
        }
        if ($action === 'audit')
        {
            return $this->action_audit();
        }

        $this->template->content = View::factory('676/admin', $this->_admin_data());
    }

    /**
     * Данные админки для одной вкладки.
     *
     * Раньше страница собирала всё сразу: карточки, спрос, индекс, перевод,
     * журнал. На тысяче карточек это и медленно, и нечитаемо. Теперь оболочка
     * берёт только общие показатели, а тяжёлое — по вкладке:
     *
     *   build   — сборка: прогон, источники, реестр;
     *   cards   — карточки: таблица, правка, проверки;
     *   quality — поиск и текст: смысловой индекс, перевод;
     *   demand  — спрос агентов;
     *   runs    — прогоны: итог, отчёт по строкам, журнал.
     *
     * Старые ссылки работают: `?run=N` открывает «Прогоны», `?slug=...` —
     * «Карточки», так что закладки и журнал переучивать не нужно.
     *
     * @return array<string,mixed>
     */
    protected function _admin_data()
    {
        $tab = (string) $this->_query_value('tab');
        $run_id = (int) $this->_query_value('run');
        $slug = (string) $this->_query_value('slug');

        if ( ! in_array($tab, ['build', 'cards', 'quality', 'demand', 'runs'], TRUE))
        {
            if ($run_id > 0)
            {
                $tab = 'runs';
            }
            elseif ($slug !== '')
            {
                $tab = 'cards';
            }
            else
            {
                $tab = 'build';
            }
        }

        $run = $run_id > 0 ? Service_Nj676_McpHub::run($run_id) : NULL;

        // Ссылка ?run=N ведёт на конкретный прогон, но старые прогоны чистятся.
        // Раньше в этом случае страница честно писала «ниже — последняя сборка»
        // и... не показывала её: вьюха получала NULL. Теперь фолбэк настоящий.
        if ( ! $run)
        {
            $run = Service_Nj676_McpHub::latest_run();
        }

        $data = [
            'tab' => $tab,
            'csrf' => Security::token(),
            'error' => '',
            'stats' => Service_Nj676_McpHub::stats(),
            // Телеметрия вызовов — один запрос на всю страницу, не N+1.
            'calls_stats' => Service_Nj676_Telemetry::stats_by_server(),
            'runs' => Service_Nj676_McpHub::runs(20),
            'run' => $run,
            // Номер из адреса нужен вьюхе, чтобы честно сказать «такого прогона нет»:
            // иначе страница молча показывает последнюю сборку, и ссылка из журнала
            // (например, после чистки старых прогонов) выглядит как «ничего не произошло».
            'run_requested' => $run_id,
            'default_n' => Service_Nj676_McpHub::DEFAULT_DISCOVER,
            'max_n' => Service_Nj676_McpHub::MAX_DISCOVER,
            'auto_enabled' => (string) getenv('NJ676_AUTO_DISCOVER') === '1',
            // Состояние индекса, перевода и спроса нужно всегда: по нему считаются
            // счётчики на вкладках («сколько ещё не переведено», «без ответа»).
            'semantic' => Service_Nj676_Semantic::status(),
            'translate' => Service_Nj676_Translate::status(),
            'demand_stats' => Service_Nj676_Demand::stats(),
            // Очередь живой проверки нужна на двух вкладках: на «Карточках» —
            // кнопкой, на «Сборке» — подсказкой, где эта кнопка живёт.
            'probe_backlog' => Service_Nj676_McpHub::probe_backlog(),
            'audit_backlog' => Service_Nj676_Audit::backlog(),
            'gems' => Service_Nj676_Audit::gems(8),
            // ИИ-анализ каталога: последний отчёт, история и идущий прогон.
            // Считаем всегда — это три дешёвых запроса, а вкладка «Сборка»
            // иначе показывала бы кнопку без ответа.
            'analysis_last' => Service_Nj676_Analysis::latest(),
            'analysis_active' => Service_Nj676_Analysis::active(),
            'analysis_history' => Service_Nj676_Analysis::history(),
            'analysis_message' => $this->_query_value('analysis') !== ''
                ? ['id' => (int) $this->_query_value('analysis'),
                   'error' => mb_substr((string) $this->_query_value('analysis_error'), 0, 200)]
                : NULL,
        ];

        if ($tab === 'build')
        {
            $data['registry'] = Service_Nj676_Registry::stats();
            $data['maintain_result'] = $this->_query_value('maintained') === '1'
                ? [
                    'registry' => (int) $this->_query_value('m_registry'),
                    'schemas' => (int) $this->_query_value('m_schemas'),
                    'broken' => (int) $this->_query_value('m_broken'),
                    'probe' => (int) $this->_query_value('m_probe'),
                    'batches' => (int) $this->_query_value('m_batches'),
                    'audit' => (int) $this->_query_value('m_audit'),
                    'translate' => (int) $this->_query_value('m_translate'),
                    'translate_jobs' => (int) $this->_query_value('m_tr_jobs'),
                    'semantic' => (int) $this->_query_value('m_semantic'),
                    'discover' => (int) $this->_query_value('m_discover'),
                ]
                : NULL;
        }

        if ($tab === 'quality')
        {
            // Результаты кнопок — из адреса после редиректа: форма не должна
            // оставлять админа с «поставлено 0» без объяснения.
            $data['translate_result'] = ($this->_query_value('translated') !== ''
                    OR $this->_query_value('translate_error') !== '')
                ? ['job_id' => (int) $this->_query_value('job'),
                   'limit' => (int) $this->_query_value('translated'),
                   // «Перевести всё» ставит цепочку задач: показываем, сколько.
                   'jobs' => (int) $this->_query_value('translate_started'),
                   // Сколько карточек освободили от мёртвого клейма.
                   'released' => (int) $this->_query_value('released'),
                   'error' => mb_substr((string) $this->_query_value('translate_error'), 0, 200)]
                : NULL;
            $data['index_result'] = ($this->_query_value('indexed') !== ''
                    OR $this->_query_value('index_error') !== '')
                ? ['queued' => (int) $this->_query_value('indexed'),
                   'skipped' => (int) $this->_query_value('index_skipped'),
                   'error' => mb_substr((string) $this->_query_value('index_error'), 0, 200)]
                : NULL;
        }

        if ($tab === 'cards')
        {
            $edit = $slug !== '' ? Service_Nj676_McpHub::get($slug, TRUE) : NULL;
            $card_q = mb_substr($this->_query_value('q'), 0, 120);
            $card_filter = $this->_query_value('filter');

            $data['servers'] = Service_Nj676_McpHub::all_for_admin(200, ['q' => $card_q, 'filter' => $card_filter]);
            $data['card_q'] = $card_q;
            $data['card_filter'] = $card_filter;
            $data['edit'] = $edit;
            $data['form'] = $edit ? $this->_form_data($edit) : [];
            $data['saved'] = $this->_query_value('saved') === '1';
            $data['transports'] = Service_Nj676_McpHub::TRANSPORTS;
            $data['auth_modes'] = Service_Nj676_McpHub::AUTH;
            $data['health_modes'] = Service_Nj676_McpHub::HEALTH;
            $data['categories'] = Service_Nj676_McpHub::CATEGORIES;
            $data['schema_check'] = $this->_query_value('checked') !== ''
                ? ['checked' => (int) $this->_query_value('checked'),
                   'broken' => (int) $this->_query_value('broken'),
                   'urls' => (int) $this->_query_value('urls')]
                : NULL;
        }

        if ($tab === 'demand')
        {
            // Спрос агентов: что спрашивают и по чему ничего не нашлось. Это не
            // украшение админки — из этих строк берутся темы автосборки.
            $days = (int) $this->_query_value('days');

            $data['demand_days'] = in_array($days, [7, 30, 90], TRUE) ? $days : Service_Nj676_Demand::WINDOW_DAYS;
            $data['demand'] = Service_Nj676_Demand::top($data['demand_days'], Service_Nj676_Demand::TOP_LIMIT);
            $data['demand_stats'] = Service_Nj676_Demand::stats();
            $data['demand_next'] = Service_Nj676_Demand::next_theme();
            $data['demand_empty'] = $this->_query_value('demand') === 'empty';
        }

        if ($tab === 'runs')
        {
            // Журнал строк прогона: что именно добавлено/обновлено/пропущено.
            $data['run_items'] = $run ? Service_Nj676_McpHub::run_items($run) : ['items' => [], 'reconstructed' => FALSE];
        }

        return $data;
    }

    /**
     * POST /mcphub/admin/schemas — перепроверить схемы уже собранных карточек.
     *
     * Сеть не нужна: проверяем сохранённые `tools`. Один инструмент с
     * `properties: []` валит весь `tools/list` у строгих клиентов, поэтому
     * бейдж «схема» должен появляться и на старых карточках, а не только
     * после новой живой проверки.
     */
    public function action_schemas()
    {
        $this->_admin_access();

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу.');
        }

        $result = Service_Nj676_McpHub::revalidate_schemas(1000);

        HTTP::redirect('/mcphub/admin?tab=cards&checked='.(int) $result['checked']
            .'&broken='.(int) $result['broken'].'&urls='.(int) ($result['urls'] ?? 0), 303);
    }

    /**
     * POST /mcphub/admin/audit — поставить в очередь полевое исследование открытых MCP-серверов
     * воркером-агентом («поиск алмазов»).
     */
    public function action_audit()
    {
        $this->_admin_access();

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу.');
        }

        $slug = mb_substr(trim((string) $this->request->post('slug')), 0, 120);
        $limit = (int) $this->request->post('limit');
        $limit = $limit > 0 ? min(Service_Nj676_Audit::MAX_BATCH, $limit) : Service_Nj676_Audit::DEFAULT_BATCH;

        $res = Service_Nj676_Audit::queue($limit, $slug, (int) ($this->_current_user->id ?? 0));

        if ($slug !== '' AND (string) $this->request->post('return') === 'detail')
        {
            HTTP::redirect('/mcphub/'.rawurlencode($slug).'?audit_queued=1', 303);
        }

        if ((int) ($res['run_id'] ?? 0) > 0)
        {
            HTTP::redirect('/mcphub/admin?tab=runs&run='.(int) $res['run_id'], 303);
        }

        HTTP::redirect('/mcphub/admin?tab=cards&audit_empty=1', 303);
    }

    /**
     * POST /mcphub/admin/semantic — поставить в очередь смысловой индекс каталога.
     *
     * Тяжёлую работу делает воркер (nj115 + эмбеддинг-сервер), сайт только
     * ставит задачи: HTTP-запрос не должен длиться минутами.
     */
    public function action_semantic()
    {
        $this->_admin_access();

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу.');
        }

        // force — «пересобрать всё заново»: нужно после смены модели
        // эмбеддингов или починки индекса, когда хеши текста не изменились.
        $force = (string) $this->request->post('force') === '1';

        $result = Service_Nj676_Semantic::index(1000, $force);

        $url = '/mcphub/admin?tab=quality&indexed='.(int) ($result['queued'] ?? 0)
            .'&index_skipped='.(int) ($result['skipped'] ?? 0);

        // Ошибку нельзя терять: без неё админка показывала «поставлено 0» с
        // зелёной галочкой, и выключенный эмбеддинг-сервер выглядел успехом.
        if (trim((string) ($result['error'] ?? '')) !== '')
        {
            $url .= '&index_error='.rawurlencode(mb_substr((string) $result['error'], 0, 200));
        }

        HTTP::redirect($url, 303);
    }

    /**
     * POST /mcphub/admin/translate — поставить перевод карточек на русский.
     *
     * Перевод делает воркер (у сайта нет модели): здесь только задача в nj120.
     * Порция по умолчанию 20 карточек — столько, сколько модель переваривает
     * за один вызов без обрезки ответа.
     */
    public function action_translate()
    {
        $this->_admin_access();

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу.');
        }

        // Два режима:
        //   all   — «Перевести всё»: цепочка независимых задач на весь каталог
        //           (одна упавшая задача не обнуляет работу, остальные едут);
        //   batch — порция на 20 карточек, чтобы догонять по чуть-чуть.
        $mode = (string) $this->request->post('mode') === 'all' ? 'all' : 'batch';

        if ($mode === 'all')
        {
            $batch = Service_Nj676_Translate::queue_all();
            $error = (string) $batch['error'];

            // Нечего переводить — не показываем «задача поставлена», это враньё.
            if ($error === '' && (int) $batch['count'] === 0)
            {
                if ((int) $batch['total'] === 0)
                {
                    $error = 'Переводить нечего: все карточки уже на русском.';
                }
                elseif ((int) $batch['claimed'] > 0)
                {
                    $error = 'Свободных карточек нет: остальные уже взяты задачами '
                        .'(цепочка идёт), либо подождите час, пока истечёт клейм.';
                }
                else
                {
                    $error = 'Ни одна задача перевода не встала в очередь — проверьте nj120.';
                }
            }

            $url = '/mcphub/admin?tab=quality&translate_started='.(int) $batch['count']
                .'&translated='.(int) $batch['job']['limit']
                .'&job='.(int) ($batch['jobs'][0] ?? 0);

            if ($error !== '')
            {
                $url .= '&translate_error='.rawurlencode($error);
            }

            HTTP::redirect($url.'#translate', 303);
        }

        $limit = (int) $this->request->post('limit');
        $limit = $limit > 0 ? min(100, $limit) : Service_Nj676_Translate::BATCH;

        $result = Service_Nj676_Translate::queue($limit);

        $url = '/mcphub/admin?tab=quality&translated='.(int) $result['limit']
            .'&job='.(int) $result['job_id'];

        if ($result['error'] !== '')
        {
            $url .= '&translate_error='.rawurlencode($result['error']);
        }

        HTTP::redirect($url.'#translate', 303);
    }

    /**
     * POST /mcphub/admin/analysis — поставить ИИ-анализ каталога.
     *
     * Модель живёт в воркере (канал `nj676.analysis`): сайт только сохраняет
     * снимок данных и ставит задачу. Ответ на вопрос «что реально работает и
     * чего не хватает» приходит в админку через минуту, а не висит в запросе.
     */
    public function action_analysis()
    {
        $this->_admin_access();

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу.');
        }

        $mode = (string) $this->request->post('mode');
        $mode = isset(Service_Nj676_Analysis::MODES[$mode]) ? $mode : 'both';

        $result = Service_Nj676_Analysis::create($mode, (int) ($this->_current_user->id ?? 0));

        $url = '/mcphub/admin?tab=build&analysis='.(int) $result['id'];

        if ((string) $result['error'] !== '')
        {
            $url .= '&analysis_error='.rawurlencode((string) $result['error']);
        }

        HTTP::redirect($url.'#analysis', 303);
    }

    /**
     * POST /mcphub/admin/translate_reset — снять клеймы и поставить перевод.
     *
     * Зачем кнопка. 24.10.2026 перевод встал: 25 карточек были «в работе» за
     * задачей, которая уже закончилась, свободных не было, и обе кнопки были
     * выключены — совет «подождите час» вместо работы. Сайт теперь отпускает
     * такие клеймы сам, но последнее слово остаётся за человеком: одно
     * нажатие снимает все метки и сразу ставит новую задачу.
     */
    public function action_translate_reset()
    {
        $this->_admin_access();

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу.');
        }

        $released = Service_Nj676_Translate::release_all();

        $mode = (string) $this->request->post('mode') === 'all' ? 'all' : 'batch';

        if ($mode === 'all')
        {
            $batch = Service_Nj676_Translate::queue_all();
            $error = (string) $batch['error'];
            $jobs = (int) $batch['count'];

            if ($error === '' AND $jobs === 0)
            {
                $error = 'Освободили карточки, но ставить нечего: переводить не осталось.';
            }

            $url = '/mcphub/admin?tab=quality&translated='.(int) $batch['job']['limit']
                .'&translate_started='.$jobs
                .'&job='.(int) ($batch['jobs'][0] ?? 0)
                .'&released='.$released;
        }
        else
        {
            $limit = (int) $this->request->post('limit');
            $limit = $limit > 0 ? min(100, $limit) : Service_Nj676_Translate::BATCH;

            $result = Service_Nj676_Translate::queue($limit);
            $error = (string) $result['error'];

            $url = '/mcphub/admin?tab=quality&translated='.(int) $result['limit']
                .'&job='.(int) $result['job_id']
                .'&released='.$released;
        }

        if ($error !== '')
        {
            $url .= '&translate_error='.rawurlencode($error);
        }

        HTTP::redirect($url.'#translate', 303);
    }

    /** POST /mcphub/admin/discover — кнопка «Найти и добавить». */
    public function action_discover()
    {
        $this->_admin_access();

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу.');
        }

        $theme = mb_substr(trim((string) $this->request->post('theme')), 0, 200);
        $requested = (int) $this->request->post('n');

        $run = $this->_queue_discovery($theme, $requested);

        HTTP::redirect('/mcphub/admin?run='.(int) $run['id'], 303);
    }

    /**
     * POST /mcphub/admin/demand — «дособрать по спросу».
     *
     * Одна кнопка: берём самый частый запрос агентов, по которому в каталоге
     * ничего не нашлось, и ставим по нему автосборку. Если в форме передан
     * `theme`, собираем по нему (так работает кнопка в строке спроса);
     * `all=1` — поставить прогоны по нескольким темам сразу (не больше 3,
     * иначе очередь забьётся дорогими задачами).
     */
    public function action_demand()
    {
        $this->_admin_access();

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу.');
        }

        $requested = (int) $this->request->post('n');

        if ((string) $this->request->post('all') === '1')
        {
            $rows = Service_Nj676_Demand::top(30, 20);
            $themes = [];
            foreach ($rows as $row)
            {
                if ((int) ($row['zero_count'] ?? 0) > 0 AND (int) ($row['catalog_matches'] ?? 0) === 0)
                {
                    $candidate = mb_substr(trim((string) ($row['sample'] ?: $row['norm'] ?? '')), 0, 200);
                    if ($candidate !== '' AND ! in_array($candidate, $themes, TRUE))
                    {
                        $themes[] = $candidate;
                    }
                    if (count($themes) >= 3)
                    {
                        break;
                    }
                }
            }

            if ($themes === [])
            {
                HTTP::redirect('/mcphub/admin?tab=demand&demand=empty', 303);
            }

            $last_run = NULL;
            foreach ($themes as $t)
            {
                $last_run = $this->_queue_discovery($t, $requested);
            }

            HTTP::redirect('/mcphub/admin?run='.(int) ($last_run['id'] ?? 0), 303);
        }

        $raw_theme = mb_substr(trim((string) $this->request->post('theme')), 0, 200);
        $theme = $raw_theme !== '' ? $raw_theme : Service_Nj676_Demand::next_theme();

        if ($theme === '')
        {
            // Спроса нет — это не ошибка: каталог отвечает на всё, что просят.
            HTTP::redirect('/mcphub/admin?tab=demand&demand=empty', 303);
        }

        $run = $this->_queue_discovery($theme, $requested);

        HTTP::redirect('/mcphub/admin?run='.(int) $run['id'], 303);
    }

    /**
     * Создать прогон автосборки и поставить его воркеру.
     *
     * Вынесено из action_discover, чтобы кнопка «Найти и добавить», кнопка
     * «Собрать по спросу» и ночной пинок ставили задачи одинаково — с теми же
     * лимитами, приоритетом и честным провалом при мёртвой очереди.
     *
     * @return array строка прогона
     */
    protected function _queue_discovery($theme, $requested = 0, $user_id = NULL)
    {
        $theme = mb_substr(trim((string) $theme), 0, 200);
        $requested = (int) $requested;
        if ($requested <= 0)
        {
            $requested = Service_Nj676_McpHub::DEFAULT_DISCOVER;
        }
        $requested = max(1, min(Service_Nj676_McpHub::MAX_DISCOVER, $requested));

        if ($user_id === NULL)
        {
            $user_id = (int) ($this->_current_user->id ?? 0);
        }

        $run = Service_Nj676_McpHub::create_run($theme, $requested, (int) $user_id);

        if (empty($run['id']))
        {
            Log_Plain::event(Log::ERROR, 'nj676: прогон не создан');
            throw HTTP_Exception::factory(500, 'Не удалось создать прогон автосборки.');
        }

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj676.discover', [
                'run_id' => (int) $run['id'],
                'theme' => $theme,
                'limit' => $requested,
            ], [
                'module' => 'nj676',
                'timeout_sec' => 900,
                'created_by' => (int) $user_id,
                'priority' => 90,
            ]);
            Service_Nj676_McpHub::set_run_job((int) $run['id'], (int) $job_id);
        }
        catch (Throwable $e)
        {
            Service_Nj676_McpHub::finish_run((int) $run['id'], [
                'status' => 'fail',
                'error' => 'Очередь недоступна: '.$e->getMessage(),
            ]);
            Log_Plain::event(Log::ERROR, 'nj676: задача автосборки не поставлена: :e', [':e' => $e->getMessage()]);

            throw HTTP_Exception::factory(500, 'Очередь недоступна: '.$e->getMessage());
        }

        return $run;
    }

    /**
     * POST /mcphub/admin/registry — синхронизация с официальным реестром MCP.
     *
     * Реестр — единственный источник, где серверы описаны машинно
     * (server.json), поэтому это дешёвая операция: без LLM, без разбора README.
     * Идёт через очередь, потому что страниц может быть много.
     */
    public function action_registry()
    {
        $this->_admin_access();

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу.');
        }

        $limit = (int) $this->request->post('limit');
        $limit = $limit > 0 ? min(5000, $limit) : 2500;

        $run = Service_Nj676_McpHub::create_run('реестр MCP', 0, (int) ($this->_current_user->id ?? 0), 'registry');

        if (empty($run['id']))
        {
            throw HTTP_Exception::factory(500, 'Не удалось создать прогон.');
        }

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj676.registry', [
                'run_id' => (int) $run['id'],
                'limit' => $limit,
            ], [
                'module' => 'nj676',
                'timeout_sec' => 1800,
                'created_by' => (int) ($this->_current_user->id ?? 0),
                'priority' => 80,
            ]);
            Service_Nj676_McpHub::set_run_job((int) $run['id'], (int) $job_id);
        }
        catch (Throwable $e)
        {
            Service_Nj676_McpHub::finish_run((int) $run['id'], [
                'status' => 'fail', 'error' => 'Очередь недоступна: '.$e->getMessage(),
            ]);

            throw HTTP_Exception::factory(500, 'Очередь недоступна: '.$e->getMessage());
        }

        HTTP::redirect('/mcphub/admin?run='.(int) $run['id'], 303);
    }

    /**
     * POST /mcphub/admin/maintain — комплексное обслуживание каталога одной кнопкой.
     *
     * Вызывает Service_Nj676_McpHub::maintain() — ту же единую функцию, которую
     * можно вызывать из cron. Выполняет чистку старых журналов, проверку JSON Schema,
     * опрос MCP-эндпоинтов пачками по 60, догон перевода и семантического индекса.
     */
    public function action_maintain()
    {
        $this->_admin_access();

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу.');
        }

        $raw_probe = $this->request->post('probe_limit');
        $probe_limit = ($raw_probe !== NULL AND $raw_probe !== '')
            ? max(0, min(300, (int) $raw_probe))
            : 180;
        $custom = (string) $this->request->post('custom') === '1';

        $res = Service_Nj676_McpHub::maintain([
            'probe_limit' => $probe_limit,
            'user_id' => (int) ($this->_current_user->id ?? 0),
            'with_registry' => $custom ? ((string) $this->request->post('with_registry') === '1') : TRUE,
            'translate_all' => $custom ? ((string) $this->request->post('translate_all') === '1') : TRUE,
            'with_semantic' => $custom ? ((string) $this->request->post('with_semantic') === '1') : TRUE,
            'with_audit' => $custom ? ((string) $this->request->post('with_audit') === '1') : TRUE,
            'with_discover' => (string) $this->request->post('with_discover') === '1',
        ]);

        $url = '/mcphub/admin?tab=build&maintained=1'
            .'&m_registry='.(int) ($res['registry']['run_id'] ?? 0)
            .'&m_schemas='.(int) ($res['schemas']['checked'] ?? 0)
            .'&m_broken='.(int) ($res['schemas']['broken'] ?? 0)
            .'&m_probe='.(int) ($res['probe']['queued'] ?? 0)
            .'&m_batches='.(int) ($res['probe']['batches'] ?? 0)
            .'&m_audit='.(int) ($res['audit']['queued'] ?? 0)
            .'&m_translate='.(int) ($res['translate']['queued'] ?? 0)
            .'&m_tr_jobs='.(int) ($res['translate']['jobs'] ?? 0)
            .'&m_semantic='.(int) ($res['semantic']['queued'] ?? 0)
            .'&m_discover='.(int) ($res['discover']['run_id'] ?? 0);

        HTTP::redirect($url.'#maintain', 303);
    }

    /**
     * POST /mcphub/admin/probe — опрос MCP-эндпоинтов (initialize + tools/list).
     *
     * Берём карточки, которые давно не опрашивали (или ещё ни разу), и ставим
     * одну или несколько задач nj676.probe по 60 серверов: воркер сделает
     * initialize + tools/list и вернёт статус, задержку и живые схемы.
     */
    public function action_probe()
    {
        $this->_admin_access();

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу.');
        }

        $limit = (int) $this->request->post('limit');
        $limit = $limit > 0 ? min(300, $limit) : 60;

        $res = Service_Nj676_McpHub::queue_probe($limit, (int) ($this->_current_user->id ?? 0));

        if ($res['queued'] === 0 AND $res['error'] !== '')
        {
            throw HTTP_Exception::factory(500, 'Очередь недоступна: '.$res['error']);
        }

        if ($res['queued'] === 0)
        {
            HTTP::redirect('/mcphub/admin?tab=cards&probed=empty', 303);
        }

        HTTP::redirect('/mcphub/admin?run='.(int) $res['run_id'], 303);
    }

    /** POST /mcphub/admin/cancel — прервать прогон (снять задачу очереди). */
    public function action_cancel()
    {
        $this->_admin_access();

        if ($this->request->method() !== Request::POST OR ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела — обновите страницу.');
        }

        $run = Service_Nj676_McpHub::run((int) $this->request->post('run_id'));

        if ($run)
        {
            if ( ! empty($run['job_id']) AND class_exists('Service_Nj120_JobQueue'))
            {
                try
                {
                    Service_Nj120_JobQueue::instance()->cancel((int) $run['job_id'], TRUE);
                }
                catch (Throwable $e)
                {
                    Log_Plain::event(Log::WARNING, 'nj676: задача не снята: :e', [':e' => $e->getMessage()]);
                }
            }

            Service_Nj676_McpHub::finish_run((int) $run['id'], [
                'status' => 'cancelled',
                'progress' => 'Остановлено вручную',
            ]);
        }

        HTTP::redirect('/mcphub/admin?run='.(int) ($run['id'] ?? 0), 303);
    }

    /** JSON статуса прогона: контракт partials/live_status. */
    public function action_api_status()
    {
        if ( ! $this->can('nj676.admin'))
        {
            $this->_json(['ok' => FALSE, 'error' => 'auth'], 403);

            return;
        }

        $run = Service_Nj676_McpHub::run((int) $this->request->param('id'));

        if ( ! $run)
        {
            $this->_json(['ok' => FALSE, 'error' => 'not_found'], 404);

            return;
        }

        $status = $run['status'] === 'running' ? 'running' : ($run['status'] === 'fail' ? 'failed' : $run['status']);
        if ($status === 'done')
        {
            $status = 'done';
        }

        $elapsed = max(0, time() - (int) strtotime((string) $run['started_at']));
        $stage = trim((string) $run['progress']);

        if ($status === 'done')
        {
            $stage = 'Готово: добавлено '.$run['added'].', обновлено '.$run['updated']
                .($run['probed'] > 0 ? ', проверено '.$run['probed'] : '');
        }
        elseif ($status === 'failed')
        {
            $stage = $run['error'] !== '' ? $run['error'] : 'Сборка завершилась ошибкой';
        }
        elseif ($status === 'cancelled')
        {
            $stage = 'Остановлено';
        }
        elseif ($stage === '')
        {
            $stage = 'Задача поставлена — ждём свободного воркера…';
        }

        // Пульс задачи: если воркер молчит, честно показываем «нет пульса».
        $silent = NULL;
        if ($status === 'running' AND ! empty($run['job_id']) AND class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                $job = Service_Nj120_JobQueue::instance()->get((int) $run['job_id']);
                if ($job)
                {
                    $last = strtotime((string) ($job['heartbeat_at'] ?: $job['claimed_at'] ?: $job['created_at']));
                    $silent = $last ? max(0, time() - $last) : NULL;
                }
            }
            catch (Throwable $e)
            {
                $silent = NULL;
            }
        }

        $this->_json([
            'ok' => TRUE,
            'status' => $status,
            'stage' => mb_substr($stage, 0, 300),
            'elapsed_sec' => $elapsed,
            'silent_sec' => $silent,
            'found' => (int) $run['found'],
            'added' => (int) $run['added'],
            'updated' => (int) $run['updated'],
            'error' => (string) $run['error'],
            'note' => $status === 'running'
                ? 'Сборка идёт в фоне: поиск по GitHub, разбор README и запись карточек. Страница обновится сама.'
                : '',
        ]);
    }

    /**
     * Пинок по расписанию. Автосборка включается только явно:
     * NJ676_AUTO_DISCOVER=1 (иначе ничего не тратится).
     */
    public function action_api_tick()
    {
        if ( ! $this->_worker_auth())
        {
            $this->_json(['ok' => FALSE, 'error' => 'auth'], 401);

            return;
        }

        // Телеметрия вызовов и журнал спроса стареют по расписанию:
        // тик — единственное место, которое бывает и без действий человека.
        Service_Nj676_Telemetry::prune();
        Service_Nj676_Demand::prune();

        // Смысловой индекс каталога: раз в сутки догоняем изменившиеся карточки.
        // Индексация идёт через очередь nj120 (правило платформы), а сам вызов
        // здесь только ставит задачи — поэтому тик не растягивается на минуты.
        // Без EMBEDDING_API_URL это no-op: поиск работает по словам.
        try
        {
            $cache = Cache::instance('file');

            if (Service_Nj676_Semantic::enabled() AND ! $cache->get('nj676_semantic_indexed_at', 0))
            {
                Service_Nj676_Semantic::index(500);
                $cache->set('nj676_semantic_indexed_at', time(), 86400);
            }
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: индекс каталога по тику не поставлен: :e',
                [':e' => $e->getMessage()]);
        }

        // Перевод карточек на русский: раз в сутки небольшая порция, чтобы
        // каталог не оставался англоязычным. Отключить — NJ676_AUTO_TRANSLATE=0.
        // Сама работа идёт в воркере (модель), здесь только задача в nj120.
        try
        {
            $cache = Cache::instance('file');

            if ((string) getenv('NJ676_AUTO_TRANSLATE') !== '0'
                AND ! $cache->get('nj676_translate_queued_at', 0))
            {
                $pending = Service_Nj676_Translate::status()['pending'];

                if ($pending > 0)
                {
                    Service_Nj676_Translate::queue(min(40, $pending));
                }

                $cache->set('nj676_translate_queued_at', time(), 86400);
            }
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: перевод по тику не поставлен: :e',
                [':e' => $e->getMessage()]);
        }

        // Сторож перевода: если карточки ждут, а задач в очереди нет — ставим
        // порцию сами. Штатного пути «прогон кончился → продолжение» хватает
        // не всегда: упавшая задача не докладывает итог, и каталог замирал до
        // следующего нажатия или суточного тика (24.10.2026). Не чаще раза в
        // полчаса, чтобы не плодить задачи поверх живых.
        try
        {
            $cache = Cache::instance('file');

            if ((string) getenv('NJ676_AUTO_TRANSLATE') !== '0'
                AND ! $cache->get('nj676_translate_watchdog_at', 0))
            {
                $status = Service_Nj676_Translate::status();

                if ((int) ($status['pending'] ?? 0) > 0
                    AND (int) ($status['free'] ?? 0) > 0
                    AND (int) ($status['queue']['active'] ?? 0) === 0)
                {
                    Service_Nj676_Translate::queue(min(40, (int) $status['free']));
                    Log_Plain::event(Log::INFO, 'nj676: сторож перевода поставил порцию: свободных :n',
                        [':n' => (int) $status['free']]);
                }

                $cache->set('nj676_translate_watchdog_at', time(), 1800);
            }
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: сторож перевода не сработал: :e',
                [':e' => $e->getMessage()]);
        }

        // Приёмка схем: раз в сутки перепроверяем сохранённые tools всех
        // карточек. Один инструмент с невалидной схемой валит tools/list у
        // строгих клиентов, и узнать об этом от агента — поздно.
        try
        {
            $cache = Cache::instance('file');
            if ( ! $cache->get('nj676_schema_checked_at', 0))
            {
                Service_Nj676_McpHub::revalidate_schemas(2000);
                $cache->set('nj676_schema_checked_at', time(), 86400);
            }
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: проверка схем по тику не прошла: :e',
                [':e' => $e->getMessage()]);
        }

        // Синхронизация с официальным реестром MCP: раз в сутки забираем свежие
        // server.json без расхода LLM (NJ676_AUTO_REGISTRY=0 выключает).
        try
        {
            $cache = Cache::instance('file');
            if ((string) getenv('NJ676_AUTO_REGISTRY') !== '0'
                AND ! $cache->get('nj676_registry_synced_at', 0))
            {
                $reg_running = Service_Nj676_McpHub::latest_run_of_kind('registry');
                $reg_busy = ($reg_running AND $reg_running['status'] === 'running'
                    AND strtotime((string) $reg_running['started_at']) > time() - 3600);

                if ( ! $reg_busy)
                {
                    $reg_run = Service_Nj676_McpHub::create_run('реестр MCP', 0, 0, 'registry');
                    if ( ! empty($reg_run['id']))
                    {
                        $reg_job = (int) Service_Nj120_JobQueue::push('nj676.registry', [
                            'run_id' => (int) $reg_run['id'],
                            'limit' => 1000,
                        ], ['module' => 'nj676', 'timeout_sec' => 1800, 'priority' => 95]);
                        Service_Nj676_McpHub::set_run_job((int) $reg_run['id'], $reg_job);
                    }
                    $cache->set('nj676_registry_synced_at', time(), 86400);
                }
            }
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: синхронизация реестра по тику не поставлена: :e',
                [':e' => $e->getMessage()]);
        }

        // Проверка живости нужна даже без автосборки: это дешёвый HTTP и
        // именно она даёт блоку «отвечают прямо сейчас» право на существование.
        $probe = ['skipped' => 'проверено недавно'];
        if ((string) getenv('NJ676_AUTO_PROBE') !== '0')
        {
            $probe = $this->_queue_probe(60);
        }

        // Фоновое исследование открытых MCP-серверов воркером-агентом (поиск «алмазов»):
        // потихоньку обходит проверенные открытые серверы пачками по 4 штуки за тик.
        $audit = ['skipped' => 'исследование не требовалось'];
        if ((string) getenv('NJ676_AUTO_AUDIT') !== '0')
        {
            try
            {
                $audit_q = Service_Nj676_Audit::queue(4, '', 0);
                if ((int) ($audit_q['queued'] ?? 0) > 0)
                {
                    $audit = [
                        'queued' => (int) $audit_q['queued'],
                        'run_id' => (int) ($audit_q['run_id'] ?? 0),
                        'job_id' => (int) ($audit_q['job_id'] ?? 0),
                    ];
                }
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::DEBUG, 'nj676: авто-исследование по тику не поставлено: :e',
                    [':e' => $e->getMessage()]);
            }
        }

        if ((string) getenv('NJ676_AUTO_DISCOVER') !== '1')
        {
            $this->_json(['ok' => TRUE, 'probe' => $probe, 'audit' => $audit,
                'skipped' => 'auto-discovery disabled (NJ676_AUTO_DISCOVER=1 включает)']);

            return;
        }

        // «Идёт сборка» — только прогон автосборки. Проверка живости и импорт
        // реестра тоже пишут прогоны; раньше именно поставленная в этом же
        // пинке проверка навсегда блокировала автосборку.
        $running = Service_Nj676_McpHub::latest_run_of_kind('discover');
        if ($running AND $running['status'] === 'running'
            AND strtotime((string) $running['started_at']) > time() - 3600)
        {
            $this->_json(['ok' => TRUE, 'skipped' => 'предыдущая сборка ещё идёт']);

            return;
        }

        // Журнал спроса не растёт бесконечно: старые запросы в отчётах не нужны
        // (NJ676_DEMAND_KEEP_DAYS, по умолчанию 180 дней).
        Service_Nj676_Demand::prune();

        $n = (int) getenv('NJ676_AUTO_COUNT');
        $n = $n > 0 ? min(Service_Nj676_McpHub::MAX_DISCOVER, $n) : 6;

        // Ночная автосборка идёт по спросу: самый частый запрос агентов, по
        // которому в каталоге ничего не нашлось. Нет спроса — собираем общее
        // «самое заметное», как раньше. Отключить: NJ676_AUTO_DEMAND=0.
        $theme = (string) getenv('NJ676_AUTO_DEMAND') === '0' ? '' : Service_Nj676_Demand::next_theme();

        $run = Service_Nj676_McpHub::create_run($theme, $n, 0);

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj676.discover', [
                'run_id' => (int) $run['id'],
                'theme' => $theme,
                'limit' => $n,
            ], ['module' => 'nj676', 'timeout_sec' => 900, 'priority' => 120]);
            Service_Nj676_McpHub::set_run_job((int) $run['id'], (int) $job_id);
        }
        catch (Throwable $e)
        {
            Service_Nj676_McpHub::finish_run((int) $run['id'], ['status' => 'fail', 'error' => $e->getMessage()]);
            $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], 500);

            return;
        }

        $this->_json(['ok' => TRUE, 'run_id' => (int) $run['id'], 'job_id' => (int) $job_id,
            'theme' => $theme, 'probe' => $probe]);
    }

    /**
     * Поставить проверку живости, если её не было STALE секунд.
     *
     * @return array отчёт для ответа tick
     */
    protected function _queue_probe($limit = 24)
    {
        $stale = (int) getenv('NJ676_PROBE_EVERY_SEC');
        $stale = $stale > 0 ? $stale : 21600;

        $last = Service_Nj676_McpHub::latest_run_by_theme('проверка удалённых серверов');

        if ($last AND strtotime((string) $last['started_at']) > time() - $stale)
        {
            return ['skipped' => 'проверка была меньше '.round($stale / 3600).' ч назад'];
        }

        $targets = Service_Nj676_McpHub::probe_targets($limit);

        if ($targets === [])
        {
            return ['skipped' => 'нет удалённых серверов для проверки'];
        }

        $run = Service_Nj676_McpHub::create_run('проверка удалённых серверов', 0, 0, 'probe');

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj676.probe', [
                'run_id' => (int) ($run['id'] ?? 0),
                'targets' => $targets,
            ], ['module' => 'nj676', 'timeout_sec' => 900, 'priority' => 110]);
        }
        catch (Throwable $e)
        {
            if ( ! empty($run['id']))
            {
                Service_Nj676_McpHub::finish_run((int) $run['id'], ['status' => 'fail', 'error' => $e->getMessage()]);
            }

            return ['error' => $e->getMessage()];
        }

        if ( ! empty($run['id']))
        {
            Service_Nj676_McpHub::set_run_job((int) $run['id'], (int) $job_id);
        }

        return ['queued' => count($targets), 'run_id' => (int) ($run['id'] ?? 0)];
    }

    // ---------------------------------------------------------------------
    // Приёмка от воркера
    // ---------------------------------------------------------------------

    /**
     * GET /mcphub/api/translate-candidates?limit=20 — карточки без перевода.
     *
     * Читает воркер (X-Worker-Token), поэтому отдаём только то, что нужно
     * модели: имя, описание, категории и имена инструментов. Ни ключей, ни
     * адресов вызова, ни внутренних id.
     */
    public function action_api_translate_candidates()
    {
        if ( ! $this->_worker_auth())
        {
            $this->_json(['ok' => FALSE, 'error' => 'auth'], 401);

            return;
        }

        $limit = (int) $this->_query_value('limit');
        $limit = $limit > 0 ? min(100, $limit) : Service_Nj676_Translate::BATCH;

        // claim=0 — «только посмотреть, сколько осталось»: карточки НЕ берём в
        // работу. Иначе контрольный запрос остатка в конце прогона забирал
        // карточку в клейм, и она висела «в работе» до истечения срока.
        $claim = $this->_query_value('claim') !== '0';
        // Номер задачи — чтобы клейм принадлежал ей и снимался, когда задача
        // закончилась (иначе карточки умершей задачи держат очередь час).
        $job_id = (int) $this->_query_value('job');

        $items = Service_Nj676_Translate::candidates($limit, $job_id, $claim);
        // Сколько всего ждёт перевода: воркер докладывает это в итоге прогона,
        // и админка видит «осталось N», а не только «переведено 5».
        $status = Service_Nj676_Translate::status();

        $this->_json([
            'ok' => TRUE,
            'count' => count($items),
            'pending' => (int) ($status['pending'] ?? 0),
            'model' => Service_Nj676_Translate::model(),
            'items' => $items,
        ]);
    }

    /**
     * GET /mcphub/api/analysis-get?id=N — срез каталога для модели.
     *
     * Воркер не имеет доступа к базе и не должен его получать: он работает
     * через API. Снимок уже сохранён при постановке анализа — здесь только
     * отдаём его и отмечаем начало работы.
     */
    public function action_api_analysis_get()
    {
        if ( ! $this->_worker_auth())
        {
            $this->_json(['ok' => FALSE, 'error' => 'auth'], 401);

            return;
        }

        $id = (int) $this->_query_value('id');
        $row = Service_Nj676_Analysis::start($id);

        if ( ! $row)
        {
            $this->_json(['ok' => FALSE, 'error' => 'analysis_not_found'], 404);

            return;
        }

        $this->_json([
            'ok' => TRUE,
            'id' => (int) $row['id'],
            'mode' => (string) $row['mode'],
            'snapshot' => (array) $row['snapshot'],
        ]);
    }

    /**
     * POST /mcphub/api/analysis-finish — отчёт модели.
     *
     * Тело: {analysis_id, status, report|error, ms, model}. Отчёт нормализуется
     * в сервисе: в админку не попадают произвольные ключи и простыни текста.
     */
    public function action_api_analysis_finish()
    {
        if ( ! $this->_worker_auth())
        {
            $this->_json(['ok' => FALSE, 'error' => 'auth'], 401);

            return;
        }

        if ($this->request->method() !== Request::POST)
        {
            $this->_json(['ok' => FALSE, 'error' => 'Только POST'], 405);

            return;
        }

        $data = json_decode((string) $this->request->body(), TRUE);

        if ( ! is_array($data))
        {
            $data = $_POST;
        }

        $id = (int) ($data['analysis_id'] ?? $data['id'] ?? 0);

        if ($id <= 0)
        {
            $this->_json(['ok' => FALSE, 'error' => 'Нужен analysis_id'], 400);

            return;
        }

        $saved = Service_Nj676_Analysis::finish($id, [
            'status' => (string) ($data['status'] ?? 'done'),
            'report' => isset($data['report']) && is_array($data['report']) ? $data['report'] : NULL,
            'error' => (string) ($data['error'] ?? ''),
            'ms' => (int) ($data['ms'] ?? 0),
            'model' => (string) ($data['model'] ?? ''),
        ]);

        $this->_json(['ok' => $saved], $saved ? 200 : 500);
    }

    public function action_api_worker()
    {
        if ( ! $this->_worker_auth())
        {
            $this->_json(['ok' => FALSE, 'error' => 'auth'], 401);

            return;
        }

        if ($this->request->method() !== Request::POST)
        {
            $this->_json(['ok' => FALSE, 'error' => 'Только POST'], 405);

            return;
        }

        // Данные приходят JSON-телом (воркер) или формой (ручная отладка).
        $raw = (string) $this->request->body();
        $data = json_decode($raw, TRUE);

        if ( ! is_array($data))
        {
            $data = $_POST;
        }

        $action = (string) $this->request->param('what');

        switch ($action)
        {
            case 'discover_state':
                $repos = $data['repos'] ?? [];
                $this->_json(['ok' => TRUE] + Service_Nj676_McpHub::known_slugs(is_array($repos) ? $repos : []));

                return;

            case 'discover_item':
                $run_id = (int) ($data['run_id'] ?? 0);

                if ($run_id > 0 AND Service_Nj676_McpHub::run($run_id) === NULL)
                {
                    Log_Plain::event(Log::WARNING, 'nj676: карточка от неизвестного прогона :r', [':r' => $run_id]);
                    $this->_json(['ok' => FALSE, 'error' => 'Неизвестный прогон: '.$run_id], 404);

                    return;
                }

                $item = isset($data['item']) && is_array($data['item']) ? $data['item'] : $data;

                $result = Service_Nj676_McpHub::apply_discovery_item($item, $run_id);

                if ($result['status'] === 'skipped')
                {
                    Log_Plain::event(Log::WARNING, 'nj676: карточка пропущена: :m',
                        [':m' => $result['message']]);
                }

                $this->_json(['ok' => $result['status'] !== 'skipped'] + $result);

                return;

            case 'translate_finish':
                // Воркер присылает итог прогона: сколько перевёл, сколько
                // пропустил и какие ошибки видел. Админка показывает это в
                // блоке перевода — иначе «5 из 1037» ни о чём не говорит.
                $stats = isset($data['stats']) && is_array($data['stats']) ? $data['stats'] : $data;

                Service_Nj676_Translate::remember_result($stats);

                // Пропущенные и неприступленные карточки снова свободны: их
                // увидит следующая задача сразу, а не через час по сроку клейма.
                if ( ! empty($data['untranslated']) && is_array($data['untranslated']))
                {
                    Service_Nj676_Translate::release($data['untranslated']);
                }

                // Прогон закончился, карточки остались — ставим продолжение
                // сами. Иначе каталог ждёт следующего нажатия или суточного
                // тика, а владелец видит «осталось 25» и пустую очередь.
                $queued = 0;

                if ((int) ($stats['translated'] ?? 0) > 0
                    AND (int) ($stats['remaining'] ?? 0) > 0)
                {
                    $queued = Service_Nj676_Translate::queue_continuation();
                }

                $this->_json(['ok' => TRUE, 'queued' => $queued]);

                return;

            case 'translate_item':
                // Воркер присылает готовые переводы пачкой. Валидация и
                // защита ручного перевода — в Service_Nj676_Translate.
                $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
                $stats = Service_Nj676_Translate::apply($items);

                // Перевод попал в карточку — пересчитываем её вектор. Текст
                // вектора включает name_ru/description_ru, поэтому карточка,
                // проиндексированная ДО перевода, на русский запрос отвечает
                // английским текстом («иконки» → не `dashboard-icons`).
                // Без этого шага русский поиск остаётся неровным ещё сутки —
                // до планового догона индекса.
                if ( ! empty($stats['slugs']))
                {
                    $reindex = Service_Nj676_Semantic::index_slugs($stats['slugs']);
                    $stats['reindexed'] = (int) ($reindex['queued'] ?? 0);
                }

                $this->_json(['ok' => TRUE] + $stats);

                return;

            case 'registry_batch':
                $run_id = (int) ($data['run_id'] ?? 0);
                $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

                if ($run_id > 0)
                {
                    Service_Nj676_McpHub::set_progress($run_id, (string) ($data['progress'] ?? 'Импорт реестра MCP'));
                }

                $stats = Service_Nj676_Registry::apply_batch($items, $run_id);
                $this->_json(['ok' => TRUE] + $stats);

                return;

            case 'probe':
                $slug = mb_substr(trim((string) ($data['slug'] ?? '')), 0, 100);

                if ($slug === '')
                {
                    $this->_json(['ok' => FALSE, 'error' => 'Нужен slug'], 400);

                    return;
                }

                $ok = ! empty($data['ok']);
                $latency = (int) ($data['latency_ms'] ?? 0);
                $error = mb_substr(trim((string) ($data['error'] ?? '')), 0, 300);
                $tools = isset($data['tools']) && is_array($data['tools']) ? $data['tools'] : [];

                $result = Service_Nj676_McpHub::apply_probe($slug, $ok, $latency, $error, $tools);

                // Воркер-проба — самый частый источник живого сигнала: если
                // сервер ответил «нужен ключ», витрина должна узнать об этом
                // здесь, а не после неудачного вызова агента.
                if ($ok)
                {
                    Service_Nj676_McpHub::clear_auth_required($slug);
                }
                elseif (Service_Nj676_Gateway::upstream_code($error) === 'AUTH_REQUIRED')
                {
                    Service_Nj676_McpHub::mark_auth_required($slug);
                }

                $this->_json(['ok' => $result['status'] !== 'skipped'] + $result);

                return;

            case 'registry_finish':
                $run_id = (int) ($data['run_id'] ?? 0);

                if ($run_id > 0)
                {
                    Service_Nj676_McpHub::finish_run($run_id, $data);
                }

                $this->_json(['ok' => TRUE, 'registry' => Service_Nj676_Registry::stats()]);

                return;

            case 'discover_progress':
                $run_id = (int) ($data['run_id'] ?? 0);
                Service_Nj676_McpHub::set_progress($run_id, (string) ($data['phase'] ?? ''));
                $this->_json(['ok' => TRUE]);

                return;

            case 'discover_finish':
                $run_id = (int) ($data['run_id'] ?? 0);

                if (Service_Nj676_McpHub::run($run_id) === NULL)
                {
                    Log_Plain::event(Log::WARNING, 'nj676: финал неизвестного прогона :r', [':r' => $run_id]);
                    $this->_json(['ok' => FALSE, 'error' => 'Неизвестный прогон: '.$run_id], 404);

                    return;
                }

                $run = Service_Nj676_McpHub::finish_run($run_id, $data);
                $continued = 0;

                if (($run['kind'] ?? '') === 'probe' AND (int) ($data['found'] ?? 0) > 0)
                {
                    $continued = Service_Nj676_McpHub::queue_probe_continuation();
                }

                $this->_json(['ok' => TRUE, 'run' => $run, 'continued' => $continued]);

                return;

            case 'audit_item':
                $res = Service_Nj676_Audit::apply_item(is_array($data) ? $data : []);
                $this->_json($res, ! empty($res['ok']) ? 200 : 400);

                return;
        }

        $this->_json(['ok' => FALSE, 'error' => 'Неизвестное действие'], 404);
    }

    // ---------------------------------------------------------------------
    // Ручная модерация (старая форма)
    // ---------------------------------------------------------------------

    public function action_save()
    {
        $this->_admin_access();

        $csrf = $this->request->post('csrf');
        if ($this->request->method() !== HTTP_Request::POST
            OR ! is_string($csrf) OR ! Security::check($csrf))
        {
            throw HTTP_Exception::factory(403, 'Форма устарела или запрос не разрешён.');
        }

        $input = [
            'id' => $this->request->post('id'),
            'slug' => $this->request->post('slug'), 'name' => $this->request->post('name'),
            'description' => $this->request->post('description'),
            'name_ru' => $this->request->post('name_ru'),
            'description_ru' => $this->request->post('description_ru'),
            'repo_url' => $this->request->post('repo_url'),
            'homepage_url' => $this->request->post('homepage_url'),
            'remote_url' => $this->request->post('remote_url'),
            'source' => $this->request->post('source'),
            'transport' => $this->request->post('transport'), 'auth_mode' => $this->request->post('auth_mode'),
            'health_status' => $this->request->post('health_status'), 'categories' => $this->request->post('categories'),
            'package_manager' => $this->request->post('package_manager'), 'package_name' => $this->request->post('package_name'),
            'package_entrypoint' => $this->request->post('package_entrypoint'),
            'env_keys' => $this->request->post('env_keys'), 'tools_json' => $this->request->post('tools_json'),
            'featured' => $this->request->post('featured'),
            'is_published' => $this->request->post('is_published'),
        ];

        [$error, $saved] = Service_Nj676_McpHub::save($input);

        if ($error !== '')
        {
            $data = $this->_admin_data();
            $data['tab'] = 'cards';
            $data['servers'] = Service_Nj676_McpHub::all_for_admin();
            $data['form'] = $input;
            $data['edit'] = NULL;
            $data['error'] = $error;
            $data['saved'] = FALSE;
            $data['transports'] = Service_Nj676_McpHub::TRANSPORTS;
            $data['auth_modes'] = Service_Nj676_McpHub::AUTH;
            $data['health_modes'] = Service_Nj676_McpHub::HEALTH;
            $data['categories'] = Service_Nj676_McpHub::CATEGORIES;
            $this->template->content = View::factory('676/admin', $data);

            return;
        }

        HTTP::redirect('/mcphub/admin?tab=cards&slug='.rawurlencode((string) $saved).'&saved=1', 303);
    }

    // ---------------------------------------------------------------------
    // Внутреннее
    // ---------------------------------------------------------------------

    protected function _admin_access()
    {
        if ( ! $this->can('nj676.admin'))
        {
            Log_Plain::event(Log::WARNING, 'nj676: отказ в доступе к админке (нужно nj676.admin)');
            throw HTTP_Exception::factory(403, 'Управление MCP Hub доступно только администратору.');
        }

        return TRUE;
    }

    /** Токен воркера: X-Worker-Token = WORKER_API_TOKEN сайта. */
    protected function _worker_auth()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));
        if ($token === '')
        {
            return FALSE;
        }

        $given = (string) $this->request->headers('x-worker-token');

        return $given !== '' && hash_equals($token, $given);
    }

    /**
     * Ключ агента: персональный токен из /mcp, либо общий MCP_TOKEN.
     *
     * Anonymous = TRUE разрешает анонимный доступ к шлюзу (каталог и серверы
     * без ключей). Это осознанный компромисс: агенту не нужно регистрироваться,
     * чтобы погуглить каталог, а инструменты платформы всё равно требуют ключ.
     *
     * @return array [bool, array ctx, string error]
     */
    /**
     * Отпечаток ответа `tools/list` — ровно того, что видит агент.
     *
     * Раньше здесь был отпечаток каталога (число карточек + max(updated_at)).
     * Он менялся на каждом прогоне обновления каталога, поэтому SSE присылал
     * `list_changed` почти на каждое подключение — хотя список корневых
     * инструментов шлюза от карточек не зависит (замечание 24.10.2026).
     * Теперь отпечаток отражает сам список: нотификация придёт только после
     * выкладки новой версии шлюза, когда состав инструментов правда изменился.
     */
    protected function _catalog_fingerprint()
    {
        try
        {
            return md5(json_encode(Service_Nj676_Gateway::tool_list(), JSON_UNESCAPED_UNICODE));
        }
        catch (Throwable $e)
        {
            return '';
        }
    }

    protected function _authorize_agent($anonymous = FALSE, $budget = 'read')
    {
        $given = (string) $this->request->headers('authorization');
        $plain = '';

        if (preg_match('/^Bearer\s+(.+)$/i', $given, $m))
        {
            $plain = trim($m[1]);
        }

        if ($plain === '')
        {
            if ($anonymous AND (string) getenv('NJ676_ANON') !== '0')
            {
                // $budget === NULL — лимит проверит вызывающий код: он знает,
                // чтение это или вызов инструмента (см. action_mcp).
                if ($budget !== NULL)
                {
                    $limit = $this->_anon_limit($budget);

                    if ( ! $limit[0])
                    {
                        return [FALSE, [], $limit[1]];
                    }
                }

                $ip = $this->_client_ip();

                return [TRUE, [
                    'is_admin' => FALSE, 'user_id' => NULL,
                    'token_hash' => 'anon:'.substr(hash('sha256', $ip), 0, 32),
                    'anonymous' => TRUE,
                ], ''];
            }

            return [FALSE, [], 'Нужен заголовок Authorization: Bearer <ключ>. Ключ выпускается на странице /mcp '
                .'(без ключа доступен только каталог).'];
        }

        $ctx = ['is_admin' => FALSE, 'user_id' => NULL, 'token_hash' => NULL, 'anonymous' => FALSE];

        if (class_exists('Service_Nj160_Tokens'))
        {
            [$ok, $user_id] = Service_Nj160_Tokens::authenticate($plain);

            if ($ok)
            {
                $ctx['user_id'] = (int) $user_id;
                $ctx['is_admin'] = Service_Nj160_Tokens::is_admin($user_id);
                $ctx['token_hash'] = hash('sha256', $plain);

                if (method_exists('Service_Nj160_Tokens', 'touch'))
                {
                    Service_Nj160_Tokens::touch($ctx['token_hash']);
                }

                return [TRUE, $ctx, ''];
            }
        }

        $token = trim((string) getenv('MCP_TOKEN'));

        if ($token === '' AND Kohana::$environment === Kohana::DEVELOPMENT)
        {
            $ctx['is_admin'] = TRUE;

            return [TRUE, $ctx, ''];
        }

        if ($token !== '' AND hash_equals($token, $plain))
        {
            // Общий ключ — уровень администратора, но профиль за ним не
            // сохраняется: у него нет персонального владельца.
            $ctx['is_admin'] = TRUE;

            return [TRUE, $ctx, ''];
        }

        Log_Plain::event(Log::WARNING, 'nj676: недействительный ключ агента при обращении к шлюзу');

        return [FALSE, [], 'Недействительный ключ. Выпустите персональный ключ на странице /mcp.'];
    }

    /**
     * Ограничение анонимных запросов к шлюзу: 120 в час с одного IP.
     * Кэш файловый, поэтому сбой кэша не должен ломать шлюз — при ошибке
     * просто пропускаем (доступ важнее счётчика).
     *
     * @return array [bool разрешено, string причина отказа]
     */
    /**
     * Бюджеты анонимного доступа: чтение и вызовы — раздельно.
     *
     * Реальный тест 24.10.2026: агент упёрся в общий лимит 120/час за один
     * прогон и получил 403 без объяснения. Теперь чтения дешевле вызовов,
     * есть минутный burst-запас, код RATE_LIMITED и Retry-After.
     *
     * @return array{0:bool,1:string,2:int} [разрешено, сообщение, через сколько секунд повторить]
     */
    protected function _anon_limit($kind = 'read')
    {
        $is_call = ((string) $kind === 'call');
        $what = $is_call ? 'call' : 'read';

        [$hour_limit, $burst] = $this->_anon_budget($what);

        try
        {
            $cache = Cache::instance('file');
            $who = substr(hash('sha256', $this->_client_ip()), 0, 24);

            $minute_key = 'nj676_anon_'.$what.'_m_'.$who.'_'.date('YmdHi');
            $hour_key = 'nj676_anon_'.$what.'_h_'.$who.'_'.date('YmdH');

            $minute = (int) $cache->get($minute_key, 0) + 1;
            $cache->set($minute_key, $minute, 120);

            $hour = (int) $cache->get($hour_key, 0) + 1;
            $cache->set($hour_key, $hour, 3900);

            $this->_budget_headers($what, $hour_limit, $burst, $hour, $minute);

            $what_ru = $is_call ? 'вызовов' : 'запросов чтения';

            if ($minute > $burst)
            {
                $retry = max(1, 60 - (int) date('s'));

                return [FALSE, 'Слишком часто: не больше '.$burst.' анонимных '.$what_ru
                    .' в минуту. Повторите через '.$retry.' с или выпустите ключ на странице /mcp.', $retry];
            }

            if ($hour > $hour_limit)
            {
                $retry = max(60, 3600 - ((int) date('i') * 60 + (int) date('s')));

                return [FALSE, 'Исчерпан часовой бюджет анонимных '.$what_ru.' ('.$hour_limit.' в час). '
                    .'Повторите через '.$retry.' с или выпустите ключ на странице /mcp — он без этого ограничения.', $retry];
            }
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: лимит анонимных не посчитан: :e', [':e' => $e->getMessage()]);
        }

        return [TRUE, '', 0];
    }

    /**
     * Бюджет одного вида запросов: [часовой лимит, минутный burst].
     *
     * Вынесено отдельно, чтобы заголовки могли показать ОБА бюджета сразу:
     * раньше агент видел только read-лимит 600 и узнавал про 12 вызовов в
     * минуту лишь по ошибке RATE_LIMITED (замечание 24.10.2026).
     */
    protected function _anon_budget($what)
    {
        // Числа берём из того же места, откуда их объявляет hub_status: иначе
        // заголовок и ответ шлюза могут разойтись после смены переменных.
        $budgets = Service_Nj676_Gateway::budgets();
        $budget = $budgets[(string) $what === 'call' ? 'call' : 'read'];

        return [(int) $budget['hour'], (int) $budget['minute']];
    }

    /**
     * Расход неизрасходованного бюджета другого вида — только чтение счётчика.
     * Нужно для честного «сколько осталось» в заголовках.
     */
    protected function _anon_left($what, $limit, $window = 'hour')
    {
        try
        {
            $cache = Cache::instance('file');
            $who = substr(hash('sha256', $this->_client_ip()), 0, 24);
            $stamp = $window === 'minute' ? date('YmdHi') : date('YmdH');
            $used = (int) $cache->get('nj676_anon_'.$what.'_'.($window === 'minute' ? 'm' : 'h').'_'
                .$who.'_'.$stamp, 0);

            return max(0, $limit - $used);
        }
        catch (Throwable $e)
        {
            return $limit;
        }
    }

    /**
     * Заголовки бюджета: оба вида запросов, час и минута.
     *
     * `X-RateLimit-Limit`/`Remaining`/`Budget` оставлены как были — по ним уже
     * настроены клиенты; новые заголовки уточняют их и раскрывают минутный
     * burst, о котором раньше нельзя было узнать заранее.
     */
    protected function _budget_headers($what, $hour_limit, $burst, $hour, $minute)
    {
        $other = ($what === 'call') ? 'read' : 'call';
        [$other_hour, $other_burst] = $this->_anon_budget($other);

        $this->response->headers('X-RateLimit-Budget', $what);
        $this->response->headers('X-RateLimit-Limit', (string) $hour_limit);
        $this->response->headers('X-RateLimit-Remaining', (string) max(0, $hour_limit - $hour));
        $this->response->headers('X-RateLimit-Limit-Hour', (string) $hour_limit);
        $this->response->headers('X-RateLimit-Remaining-Hour', (string) max(0, $hour_limit - $hour));
        $this->response->headers('X-RateLimit-Limit-Minute', (string) $burst);
        $this->response->headers('X-RateLimit-Remaining-Minute', (string) max(0, $burst - $minute));

        // Оба бюджета целиком: агент видит, что «12 вызовов в минуту» — не
        // сюрприз, а объявленное правило.
        $this->response->headers('X-RateLimit-Call-Limit-Hour', (string) ($what === 'call' ? $hour_limit : $other_hour));
        $this->response->headers('X-RateLimit-Call-Limit-Minute', (string) ($what === 'call' ? $burst : $other_burst));
        $this->response->headers('X-RateLimit-Call-Remaining-Hour', (string) ($what === 'call'
            ? max(0, $hour_limit - $hour) : $this->_anon_left('call', $other_hour)));
        $this->response->headers('X-RateLimit-Call-Remaining-Minute', (string) ($what === 'call'
            ? max(0, $burst - $minute) : $this->_anon_left('call', $other_burst, 'minute')));
        $this->response->headers('X-RateLimit-Read-Limit-Hour', (string) ($what === 'read' ? $hour_limit : $other_hour));
        $this->response->headers('X-RateLimit-Read-Limit-Minute', (string) ($what === 'read' ? $burst : $other_burst));
        $this->response->headers('X-RateLimit-Read-Remaining-Hour', (string) ($what === 'read'
            ? max(0, $hour_limit - $hour) : $this->_anon_left('read', $other_hour)));
        $this->response->headers('X-RateLimit-Read-Remaining-Minute', (string) ($what === 'read'
            ? max(0, $burst - $minute) : $this->_anon_left('read', $other_burst, 'minute')));
    }

    /** Что расходует бюджет: tools/call с hub_call_tool — вызов, остальное — чтение. */
    protected function _request_kind(array $body)
    {
        $calls = isset($body[0]) && is_array($body[0]) ? $body : [$body];

        foreach ($calls as $call)
        {
            if ( ! is_array($call) OR (string) ($call['method'] ?? '') !== 'tools/call')
            {
                continue;
            }

            $name = (string) ($call['params']['name'] ?? '');
            $arguments = is_array($call['params']['arguments'] ?? NULL) ? $call['params']['arguments'] : [];

            if ($name === 'hub_call_tool' AND empty($arguments['dry_run']))
            {
                return 'call';
            }
        }

        return 'read';
    }

    /**
     * IP клиента: общий помощник платформы (учитывает X-Forwarded-For), иначе
     * REMOTE_ADDR. Request::ip() в этой версии Kohana нет.
     */
    protected function _client_ip()
    {
        if (class_exists('Service_Nj100_Ratelimit'))
        {
            return (string) Service_Nj100_Ratelimit::client_ip();
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** Нормализованные фильтры витрины и REST /api/search (allowlist). */
    protected function _filters()
    {
        $q = $this->_query_value('q');
        if ($q === '')
        {
            $q = $this->_query_value('query');
        }

        $sort = $this->_query_value('sort');
        if ($sort === '')
        {
            $sort = $this->_query_value('sort_by');
        }

        $per_raw = $this->_query_value('per');
        if ($per_raw === '')
        {
            $per_raw = $this->_query_value('limit');
        }
        if ($per_raw === '')
        {
            $per_raw = $this->_query_value('per_page');
        }

        $ready_val = strtolower($this->_query_value('ready') ?: $this->_query_value('only_ready'));
        $gems_val = strtolower($this->_query_value('gems') ?: $this->_query_value('only_gems'));
        $audited_val = strtolower($this->_query_value('audited'));
        $offset_raw = $this->_query_value('offset');
        if ($offset_raw === '')
        {
            $offset_raw = $this->_query_value('cursor');
        }

        $filters = [
            'q' => mb_substr($q, 0, 120),
            'category' => $this->_query_value('category'),
            'transport' => $this->_query_value('transport'),
            'auth' => $this->_query_value('auth'),
            'health' => $this->_query_value('health'),
            'origin' => $this->_query_value('origin'),
            'sort' => $sort,
            'order' => $this->_query_value('order'),
            // «Только то, что можно вызвать сейчас»: одна галочка вместо трёх
            // фильтров (без ключа, без установки, проверен).
            'ready' => in_array($ready_val, ['1', 'true', 'yes'], TRUE),
            'gems' => in_array($gems_val, ['1', 'true', 'yes'], TRUE),
            'audited' => in_array($audited_val, ['1', 'true', 'yes'], TRUE),
            'page' => max(1, (int) $this->_query_value('page')),
            'per' => (int) $per_raw,
        ];

        if ($offset_raw !== '' AND is_numeric($offset_raw) AND (int) $offset_raw >= 0)
        {
            $filters['offset'] = (int) $offset_raw;
        }

        return $filters;
    }

    protected function _form_data($server)
    {
        $server['tools_json'] = json_encode($server['tools'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $server['categories'] = implode(', ', (array) $server['categories']);
        $server['env_keys'] = implode(', ', (array) $server['env_keys']);
        $server['package_manager'] = (string) ($server['package_manager'] ?? '');

        if ($server['package_manager'] === '' AND ! empty($server['packages'][0]['manager']))
        {
            $server['package_manager'] = (string) $server['packages'][0]['manager'];
            $server['package_name'] = (string) ($server['packages'][0]['name'] ?? '');
            $server['package_entrypoint'] = (string) ($server['packages'][0]['entrypoint'] ?? '');
        }

        return $server;
    }

    protected function _query_value($key)
    {
        $value = $this->request->query($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    protected function _json(array $data, $status = 200)
    {
        $this->auto_render = FALSE;
        $this->response->status((int) $status);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->headers('Cache-Control', 'no-store');
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
