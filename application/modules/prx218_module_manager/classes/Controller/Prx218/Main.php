<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Controller_Prx218_Main
 * 
 * Базовый контроллер модуля Module Manager (Registry).
 * Отвечает за инициализацию общих свойств, проверку прав доступа и формирование меню.
 * 
 * @package Prx218
 * @version 1.0.0
 */
abstract class Controller_Prx218_Main extends Controller_Base {

    /**
     * @var Model_Prx218_Module Текущий модуль (если передан ID в URL)
     */
    protected $_module = NULL;

    /**
     * Инициализация модуля: проверка прав, заголовки и навигация.
     */
    public function before()
    {
        parent::before();

        // 1. Базовая проверка прав на доступ к модулю (чтение реестра)
        if ( ! $this->can('module_manager.view'))
        {
            throw new HTTP_Exception_403('Access Denied: Module Manager View');
        }

        // 2. Загрузка конфигурации модуля
        $config = Kohana::$config->load('prx218_module_manager');

        // 3. Установка базовых мета-данных
        $this->set_title(__('Реестр модулей'));
        $this->set_description(__('Централизованный учет и аудит программных компонентов системы'));

        // 4. Формирование единообразного меню модуля
        $this->_init_menu();

        // 5. Попытка загрузки объекта, если передан ID (для дочерних контроллеров)
        $id = (int) $this->request->param('id');
        if ($id > 0)
        {
            $this->_module = ORM::factory('Prx218_Module', $id);
        }
    }

    /** Инициатор события: id авторизованного пользователя или NULL (система). */
    protected function _actor_id(): ?int
    {
        return $this->_current_user ? (int) $this->_current_user->id : NULL;
    }

    /**
     * Инициализация структуры меню с учетом ACL
     */
    protected function _init_menu()
    {
        $menu = [
            [
                'title' => __('Список модулей'),
                'href'  => '/prx218',
                'icon'  => 'fas fa-cubes',
                'acl'   => 'module_manager.view',
            ],
            [
                'title' => __('Сканер системы'),
                'href'  => '/prx218/scanner',
                'icon'  => 'fas fa-search',
                'acl'   => 'module_manager.scan',
            ],
            [
                'title' => __('Зависимости'),
                'href'  => '/prx218/index/dependencies',
                'icon'  => 'fas fa-project-diagram',
                'acl'   => 'module_manager.view',
            ],
            [
                'title' => __('Настройки'),
                'href'  => '/prx218/index/settings',
                'icon'  => 'fas fa-cogs',
                'acl'   => 'module_manager.manage_status',
            ]
        ];

        // Меню модуля — наверх (чипы), слева — общая навигация по сервисам
        $this->set_module_nav($menu);

    }

    /**
     * Хелпер для проверки прав на конкретное действие над модулем
     * 
     * @param string $ability
     * @param mixed $object
     * @throws HTTP_Exception_403
     */
    protected function _check_permission($ability, $object = NULL)
    {
        if ( ! $this->can('module_manager.' . $ability, $object))
        {
            throw new HTTP_Exception_403('Access Denied: ' . $ability);
        }
    }
}