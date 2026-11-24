<?php defined('SYSPATH') or die('No direct script access.');



/**
 * Controller_Prx102_Base
 *
 * Базовый контроллер для всех контроллеров приложения.
 * Инициализирует основной шаблон, загружает общие данные и управляет мета-тегами.
 */
#[AllowDynamicProperties]
abstract class Controller_Prx102_Base extends Controller {

    /**
     * @var  string  Название сайта
     */
    public $site_name;

    /**
     * @var  string  Режим отображения таблиц (div/table)
     */
    public $table_view;

    public $template = 'template_tailwindcss';
    // public $template = 'template_bootstrap_5_3';

    /**
     * @var  boolean  Включить ли автоматический рендеринг шаблона
     */
    public $auto_render = TRUE;

    /**
     * @var  array  Массив для хранения мета-тегов (title, description, keywords)
     */
    protected $_meta = array(
        'title'       => '',
        'description' => '',
        'keywords'    => ''
    );

    /**
     * @var  array  Массив для хранения CSS файлов
     */
    protected $_styles = array();

    /**
     * @var  array  Массив для хранения JavaScript файлов
     */
    protected $_scripts = array();

    /**
     * @var  Model_User|null  Текущий авторизованный пользователь.
     */
    protected $_current_user = NULL;

    /**
     * @var array Массив имен ролей текущего авторизованного пользователя.
     */
    protected $_current_user_roles = [];

    /** @var Prx_Actor|null Серверный контекст доступа (создаётся по требованию). */
    protected $_actor = NULL;

    /** Только машинные endpoints с собственной обязательной аутентификацией. */
    protected function machine_request(): bool
    {
        return FALSE;
    }

    public function before()
    {
        parent::before();

        // Канал определяет серверный контроллер, не заголовки клиента.
        Prx_PostGuard::assert_safe($this->request, $this->machine_request());

		// Controller_Robotprotection::check_protection();
      
		$site_config = Kohana::$config->load('site_config');
		   
		$site_name = $site_config->get('site_name');
		View::set_global('site_name', $site_name);
		$this->site_name = $site_name;
        
        $site_logo = $site_config->get('site_logo');
		View::set_global('site_logo', $site_logo);
		
		if ((isset ($_COOKIE["table_view"])) AND (Cookie::get("table_view", 'div')=='table')) $table_view = 'table'; else $table_view = 'div';
		View::set_global('table_view', $table_view);
		$this->table_view = $table_view;

        // Проверяем авторизацию и получаем данные пользователя
        if (Auth::instance()->logged_in('login'))
        {
            $user_model = Auth::instance()->get_user();
            $this->_current_user = $user_model;

            $roles = [];
            foreach ($user_model->roles->find_all() as $role) {
                $roles[] = $role->name;
            }
            $this->_current_user_roles = $roles;

            // prx599 «Выйти везде»: сессия, убитая kick-меткой, разлогинивается
            // на первом же запросе; живая — обновляет last_seen. try/catch:
            // сбой учёта не должен ломать страницу.
            if (class_exists('Service_Prx599_Account'))
            {
                try
                {
                    // session_valid() обновляет last_seen не чаще одного раза
                    // в 5 минут; отдельный upsert на каждой странице не нужен.
                    $session_valid = Service_Prx599_Account::session_valid((int) $user_model->id);
                }
                catch (Throwable $e)
                {
                    $session_valid = TRUE;
                    Kohana::$log->add(Log::WARNING, 'prx599: enforce: :e', [':e' => $e->getMessage()]);
                }
                if ( ! $session_valid)
                {
                    // HTTP::redirect бросает исключение; не поглощаем его
                    // общим catch, иначе продолжится запрос прежнего аккаунта.
                    Auth::instance()->logout(TRUE);
                    HTTP::redirect('/auth/login?kick=1', 302);
                }
            }
        }
        else
        {
            $this->_current_user = NULL;
            $this->_current_user_roles = [];
        }

        View::bind_global('current_user', $this->_current_user);
        View::bind_global('current_user_roles', $this->_current_user_roles);

        // Инициализация шаблона и передача общих данных
        if ($this->auto_render === TRUE)
        {
            $this->template = View::factory($this->template);

          	View::bind_global('styles', $this->_styles); 
            View::bind_global('scripts', $this->_scripts); 
			View::bind_global('title', $this->_meta['title']); 
          
            $this->template->set(array(
                'meta'               => $this->_meta,
                'styles'             => $this->_styles,
                'scripts'            => $this->_scripts,
                'content'            => '',
                'header'             => '',
                'footer'             => '',
                'sidebar'            => '',
                'module_nav'         => [],
                'current_user'       => $this->_current_user,
                'current_user_roles' => $this->_current_user_roles,
            ));

            // Меню сервисов — по умолчанию для всех страниц. До 28.09.2026
            // ровно этот вызов был скопирован в 102 контроллера: одинаковые
            // четыре строки с одинаковой защитой class_exists. Контроллеру,
            // которому нужно другое меню, достаточно вызвать set_menu() после
            // parent::before() — как делают Welcome и prx208.
            if (class_exists('Service_Prx110_Catalog'))
            {
                $this->set_menu(Service_Prx110_Catalog::menu_items());
            }
        }
    }
	
    /**
     * Метод для генерации и установки меню в шаблон с поддержкой разрешений (acl)
     * 
     * @param array $menu_items Список элементов меню
     */
    protected function set_menu(array $menu_items)
    {
        $allowed_menu_items = [];
        
        foreach ($menu_items as $item) {
            // Добавляем 'acl' в значения по умолчанию
            $item = array_merge(array(
                'title'      => '',
                'href'       => '#',
                'roles'      => [],
                'acl' => '', // Новое поле
                'active'     => 0,
                'icon'       => ''
            ), $item);

            $is_allowed = FALSE;

            // 1. Проверяем наличие 'acl' (Приоритет)
            if (!empty($item['acl'])) {
                if ($item['acl'] === 'all') {
                    $is_allowed = TRUE;
                } else {
                    // Используем встроенный метод can()
                    $is_allowed = $this->can($item['acl']);
                }
            } 
            // 2. Если acl не указан, проверяем 'roles' (Совместимость)
            elseif (!empty($item['roles'])) {
                $is_allowed = $this->has_any_role($item['roles']);
            } 
            // 3. Если ничего не указано — пункт доступен всем
            else {
                $is_allowed = TRUE;
            }

            if ($is_allowed) {
                $allowed_menu_items[] = $item;
            }
        }

        // Активный пункт — самый длинный совпавший href или его алиас
        // (например, /poll подсвечивается и на объекте /p/<code>).
        $current_uri = '/' . $this->request->uri();
        $active_href = NULL;
        $active_len  = -1;

        foreach ($allowed_menu_items as $item)
        {
            if ($item['href'] === '#' OR $item['href'] === '') continue;

            $paths = array_merge([(string) $item['href']], (array) ($item['active_paths'] ?? []));

            foreach ($paths as $path)
            {
                $href = rtrim((string) $path, '/');

                if ($href !== '' AND ($current_uri === $href OR strpos($current_uri, $href.'/') === 0)
                    AND strlen($href) > $active_len)
                {
                    $active_href = $item['href'];
                    $active_len  = strlen($href);
                }
            }
        }

        foreach ($allowed_menu_items as $key => $item)
        {
            if ($active_href !== NULL AND $item['href'] === $active_href)
            {
                $allowed_menu_items[$key]['active'] = 1;
                $active_href = NULL;
            }
        }

        if ($this->auto_render === TRUE) {
            $this->template->menu_items = $allowed_menu_items;
        }
    }

    /**
     * Меню модуля: чипы в шапке на больших экранах (md+), на малых —
     * прокручиваемая строка под ней, отдельно от постоянной левой панели.
     * Фильтрация по ролям/acl — как в set_menu().
     */
    protected function set_module_nav(array $items)
    {
        $allowed = [];

        foreach ($items as $item) {
            $item = array_merge([
                'title' => '',
                'href'  => '#',
                'icon'  => '',
                'roles' => [],
                'acl'   => '',
                'active' => 0,
            ], $item);

            if ( ! empty($item['acl'])) {
                $is_allowed = ($item['acl'] === 'all') ? TRUE : $this->can($item['acl']);
            } elseif ( ! empty($item['roles'])) {
                $is_allowed = $this->has_any_role((array) $item['roles']);
            } else {
                $is_allowed = TRUE;
            }

            if ($is_allowed AND trim((string) $item['title']) !== '') {
                $allowed[] = $item;
            }
        }

        // Активный пункт — самый длинный совпавший href или явно объявленный
        // алиас маршрута объекта (например, /p/<code> внутри /poll).
        $current_uri = '/' . $this->request->uri();
        $active_href = NULL;
        $active_len  = -1;

        foreach ($allowed as $item)
        {
            if ($item['href'] === '#' OR $item['href'] === '') continue;

            $paths = array_merge([(string) $item['href']], (array) ($item['active_paths'] ?? []));

            foreach ($paths as $path)
            {
                $href = rtrim((string) $path, '/');

                if ($href !== '' AND ($current_uri === $href OR strpos($current_uri, $href.'/') === 0)
                    AND strlen($href) > $active_len)
                {
                    $active_href = $item['href'];
                    $active_len  = strlen($href);
                }
            }
        }

        foreach ($allowed as $key => $item)
        {
            $allowed[$key]['active'] = ($active_href !== NULL AND $item['href'] === $active_href) ? 1 : 0;
        }

        if ($this->auto_render === TRUE) {
            $this->template->module_nav = $allowed;
        }
    }

    public function after()
    {
        if ($this->auto_render === TRUE)
        {
            $this->template->meta = $this->_meta;
            $this->template->styles = $this->_styles;
            $this->template->scripts = $this->_scripts;

            // Избранное нужно уже в первом HTML, а не после запроса браузера.
            // API без шаблона эту выборку не выполняет. Сбой БД не должен
            // блокировать страницу миграций и остальные разделы.
            $nav_favorites = ['status' => 'guest', 'user_id' => 0, 'csrf' => '', 'items' => []];
            if ($this->_current_user)
            {
                $nav_favorites['user_id'] = (int) $this->_current_user->id;
                $nav_favorites['status'] = 'error';
                $this->response->headers('Cache-Control', 'private, no-store');
                try
                {
                    $nav_favorites['csrf'] = Security::token();
                    $items = Service_Prx110_Favorites::items($nav_favorites['user_id']);
                    $nav_favorites['items'] = Service_Prx110_Favorites::mark_active($items, '/'.ltrim($this->request->uri(), '/'));
                    $nav_favorites['status'] = 'ready';
                }
                catch (Throwable $e)
                {
                    Kohana::$log->add(Log::ERROR, 'prx110: не удалось отобразить Избранное (:type)', [':type' => get_class($e)]);
                }
            }
            // Доступно и общему шаблону, и ещё не отрендеренным карточкам каталога.
            View::set_global('nav_favorites', $nav_favorites);
            $this->template->nav_favorites = $nav_favorites;
            $this->response->body($this->template->render());
        }

        parent::after();
    }

    protected function has_role($role_name)
    {
        return in_array($role_name, $this->_current_user_roles);
    }

    protected function has_any_role(array $roles)
    {
        foreach ($roles as $role) {
            if ($this->has_role($role)) {
                return true;
            }
        }
        return false;
    }

    protected function set_title($title)
    {
        $this->_meta['title'] = $title;
    }

    protected function set_description($description)
    {
        $this->_meta['description'] = $description;
    }

    protected function set_keywords($keywords)
    {
        $this->_meta['keywords'] = $keywords;
    }

    protected function add_style($file_path, $media = 'screen')
    {
        $this->_styles[] = array('file' => $file_path, 'media' => $media);
    }

    protected function add_script($file_path, $defer = FALSE)
    {
        $this->_scripts[] = array('file' => $file_path, 'defer' => $defer);
    }

    protected function add_inline_script($script_code)
    {
        $this->_scripts[] = array('code' => $script_code);
    }

    protected function add_inline_style($style_code)
    {
        $this->_styles[] = array('code' => $style_code);
    }
	
	protected function is_owner($data, $owner_property = 'user_id')
	{
		if (!$this->_current_user) {
			return FALSE;
		}

		$current_user_id = (int) $this->_current_user->id;

		if (is_object($data)) {
			if (isset($data->{$owner_property})) {
				return (int) $data->{$owner_property} === $current_user_id;
			} elseif (method_exists($data, 'get_owner_id')) {
				return (int) $data->get_owner_id() === $current_user_id;
			}
		} elseif (is_array($data)) {
			if (isset($data[$owner_property])) {
				return (int) $data[$owner_property] === $current_user_id;
			}
		}

		return FALSE;
	}
	
    /**
     * Серверный контекст доступа (Prx_Actor): кто действует, с какими ролями
     * и в каком оргконтексте. Единственный источник actor для сервисов.
     */
    public function actor()
    {
        if ($this->_actor !== NULL)
        {
            return $this->_actor;
        }

        if ( ! $this->_current_user)
        {
            return $this->_actor = Prx_Actor::guest();
        }

        $org = [];
        if (class_exists('Service_Prx300_Directory'))
        {
            try
            {
                $org = (array) Service_Prx300_Directory::context_for_user((int) $this->_current_user->id);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'actor: org context: :e', [':e' => $e->getMessage()]);
            }
        }

        return $this->_actor = new Prx_Actor(
            (int) $this->_current_user->id,
            $this->_current_user_roles,
            is_array($org) ? $org : []
        );
    }

    /**
     * Главный метод проверки прав в системе (Единообразно)
     * 
     * @param string $ability Ключ права (например, 'client.edit')
     * @param mixed $object (Опционально) Объект для проверки владения
     */
    public function can($ability, $object = NULL)
    {
        // 1. "Просто": Управление всегда может всё
        if ($this->has_role('admin')) {
            return TRUE;
        }

        // 2. "Хорошо": Если передан объект, проверяем владение
        // (Например: редактировать свою задачу можно без спец. прав)
        if ($object !== NULL) {
            if ($this->is_owner($object)) {
                return TRUE;
            }
        }

        // 3. Проверка через системный ACL (маппинг ролей и прав)
        return $this->actor()->can($ability);
    }

}