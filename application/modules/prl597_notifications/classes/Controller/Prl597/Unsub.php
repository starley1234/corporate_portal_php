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

        $base  = Service_Prl597_Base::base_url();

        $color = $ok ? '#059669' : '#dc2626';
        $mark  = $ok ? '✓' : '!';

        $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$title.' — PRL.ru</title></head>'
            .'<body style="margin:0;background:#f3f4f6;font:16px/1.55 Arial,Helvetica,sans-serif;color:#111827;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:48px 16px;">'
            .'<table role="presentation" width="560" cellpadding="0" cellspacing="0" '
            .'style="max-width:560px;width:100%;background:#ffffff;border:1px solid #e5e7eb;border-radius:16px;">'
            .'<tr><td style="background:#111827;padding:16px 28px;color:#ffffff;font:bold 18px Arial,Helvetica,sans-serif;">'
            .'<span style="color:#60a5fa;">PRL</span>.ru</td></tr>'
            .'<tr><td style="padding:28px;">'
            .'<div style="font:bold 40px Arial,Helvetica,sans-serif;color:'.$color.';line-height:1;">'.$mark.'</div>'
            .'<h1 style="margin:8px 0 12px;font-size:20px;">'.$title.'</h1>'
            .'<p style="margin:0 0 20px;color:#374151;">'.$text.'</p>'
            .'<p style="margin:0 0 8px;"><a href="'.$base.'/prl597?tab=settings" style="color:#2563eb;">Настройки уведомлений</a></p>'
            .'<p style="margin:0;"><a href="'.$base.'" style="color:#2563eb;">На главную PRL.ru</a></p>'
            .'</td></tr></table></td></tr></table></body></html>';

        $response->status((int) $code);
        $response->headers('Content-Type', 'text/html; charset=utf-8');

        // Почтовый клиент «в один клик» (RFC 8058) ждёт короткий ответ без
        // страницы: он её не показывает. Человеку в браузере — страницу.
        $response->body($post ? '' : $html);
    }
}
