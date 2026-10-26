<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Путеводитель (nj596): автособираемая документация.
 *
 * Для пользователей — /guide и /guide/<slug>: разделы и презентации сервисов
 * (манифесты nj110 + человеческие описания config/nj596_presentations.php,
 * Mermaid-диаграммы). Для разработчиков — /guide/dev (роль admin): README,
 * AGENTS, манифесты, MCP-инструменты всех модулей (Parsedown).
 */
class Controller_Nj596_Guide extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $nav = [
            ['title' => 'Обзор', 'href' => '/guide', 'icon' => 'fas fa-map'],
        ];

        if (Auth::instance()->logged_in('admin'))
        {
            $nav[] = ['title' => 'Разработчику', 'href' => '/guide/dev', 'icon' => 'fas fa-code', 'roles' => ['admin']];
        }

        $this->set_module_nav($nav);
    }

    /** GET /guide — обзор всех сервисов по разделам. */
    public function action_index()
    {
        $modules = $this->_catalog_modules();
        $pres    = Kohana::$config->load('nj596_presentations');
        $groups  = [];

        foreach ($modules as $m)
        {
            $slug = (string) $m['slug'];
            $m['presented'] = isset($pres[$slug]);
            $m['tagline']   = isset($pres[$slug]['tagline']) ? (string) $pres[$slug]['tagline'] : '';
            $groups[(string) $m['group']][] = $m;
        }

        $this->set_title('Путеводитель — что умеет N2J.ru');
        $this->set_description('Обзор всех сервисов платформы: что делает каждый, как пользоваться, пошаговые схемы. Живая документация из манифестов модулей.');

        $this->template->content = View::factory('596/index', [
            'groups'   => $groups,
            'total'    => count($modules),
            'is_admin' => Auth::instance()->logged_in('admin'),
        ]);
    }

    /** GET /guide/<slug> — презентация сервиса. */
    public function action_service()
    {
        $slug = (string) $this->request->param('slug');
        $mod  = NULL;

        foreach ($this->_catalog_modules() as $m)
        {
            if ($m['slug'] === $slug)
            {
                $mod = $m;
                break;
            }
        }

        $pres = Kohana::$config->load('nj596_presentations');
        $p    = isset($pres[$slug]) ? (array) $pres[$slug] : NULL;

        if ($mod === NULL)
        {
            throw HTTP_Exception::factory(404, 'Сервиса нет в путеводителе');
        }

        $title = $p['title'] ?? ($mod['title'] ?? $slug);

        $this->set_title($title.' — как пользоваться');
        $this->set_description((string) ($p['about'] ?? ($mod['short'] ?? '')));

        $this->template->content = View::factory('596/service', [
            'slug'   => $slug,
            'mod'    => $mod,
            'p'      => $p,
            'others' => array_slice($this->_catalog_modules(), 0, 100),
        ]);
    }

    /** GET /guide/dev — автособираемая документация для разработчиков (admin). */
    public function action_dev()
    {
        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $md = $this->_parsedown();
        $sections = [];

        foreach (Kohana::modules() as $name => $path)
        {
            // манифест берём у общего сборщика (он же кэширует), а файлы
            // README/AGENTS/mcp всё равно нужны по пути модуля
            $manifest = Manifest::get($name) ?: NULL;

            $readme = is_file($path.'README.md') ? (string) file_get_contents($path.'README.md') : '';
            $agents = is_file($path.'AGENTS.md') ? (string) file_get_contents($path.'AGENTS.md') : '';

            $mcp = [];
            $mcpfile = $path.'config'.DIRECTORY_SEPARATOR.'mcp.php';

            if (is_file($mcpfile))
            {
                try
                {
                    $mcp_cfg = include $mcpfile;

                    foreach ((array) ($mcp_cfg['tools'] ?? []) as $t)
                    {
                        if (isset($t['name']))
                        {
                            $mcp[] = (string) $t['name'];
                        }
                    }
                }
                catch (Throwable $e) { /* нет инструментов — не страшно */ }
            }

            $sections[] = [
                'name'     => (string) $name,
                'manifest' => $manifest,
                'mcp'      => $mcp,
                'readme'   => $readme !== '' ? $md->text($readme) : '',
                'agents'   => $agents !== '' ? $md->text($agents) : '',
            ];
        }

        // сервисы каталога — вперёд, платформенные — следом
        usort($sections, function ($a, $b) {
            return [(empty($a['manifest']) ? 1 : 0), $a['name']] <=> [(empty($b['manifest']) ? 1 : 0), $b['name']];
        });

        $this->set_title('Путеводитель разработчика — автосборка по модулям');

        $this->template->content = View::factory('596/dev', [
            'sections' => $sections,
        ]);
    }

    /** Модули каталога nj110 (манифесты) в стабильном порядке. */
    protected function _catalog_modules()
    {
        $out = [];

        if (class_exists('Service_Nj110_Catalog'))
        {
            foreach ((array) Service_Nj110_Catalog::modules() as $m)
            {
                if ( ! empty($m['slug']))
                {
                    $out[] = $m;
                }
            }
        }

        usort($out, function ($a, $b) {
            return [$a['group'] ?? '', $a['sort'] ?? 0] <=> [$b['group'] ?? '', $b['sort'] ?? 0];
        });

        return $out;
    }

    /** Parsedown (vendor модуля) — один раз. */
    protected function _parsedown()
    {
        require_once APPPATH.'modules/nj596_guide/vendor/Parsedown.php';

        return new Parsedown();
    }
}
