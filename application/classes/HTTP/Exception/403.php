<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * 403 сайта: страница ошибки для браузера, JSON — для API.
 *
 * JSON-клиент (fetch страницы, интегратор) получал HTML-страницу и не мог
 * разобрать причину (например, отказ CSRF у POST /prx110/api/favorites).
 * JSON отдаётся, если запрос его ждёт: заголовок Accept с application/json
 * без text/html или адрес API (`api/v1/…`, `…/api/…`, `…_api/…`).
 */
class HTTP_Exception_403 extends Kohana_HTTP_Exception_403 {

    public $site_name;

    public function get_response()
    {
        if (self::wants_json($this->request()))
        {
            return Response::factory()
                ->status(403)
                ->headers('Content-Type', 'application/json; charset='.Kohana::$charset)
                ->headers('Cache-Control', 'no-store')
                ->body(json_encode([
                    'ok'      => FALSE,
                    'error'   => 'forbidden',
                    'message' => $this->getMessage() !== '' ? $this->getMessage() : 'Доступ запрещён.',
                ], JSON_UNESCAPED_UNICODE));
        }

        $template = View::factory('template_tailwindcss');
        $template->content = View::factory('errors/403');

        $site_config = Kohana::$config->load('site_config');

        $site_name = $site_config->get('site_name');
        View::set_global('site_name', $site_name);
        $this->site_name = $site_name;

        $site_logo = $site_config->get('site_logo');
        View::set_global('site_logo', $site_logo);

        return Response::factory()
            ->status(403)
            ->body($template->render());
    }

    /** Запрос ждёт JSON: Accept или адрес API. */
    public static function wants_json(?Request $request): bool
    {
        if ($request === NULL) return FALSE;

        $accept = strtolower((string) $request->headers('Accept'));
        if (strpos($accept, 'application/json') !== FALSE AND strpos($accept, 'text/html') === FALSE) return TRUE;

        return preg_match('~(?:^|/)(?:[a-z0-9]+_)*api(?:/|$)~i', ltrim((string) $request->uri(), '/')) === 1;
    }
}
