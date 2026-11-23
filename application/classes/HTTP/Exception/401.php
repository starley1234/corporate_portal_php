<?php defined('SYSPATH') or die('No direct script access.');

/**
 * 401 «нужен бесплатный аккаунт» — для JSON-API сервисов, которые работают
 * только с зарегистрированными (см. `application/classes/Prx/Gate.php`).
 *
 * Почему отдельный класс, а не `HTTP_Exception::factory(401, …)`: у платформы
 * 401 не был объявлен, и заводской обработчик вернул бы HTML страницы ошибки.
 * Клиент этих точек — JavaScript страницы, а он умеет понять только JSON:
 *
 *   { "ok": false, "error": "auth", "login": "/auth/login?return_url=/imagegen" }
 *
 * `login` — адрес возврата после входа. Он собирается из адреса самого запроса
 * (`/imagegen/api/run` → `/imagegen`), поэтому точка API не должна ничего о нём
 * знать: замок в `before()` контроллера остаётся одной строкой.
 */
// Наследуем не ядровой 401 (он «Expected»: требует заголовок WWW-Authenticate
// и падает без него), а 403 — как это уже сделано для страницы 403 сайта.
// Важно: `Kohana_HTTP_Exception_403` — потомок класса `HTTP_Exception`, поэтому
// запрос ловит исключение в `catch (HTTP_Exception $e)` и берёт ответ из
// `get_response()`. Прямой потомок `Kohana_HTTP_Exception` до этой ветки
// не доходит и отдаёт HTML страницы ошибки вместо JSON.
class HTTP_Exception_401 extends Kohana_HTTP_Exception_403
{
    /** @var int статус «требуется аутентификация» */
    protected $_code = 401;

    public function get_response()
    {
        $page  = Prx_Gate::page_from_uri($this->request() ? (string) $this->request()->uri() : '');
        $login = '/auth/login'.($page !== '' ? '?return_url='.rawurlencode($page) : '');

        return Response::factory()
            ->status(401)
            ->headers('Content-Type', 'application/json; charset='.Kohana::$charset)
            ->body(json_encode([
                'ok'      => FALSE,
                'error'   => 'auth',
                'message' => 'Требуется вход в PRX.',
                'login'   => $login,
            ], JSON_UNESCAPED_UNICODE));
    }
}
