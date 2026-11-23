<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Вход обязателен» — общий замок платформы для сервисов, которые работают
 * только по аккаунту (решение владельца 08.10.2026).
 *
 * Зачем отдельный класс, а не проверка в каждом контроллере: замков теперь
 * много (veclab, imagegen, qc, cameras, similar, review, scout, dossier), и все
 * они должны отвечать ОДИНАКОВО — и человеку, и клиенту API:
 *
 *   · человек получает страницу с плашкой `partials/auth_wall` (что даёт
 *     сервис, зачем аккаунт и одна кнопка входа) — вид один у всех;
 *   · API отдаёт 401 JSON (`{ok:false, error:'auth', login:'/auth/login…'}`)
 *     через `HTTP_Exception_401` — клиент уводит человека на вход, а не
 *     показывает ему HTML страницы ошибки.
 *
 * Работает по ядру Auth: тот же признак входа, что и у остальных страниц
 * (`Auth::instance()->logged_in()`), поэтому ничего не расходится с ядром.
 * Анонимные посетители отдельно НЕ считаются: кука `ubid` (Visitor) — это не
 * аккаунт, и «только для зарегистрированных» означает именно аккаунт.
 */
class Prx_Gate
{
    /**
     * Пришёл ли запрос от воркера (X-Worker-Token)?
     *
     * Воркерские точки (приём кадров и результатов, cron-пинки) ходят без
     * сессии: ключ проверяется здесь. Точки сами задают политику отсутствия
     * токена и, где требуется, site-токен с приоритетом над общим. Замок пропускает их
     * дальше — иначе воркер молча перестал бы доезжать (урок 21.09: «вердикт
     * воркера не доезжал»).
     * Без query сравнение строгое; при opt-in токены обрезаются, как в API
     * очереди. Пустой секрет всегда запрещает запрос.
     */
    public static function worker_request(Request $request, ?string $expected = NULL, bool $allow_query = FALSE): bool
    {
        $want = $expected === NULL ? (string) getenv('WORKER_API_TOKEN') : $expected;
        $token = $request->headers('x-worker-token');
        if ($allow_query)
        {
            if ($token === NULL OR trim((string) $token) === '') $token = $request->query('token');
            $want = trim($want);
            $token = trim((string) $token);
        }
        else
        {
            $token = (string) $token;
        }
        return $want !== '' AND $token !== '' AND hash_equals($want, $token);
    }

    /**
     * Действие API, которое обязан пропускать замок: воркерские точки.
     *
     * Дефис в адресе нормализуем в подчёркивание, потому что маршруты пишут
     * `worker-result` (см. init.php модулей), а методы — `action_worker_result`.
     */
    public static function worker_action($action)
    {
        $action = str_replace('-', '_', strtolower((string) $action));

        return in_array($action, [
            'worker_result',    // приём результата прогона
            'worker_upload',    // приём файла картинки (prx641)
            'worker_token',     // служебная проверка токена
            'photo_file',       // кадр прогона для воркера (vision-сервисы)
            'cron_poll',        // пинок расписания тик-источников (prx639/prx640)
            'webhook',          // внешний триггер датчика/ПЛК
        ], TRUE);
    }

    /**
     * Залогинен ли посетитель. Одна точка правды для всех замков.
     */
    public static function logged_in()
    {
        return (bool) Auth::instance()->logged_in();
    }

    /**
     * Плашка «Сервис для зарегистрированных» — готовая вьюха для страниц.
     *
     *   echo Prx_Gate::wall('veclab', [
     *       'icon'   => 'fas fa-vector-square',
     *       'title'  => 'Лаборатория векторов — по аккаунту',
     *       'text'   => '…почему нужен вход…',
     *       'points' => ['что', 'получит', 'человек'],
     *   ]);
     *
     * @param  string $return_url куда вернуть после входа (локальный путь)
     * @param  array  $options    параметры `partials/auth_wall`
     * @return View
     */
    public static function wall($return_url = '', array $options = [])
    {
        return View::factory('partials/auth_wall', $options + [
            'return_url' => (string) $return_url,
        ]);
    }

    /**
     * Текущая страница сервиса по адресу запроса — для возврата после входа.
     * «/imagegen/api/run» → «imagegen»: человеку нужна страница, а не точка API,
     * и без ведущего слэша — в этом виде `return_url` понимает Controller_Auth
     * (так же его строят плашки `Prx_Gate::wall('veclab')`).
     */
    public static function page_from_uri($uri)
    {
        $path = trim((string) $uri, '/');

        if ($path === '')
        {
            return '';
        }

        $segments = explode('/', $path);

        return $segments[0];
    }
}
