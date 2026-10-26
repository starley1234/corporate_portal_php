<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Внутренний endpoint записи обезличенного просмотра (NJ-516).
 *
 * POST /nj516/api/hit {"path":"/vat"} — его вызывает общий app.js после
 * открытия страницы. Счётчик принимает только POST с same-origin Origin,
 * не раскрывает агрегаты наружу и не сохраняет содержимое Referer.
 */
class Controller_Nj516_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Cache-Control', 'no-store');
    }

    public function action_hit()
    {
        if ($this->request->method() !== Request::POST)
        {
            $this->response->status(405);
            $this->response->headers('Allow', 'POST');
            return;
        }

        // JS same-origin POST присылает Origin. Если заголовок есть — host обязан совпасть;
        // для браузеров без Origin остаётся только безвредная инкрементация агрегата.
        $origin = trim((string) $this->request->headers('origin'));
        if ($origin !== '')
        {
            $origin_host = strtolower((string) parse_url($origin, PHP_URL_HOST));
            $request_host = strtolower((string) parse_url('http://'.(string) Arr::get($_SERVER, 'HTTP_HOST', ''), PHP_URL_HOST));
            if ($origin_host === '' OR $request_host === '' OR $origin_host !== $request_host)
            {
                $this->response->status(204);
                return;
            }
        }

        $decoded = json_decode((string) $this->request->body(), TRUE);
        if ( ! is_array($decoded) OR ! isset($decoded['path']) OR ! is_string($decoded['path']))
        {
            $this->response->status(204);
            return;
        }

        $logged = FALSE;
        try
        {
            $logged = Auth::instance()->logged_in();
        }
        catch (Throwable $e)
        {
            // Учёт авторизации не должен ломать страницу или hit-endpoint.
        }

        try
        {
            Service_Nj516_Metrics::track_page(
                $decoded['path'],
                (string) $this->request->headers('referer'),
                (bool) $logged,
                (string) Arr::get($_SERVER, 'HTTP_USER_AGENT', ''),
                (string) Arr::get($_SERVER, 'HTTP_HOST', '')
            );
        }
        catch (Throwable $e)
        {
            // Аналитика — best effort, ошибка не должна влиять на пользователя.
            Kohana::$log->add(Log::WARNING, 'nj516: запись просмотра: :e', [':e' => $e->getMessage()]);
        }

        $this->response->status(204);
    }
}
