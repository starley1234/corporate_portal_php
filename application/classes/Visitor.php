<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Visitor — общая идентификация посетителей для всех модулей.
 *
 * Часть модулей — только для зарегистрированных, часть — публичные, но
 * привязывать данные (запросы, предпочтения, лимиты) к пользователю нужно
 * везде. Для анонимов используется постоянная браузерная кука `ubid`
 * (случайный 32-символьный идентификатор, живёт год).
 *
 * Использование:
 *     $uid = Visitor::uid();          // 'u:12' или 'g:a1b2…' — ключ в БД
 *     Visitor::user_id();              // int|NULL — id залогиненного
 *
 * Кука ставится лениво при первом Visitor::ubid()/uid() — вызывайте из
 * контроллера до вывода тела ответа (например, в before()/action_*).
 */
class Visitor {

    /** Имя браузерной куки с идентификатором анонима */
    const COOKIE = 'ubid';

    /** Срок жизни куки: год */
    const LIFETIME = 31536000;

    /** @var string|NULL кеш значения ubid текущего запроса */
    protected static $_ubid = NULL;

    /** id залогиненного пользователя или NULL. */
    public static function user_id()
    {
        if ( ! class_exists('Auth'))
        {
            return NULL;
        }

        $user = Auth::instance()->get_user();

        return $user ? (int) $user->id : NULL;
    }

    /**
     * Есть ли уже валидная браузерная кука `ubid` — БЕЗ генерации новой.
     * В отличие от ubid()/uid() ничего не выставляет: отсекает роботов и
     * краулеров, у которых кук нет, от реального браузера, которому кука
     * была поставлена при прошлом визите.
     */
    public static function has_ubid()
    {
        $value = Cookie::get(self::COOKIE);

        return is_string($value) AND preg_match('/^[0-9a-f]{32}$/', $value) === 1;
    }

    /**
     * Идентификатор анонима из куки `ubid`. Если куки нет — генерирует и
     * ставит её (год). Возвращает строку или NULL, если куки нельзя выставить.
     */
    public static function ubid()
    {
        if (self::$_ubid !== NULL)
        {
            return self::$_ubid;
        }

        $value = Cookie::get(self::COOKIE);

        if ( ! is_string($value) OR ! preg_match('/^[0-9a-f]{32}$/', $value))
        {
            $value = bin2hex(random_bytes(16));

            try
            {
                Cookie::set(self::COOKIE, $value, self::LIFETIME);
            }
            catch (Exception $e)
            {
                // соль/заголовки недоступны — идентификация только на запрос
                Kohana::$log->add(Log::WARNING, 'Visitor: не удалось поставить куку ubid: :e',
                    [':e' => $e->getMessage()]);
            }
        }

        return self::$_ubid = $value;
    }

    /**
     * Единый идентификатор посетителя для хранения в БД:
     * 'u:<id>' у залогиненных, 'g:<ubid>' у анонимов.
     */
    public static function uid()
    {
        $user_id = self::user_id();

        if ($user_id !== NULL)
        {
            return 'u:'.$user_id;
        }

        $ubid = self::ubid();

        return $ubid !== NULL ? 'g:'.$ubid : 'anon';
    }
}
