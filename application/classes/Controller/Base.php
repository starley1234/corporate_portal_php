<?php defined('SYSPATH') or die('No direct script access.');

/** Контроллер прикладных модулей: авторизация обязательна по умолчанию. */
abstract class Controller_Base extends Controller_Prx102_Base {

    /**
     * @var bool Требуется ли авторизация для доступа к контроллеру по умолчанию
     */
    protected $auth_required = TRUE;

    /**
     * @var array Список action, доступных без авторизации
     */
    protected $public_actions = [];

    /**
     * Выполняется перед вызовом action.
     */
    public function before()
    {
        parent::before();

        // Проверяем, нужно ли ограничивать доступ
        if ($this->auth_required) {
            $action = $this->request->action();

            // Если экшен не в белом списке и пользователь не авторизован
            if (!in_array($action, $this->public_actions) && !$this->_current_user) {
                // Перенаправляем на логин или выдаем 403
                throw new HTTP_Exception_403('Access Denied. Please login.');
            }
        }

        /**
         * ПРИМЕР ВЫЗОВА МЕНЮ С ПРОВЕРКОЙ РАЗРЕШЕНИЙ:
         *
         * 'acl' => 'all' (или пустая строка) — пункт видят все авторизованные.
         * 'acl' => 'task.view' — увидят те, кому разрешено 'task.view'.
         *
         * $this->set_menu([
         *     [
         *         'title'      => 'Задачи',
         *         'href'       => '/prx100_tasks',
         *         'icon'       => 'fas fa-tasks',
         *         'acl' => 'task.view',
         *         'children'   => []
         *     ],
         *     [
         *         'title'      => 'Настройки системы',
         *         'href'       => '/prx100_settings',
         *         'icon'       => 'fas fa-cog',
         *         'acl' => 'settings.manage',
         *         'children'   => []
         *     ]
         * ]);
         */
    }

    /**
     * Быстрый ответ JSON для контроллеров (AJAX/API)
     */
    public function _json_response($data, $code = 200)
    {

        $this->auto_render = FALSE;
        $this->response
            ->status($code)
            ->headers('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

}
