<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx110_catalog API: GET /prx110/api/catalog — JSON реестра видимых сервисов.
 *
 * Используется палитрой «Все сервисы» в боковой панели и страницей
 * /services (опорные данные для клиентской фильтрации). Поиск идёт по ВСЕМ
 * доступным текущему пользователю сервисам — не по пунктам левого меню:
 * auth/admin/agent-сервисы попадают в выдачу, если у пользователя есть право.
 *
 * GET /prx110/api/favorites и POST /prx110/api/favorites — Избранное
 * текущего аккаунта; POST /prx110/api/favorites_import — явный перенос из
 * браузера. Изменения требуют CSRF и сверки текущего аккаунта.
 */
class Controller_Prx110_Api extends Controller_Prx100_Base {

    public function before()
    {
        try
        {
            parent::before();
        }
        catch (HTTP_Exception_Redirect $e)
        {
            // Принудительный выход для API — 401 JSON, а не HTML логина.
            if ( ! Auth::instance()->logged_in('login')) throw HTTP_Exception::factory(401, 'Требуется вход');
            throw $e;
        }
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
            // Позволяет вкладке обнаружить смену сессии, не выбирает пользователя.
            $catalog['user_id'] = (int) $this->_current_user->id;

            $this->response->status(200);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->headers('Cache-Control', 'private, no-store');
            $this->response->body(json_encode($catalog, JSON_UNESCAPED_UNICODE));
        }
        catch (Throwable $e)
        {
            $this->response->status(400);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->headers('Cache-Control', 'private, no-store');
            $this->response->body(json_encode([
                'error' => 'Каталог временно недоступен: '.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /** GET — текущее Избранное; POST — установить состояние одной звезды. */
    public function action_favorites()
    {
        $method = $this->request->method();
        if ($method !== Request::GET AND $method !== Request::POST)
        {
            $this->response->headers('Allow', 'GET, POST');
            $this->_favorites_reply(['error' => 'Метод не поддерживается'], 405);
            return;
        }
        if ($method === Request::POST AND ! $this->_favorites_write_allowed()) return;

        try
        {
            $uid = (int) $this->_current_user->id;
            if ($method === Request::POST)
            {
                $pinned = $this->request->post('pinned');
                $href = $this->request->post('href');
                if ( ! is_string($pinned) OR ! in_array($pinned, ['0', '1'], TRUE) OR ! is_string($href)
                    OR ! Service_Prx110_Favorites::set($uid, $href, $pinned === '1'))
                {
                    $this->_favorites_reply(['error' => 'Сервис недоступен или указан неверно'], 422);
                    return;
                }
            }
            $this->_favorites_reply(['user_id' => $uid, 'items' => Service_Prx110_Favorites::items($uid)]);
        }
        catch (Throwable $e)
        {
            $this->_favorites_failed($e);
        }
    }

    /** POST — только по выбору пользователя; существующие закладки сохраняются. */
    public function action_favorites_import()
    {
        if ($this->request->method() !== Request::POST)
        {
            $this->response->headers('Allow', 'POST');
            $this->_favorites_reply(['error' => 'Метод не поддерживается'], 405);
            return;
        }
        if ( ! $this->_favorites_write_allowed()) return;

        $raw = $this->request->post('hrefs');
        $hrefs = (is_string($raw) AND strlen($raw) <= 65536) ? json_decode($raw, TRUE) : NULL;
        if ( ! is_array($hrefs) OR ! array_is_list($hrefs) OR count($hrefs) > Service_Prx110_Favorites::IMPORT_LIMIT)
        {
            $this->_favorites_reply(['error' => 'Некорректный список Избранного'], 422);
            return;
        }

        try
        {
            $uid = (int) $this->_current_user->id;
            $result = Service_Prx110_Favorites::import($uid, $hrefs);
            $this->_favorites_reply([
                'user_id' => $uid, 'items' => Service_Prx110_Favorites::items($uid),
                'added' => $result['added'], 'skipped' => $result['skipped'],
            ]);
        }
        catch (Throwable $e)
        {
            $this->_favorites_failed($e);
        }
    }

    /** account_id не выбирает пользователя, а защищает устаревшую вкладку. */
    protected function _favorites_write_allowed()
    {
        $csrf = $this->request->post('csrf');
        if ( ! is_string($csrf) OR ! Security::check($csrf))
        {
            $this->_favorites_reply(['error' => 'Сессия формы устарела'], 403);
            return FALSE;
        }
        $account = $this->request->post('account_id');
        if ( ! is_string($account) OR $account === '' OR ! ctype_digit($account) OR (int) $account !== (int) $this->_current_user->id)
        {
            $this->_favorites_reply(['error' => 'Аккаунт изменился; обновите страницу'], 409);
            return FALSE;
        }
        return TRUE;
    }

    protected function _favorites_reply(array $body, $status = 200)
    {
        $this->response->status($status);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->headers('Cache-Control', 'private, no-store');
        $this->response->body(json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    protected function _favorites_failed(Throwable $e)
    {
        // Без адресов, идентификаторов и текста SQL в журнале.
        Kohana::$log->add(Log::ERROR, 'prx110: Избранное недоступно (:type)', [':type' => get_class($e)]);
        $this->_favorites_reply(['error' => 'Не удалось подтвердить состояние Избранного'], 503);
    }
}
