<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx110_catalog API: GET /prx110/api/catalog — JSON реестра видимых сервисов.
 *
 * Используется палитрой «Все сервисы» в боковой панели и страницей
 * /services (опорные данные для клиентской фильтрации). Поиск идёт по ВСЕМ
 * доступным текущему пользователю сервисам — не по пунктам левого меню:
 * auth/admin/agent-сервисы попадают в выдачу, если у пользователя есть право.
 *
 * Публичный GET, без побочных эффектов; кеш реестра — на запрос.
 */
class Controller_Prx110_Api extends Controller_Prx100_Base {

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;
        if (! $this->_current_user)
        {
            throw HTTP_Exception::factory(401, 'Требуется вход');
        }
    }

    /** GET /prx110/api/catalog. */
    public function action_catalog()
    {
        try
        {
            $catalog = class_exists('Service_Prx110_Catalog')
                ? Service_Prx110_Catalog::search_json()
                : ['groups' => [], 'items' => [], 'total' => 0];

            $this->response->status(200);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->headers('Cache-Control', 'no-store');
            $this->response->body(json_encode($catalog, JSON_UNESCAPED_UNICODE));
        }
        catch (Throwable $e)
        {
            $this->response->status(400);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->body(json_encode([
                'error' => 'Каталог временно недоступен: '.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}
