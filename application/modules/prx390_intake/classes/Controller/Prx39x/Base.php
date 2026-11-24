<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Общая база страниц документооборота prx390–prx394.
 *
 * Здесь только то, что одинаково у пяти модулей: актор, CSRF/POST, флеш,
 * навигация контура и проверка права. Бизнес-логика — в сервисах модулей.
 */
abstract class Controller_Prx39x_Base extends Controller_Base
{
    public function before()
    {
        parent::before();
        if ($this->auth_required && ! $this->can('admin.access'))
        {
            throw HTTP_Exception::factory(403, 'Тестовый документооборот доступен только администратору');
        }
    }

    protected function _require($ability)
    {
        if ( ! $this->can($ability))
        {
            throw HTTP_Exception::factory(403, 'Недостаточно прав: '.$ability);
        }
    }

    protected function _nav($active)
    {
        $items = [
            ['title' => 'Входящие', 'href' => '/inbox', 'icon' => 'fas fa-inbox', 'acl' => 'prx390.view'],
            ['title' => 'Реестр', 'href' => '/prx391_documents', 'icon' => 'fas fa-folder-open', 'acl' => 'prx391.view'],
            ['title' => 'ИИ-разбор', 'href' => '/analysis', 'icon' => 'fas fa-wand-magic-sparkles', 'acl' => 'prx392.view'],
            ['title' => 'Сроки', 'href' => '/sla', 'icon' => 'fas fa-clock', 'acl' => 'prx393.view'],
        ];

        foreach ($items as &$item)
        {
            $item['active'] = $item['href'] === $active;
            $item['active_paths'] = $item['href'] === '/prx391_documents' ? ['/prx391_documents'] : [];
        }
        unset($item);

        $this->set_module_nav($items);
    }

    protected function _post(callable $operation, $redirect, callable $success = NULL)
    {
        if ($this->request->method() !== Request::POST)
        {
            throw (HTTP_Exception::factory(405, 'Нужен POST'))->allowed('POST');
        }
        $csrf = $this->request->post('csrf');
        if ( ! is_string($csrf) OR ! Security::check($csrf))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }

        try
        {
            $result = $operation();
            $message = is_string($result) ? $result : 'Готово';
            Session::instance()->set('prx39x_flash', ['type' => 'ok', 'text' => $message]);
            $target = $success ? (string) $success($result) : (string) $redirect;
        }
        catch (InvalidArgumentException $e)
        {
            Session::instance()->set('prx39x_flash', ['type' => 'err', 'text' => $e->getMessage()]);
            $target = (string) $redirect;
        }
        catch (RuntimeException $e)
        {
            Session::instance()->set('prx39x_flash', ['type' => 'err', 'text' => $e->getMessage()]);
            $target = (string) $redirect;
        }

        HTTP::redirect($target, 303);
    }

    protected function _flash()
    {
        $flash = Session::instance()->get_once('prx39x_flash', []);

        return is_array($flash) ? $flash : [];
    }

    protected function _back($fallback = '/prx391_documents')
    {
        $ref = (string) $this->request->referrer();
        if ($ref !== '' AND strpos($ref, '://') !== FALSE)
        {
            $path = parse_url($ref, PHP_URL_PATH);

            return $path ?: $fallback;
        }

        return $fallback;
    }

    protected function _csrf()
    {
        return Security::token();
    }

    protected function _json($data, $code = 200)
    {
        $this->auto_render = FALSE;
        $this->response
            ->status($code)
            ->headers('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
