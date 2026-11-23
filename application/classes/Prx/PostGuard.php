<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Глобальная защита от CSRF: одно правило вместо ручных проверок в каждом
 * контроллере.
 *
 * Любой небезопасный метод (POST/PUT/PATCH/DELETE) обязан доказать, что
 * запрос пришёл из нашего же UI, а не с чужого сайта:
 *
 *   1. валидный токен — поле `csrf` (форма или AJAX-тело) либо заголовок
 *      `X-CSRF-Token` (тот же `Security::token()` из сессии);
 *   2. ИЛИ машинный канал — заголовок `Authorization` (Bearer API) или
 *      `X-Worker-Token` (воркер). Такие заголовки нельзя поставить из чужой
 *      страницы, поэтому они сами по себе доказывают происхождение.
 *
 * Всё остальное — 403. Исключения только для входящих вебхуков с собственной
 * подписью: `config/post_guard.php`, ключ `webhooks`.
 *
 * Контроллеру ничего не нужно: `Controller_Prx100_Base::before()` вызывает
 * `Prx_PostGuard::assert_safe()`. Токен в форму — `Security::token()`.
 */
final class Prx_PostGuard
{
    /** HTTP-методы, которые не меняют состояние. */
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @throws HTTP_Exception_403
     */
    public static function assert_safe(Request $request): void
    {
        if (in_array(strtoupper($request->method()), self::SAFE, TRUE)) {
            return;
        }

        if (self::has_machine_credentials($request)) {
            return;
        }

        if (self::valid_token($request)) {
            return;
        }

        if (self::is_webhook($request)) {
            return;
        }

        if (Kohana::$log) {
            Kohana::$log->add(Log::NOTICE, 'post_guard: CSRF не прошёл :key :method :uri', [
                ':key' => self::key($request),
                ':method' => $request->method(),
                ':uri' => $request->uri(),
            ]);
        }

        throw HTTP_Exception::factory(403, 'Сессия устарела. Обновите страницу и повторите действие.');
    }

    /** Токен из тела запроса или заголовка; массив `csrf[]` — первый элемент. */
    public static function valid_token(Request $request): bool
    {
        $token = $request->post('csrf');

        if (is_array($token)) {
            $token = reset($token);
        }

        if ( ! is_string($token) OR $token === '') {
            $token = self::header($request, 'X-CSRF-Token');
        }

        return $token !== '' AND Security::check($token);
    }

    /** Машинный запрос: заголовок с учётными данными, недоступный чужому сайту. */
    public static function has_machine_credentials(Request $request): bool
    {
        foreach (['Authorization', 'X-Worker-Token'] as $header) {
            if (self::header($request, $header) !== '') {
                return TRUE;
            }
        }

        return FALSE;
    }

    /** Заголовок без зависимости от регистра ключа (Apache/CGI дают разный). */
    private static function header(Request $request, string $name): string
    {
        $name = strtolower($name);

        foreach ($request->headers() as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return trim(is_array($value) ? implode(',', $value) : (string) $value);
            }
        }

        return '';
    }

    /** `controller/action` в нижнем регистре — для логов и диагностики. */
    public static function key(Request $request): string
    {
        return strtolower($request->controller().'/'.$request->action());
    }

    /** Входящий вебхук: свой URI-префикс в config/post_guard.php. */
    public static function is_webhook(Request $request): bool
    {
        $uri = '/'.ltrim($request->uri(), '/');

        foreach (self::webhook_prefixes() as $prefix) {
            $prefix = '/'.ltrim($prefix, '/');

            if ($prefix === '/' OR strpos($uri, rtrim($prefix, '/').'/') === 0 OR $uri === rtrim($prefix, '/')) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /** @return array<int, string> */
    public static function webhook_prefixes(): array
    {
        $config = Kohana::$config->load('post_guard');

        return array_map('strval', (array) $config->get('webhooks', []));
    }
}
