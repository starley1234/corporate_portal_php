<?php defined('SYSPATH') or die('No direct script access.');

/**
 * UI: небезопасные методы требуют CSRF, независимо от заголовков авторизации.
 * Машинный канал объявляет контроллер; он ОБЯЗАН проверить ключ перед операцией.
 * Вебхуки с собственной подписью перечислены в config/post_guard.php.
 */
final class Prx_PostGuard
{
    /** HTTP-методы, которые не меняют состояние. */
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @throws HTTP_Exception_403
     */
    public static function assert_safe(Request $request, bool $machine = FALSE): void
    {
        if (in_array(strtoupper($request->method()), self::SAFE, TRUE)) {
            return;
        }

        if ($machine) {
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
