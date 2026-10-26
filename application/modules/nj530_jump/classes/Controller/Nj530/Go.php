<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Редирект коротких ссылок (NJ-530): GET /x/<code> → 302 + счётчик.
 *
 * Только GET: боты-превью и клики считаются одинаково; POST сюда не вести.
 */
class Controller_Nj530_Go extends Controller {

    public $auto_render = FALSE;

    public function action_index()
    {
        $code = $this->request->param('code');
        $row = Service_Nj530_Jump::resolve($code);

        if ($row === NULL)
        {
            throw HTTP_Exception::factory(404, 'Короткая ссылка не найдена');
        }

        Service_Nj530_Jump::click($code);

        $this->response->headers('Cache-Control', 'no-store');
        HTTP::redirect($row['url'], 302);
    }
}
