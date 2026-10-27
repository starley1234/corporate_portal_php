<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Отказ от уведомлений по ссылке из письма (prl597): /prl597/unsub.
 *
 * Зачем отдельный контроллер: ссылка приходит в чужой почтовый ящик, и
 * человек не обязан входить на сайт, чтобы отписаться. Пропуск — подпись
 * ссылки (HMAC, см. Notify::unsub_token), поэтому:
 *
 *   * сессии не требуем — иначе отписка превращается в квест «войди, найди
 *     настройки, найди событие, сними клетку»;
 *   * ничего не «вспоминаем» сами: что именно отключать, написано в ссылке,
 *     а подделать её без серверного секрета нельзя;
 *   * колокольчик на сайте не трогаем — отказ касается только того, что
 *     уходит наружу (владелец, 16.10.2026).
 *
 * GET  — человеческая страница с результатом;
 * POST — ответ 200 без тела: так работает «отписаться в один клик»
 *        (RFC 8058, заголовок List-Unsubscribe-Post), клиент письма зовёт
 *        ссылку сам и страницу не показывает.
 */
class Controller_Prl597_Unsub extends Controller
{
    public function before()
    {
        parent::before();

        $this->auto_render = FALSE;
    }

    /** GET|POST /prl597/unsub?u=&c=&e=&s=&t= */
    public function action_index()
    {
        $request = $this->request;
        $post    = $request->method() === Request::POST;

        // Значения одинаково читаются из строки запроса и из тела: почтовые
        // клиенты «в один клик» шлют POST на тот же адрес.
        $get = function ($key, $default = '') use ($request, $post) {
            $value = $post ? $request->post($key) : $request->query($key);

            if ($value === NULL OR $value === '')
            {
                $value = $request->query($key, $request->post($key, $default));
            }

            return trim((string) $value);
        };

        $user_id = (int) $get('u');
        $channel = $get('c', 'email');
        $alias   = $get('e');
        $scope   = $get('s', 'event');
        $token   = $get('t');

        if ( ! in_array($scope, ['channel', 'event'], TRUE))
        {
            $scope = 'event';
        }

        if ( ! Service_Prl597_Notify::unsub_verify($user_id, $channel, $alias, $scope, $token))
        {
            self::_page($this->response, FALSE, 'Ссылка не подходит',
                'Возможно, она скопирована не целиком или устарела. Настроить уведомления можно на сайте.', 403, $post);

            return;
        }

        $result = Service_Prl597_Notify::unsub_apply($user_id, $channel, $alias, $scope);

        self::_page($this->response, (bool) $result['ok'],
            $result['ok'] ? 'Готово' : 'Не получилось отключить',
            (string) $result['message'], 200, $post);
    }

    /**
     * Небольшая самостоятельная страница: шаблон сайта требует входа и
     * сессии, а здесь нужен ответ вне зависимости от них.
     *
     * Тело ставим прямо в ответ: Kohana не забирает возврат действия
     * (Controller::execute() его игнорирует) — так же поступает Api.
     */
    protected static function _page(Response $response, $ok, $title, $text, $code = 200, $post = FALSE)
    {
        $title = htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8');
        $text  = htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');

        $base = htmlspecialchars((string) Service_Prl597_Base::base_url(), ENT_QUOTES, 'UTF-8');

        $mark   = $ok ? '✓' : '!';
        $status = $ok ? 'status-ok' : 'status-err';

        $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$title.' — PRL</title>'
            .'<style>'
            .'*{box-sizing:border-box}'
            .'body{margin:0;background:#f9fafb;color:#111827;font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}'
            .'main{width:min(100% - 2rem,35rem);margin:12vh auto;padding:2rem;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 2px rgb(17 24 39/.06)}'
            .'.mark{display:flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:9999px;font-size:28px;font-weight:700;line-height:1}'
            .'.status-ok{background:#ecfdf5;color:#047857}.status-err{background:#fff1f2;color:#be123c}'
            .'h1{margin:1rem 0 .75rem;font-size:30px;line-height:1.2;letter-spacing:-.02em}'
            .'p{margin:.75rem 0;color:#4b5563}'
            .'.actions{display:flex;flex-wrap:wrap;gap:.625rem;margin-top:1.25rem}'
            .'a.button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:.625rem 1rem;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb;color:#1f2937;font-weight:600;text-decoration:none}'
            .'a.button:hover{background:#f3f4f6}'
            .'@media(prefers-color-scheme:dark){body{background:#111827;color:#f9fafb}main{background:#1f2937;border-color:#374151;box-shadow:0 1px 2px rgb(0 0 0/.25)}p{color:#d1d5db}.status-ok{background:rgb(6 78 59/.35);color:#6ee7b7}.status-err{background:rgb(80 7 36/.45);color:#fda4af}a.button{background:#1f2937;border-color:#4b5563;color:#f9fafb}a.button:hover{background:#374151}}'
            .'</style></head><body><main>'
            .'<div class="mark '.$status.'" aria-hidden="true">'.$mark.'</div>'
            .'<h1>'.$title.'</h1><p>'.$text.'</p>'
            .'<div class="actions">'
            .'<a class="button" href="'.$base.'/prl597?tab=settings">Настройки уведомлений</a>'
            .'<a class="button" href="'.$base.'">На главную</a>'
            .'</div></main></body></html>';

        $response->status((int) $code);
        $response->headers('Content-Type', 'text/html; charset=utf-8');

        // Почтовый клиент «в один клик» (RFC 8058) ждёт короткий ответ без
        // страницы: он её не показывает. Человеку в браузере — страницу.
        $response->body($post ? '' : $html);
    }
}
