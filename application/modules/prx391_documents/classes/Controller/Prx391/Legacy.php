<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Legacy /documents → /prx391_documents.
 *
 * Один маршрут с `path` покрывает и список, и карточку, и file/save/close.
 * Редиректим с 301 и сохраняем query; авторизация не нужна — цель всё равно
 * проверит доступ на новом адресе.
 */
class Controller_Prx391_Legacy extends Controller_Base
{
    public $auto_render = FALSE;
    protected $auth_required = FALSE;

    public function action_index()
    {
        $path = trim((string) $this->request->param('path'), '/');
        $uri = '/prx391_documents'.($path !== '' ? '/'.$path : '');
        $query = $this->request->query();

        if ( ! empty($query))
        {
            $uri .= '?'.http_build_query($query);
        }

        HTTP::redirect($uri, 301);
    }
}
