<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API миграций (prl111_migrations).
 *
 * Модуль ведёт схему базы, поэтому его витрина обычно остаётся на странице
 * `/migrate_pg`. Но состояние схемы нужно и машинам: развёртывание,
 * мониторинг и агент должны уметь спросить «что применено, что ждёт, есть ли
 * расхождения», не разбирая вывод консоли.
 *
 * Доступ только администратору: список миграций раскрывает устройство базы.
 *
 * @package    Prl111
 * @category   Controllers
 */
class Controller_Prl111_Api extends Controller_Base
{
    /** @var bool Не оборачивать ответ в шаблон */
    public $auto_render = FALSE;

    /** @var bool Авторизацию проверяем сами, чтобы отдавать JSON вместо HTML-403 */
    protected $auth_required = FALSE;

    public function before()
    {
        parent::before();

        // Только управление: методов _json_halt в базовом контроллере нет,
        // а ACL prl111.view нигде не сеялся — роль проверяем явно
        if ( ! $this->_current_user)
        {
            $this->_json_response(['ok' => FALSE, 'error' => 'Требуется авторизация'], 401);
            return;
        }

        if ( ! $this->has_role('admin'))
        {
            $this->_json_response(['ok' => FALSE, 'error' => 'Доступ только для администратора'], 403);
            return;
        }
    }

    /**
     * GET /migrate_pg/api/status?module=
     *
     * Сводка: сколько миграций найдено, применено, ждёт, какие модули затронуты.
     */
    public function action_status()
    {
        $module  = Arr::get($this->request->query(), 'module');
        $service = Service_Prl111_Migrator::instance();

        $report = $service->status_report($module);

        $this->_json_response([
            'ok'          => TRUE,
            'db_group'    => $service->db_group(),
            // applied — карта «класс миграции» => «когда применена»
            'applied'     => $service->applied(),
            'pending'     => array_values($service->pending($module)),
            'total'       => count($service->inventory($module)),
            // report_text — тот же текст, что печатает bin/console db:migrate
            'report_text' => $report,
        ]);
    }

    /**
     * GET /migrate_pg/api/inventory?module= — все найденные миграции с их состоянием.
     */
    public function action_inventory()
    {
        $module  = Arr::get($this->request->query(), 'module');
        $service = Service_Prl111_Migrator::instance();

        $this->_json_response(['ok' => TRUE, 'migrations' => array_values($service->inventory($module))]);
    }

    /**
     * GET /migrate_pg/api/orphans — применённые миграции, которых нет в коде.
     *
     * Полезно после переноса изменений: файл удалили, а запись в базе осталась.
     */
    public function action_orphans()
    {
        $this->_json_response(['ok' => TRUE, 'orphans' => array_values(Service_Prl111_Migrator::instance()->orphans())]);
    }
}
