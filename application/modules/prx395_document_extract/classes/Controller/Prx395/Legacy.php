<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Legacy /document-extract → /prx395_document_extract.
 *
 * Редиректим с 301 и сохраняем query. Авторизация не нужна: доступ проверит
 * новый адрес. Формы админки отправляются уже на новый путь, поэтому POST
 * сюда не приходит.
 */
class Controller_Prx395_Legacy extends Controller_Base
{
    public $auto_render = FALSE;
    protected $auth_required = FALSE;

    public function action_index()
    {
        $path = trim((string) $this->request->param('id'), '/');
        $uri = '/prx395_document_extract'.($path !== '' ? '/'.$path : '');
        $query = $this->request->query();

        if ( ! empty($query))
        {
            $uri .= '?'.http_build_query($query);
        }

        HTTP::redirect($uri, 301);
    }
}
