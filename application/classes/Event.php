<?php defined('SYSPATH') or die('No direct script access.');

if ( ! class_exists('Service_Prl208_EventBus', false))
{
    $prev_reporting = error_reporting();
    error_reporting($prev_reporting & ~E_DEPRECATED);
    class_exists('Service_Prl208_EventBus', true);
    error_reporting($prev_reporting);
}

/**
 * Класс управления событиями (Event Dispatcher).
 * Проксирует вызовы в Service_Prl208_EventBus.
 */
class Event {

    /**
     * Получить инстанс шины событий с подавлением E_DEPRECATED для устаревших модулей в PHP 8.4.
     *
     * @return Service_Prl208_EventBus
     */
    protected static function bus()
    {
        if ( ! class_exists('Service_Prl208_EventBus', false))
        {
            $prev_reporting = error_reporting();
            error_reporting($prev_reporting & ~E_DEPRECATED);
            class_exists('Service_Prl208_EventBus', true);
            error_reporting($prev_reporting);
        }

        return Service_Prl208_EventBus::instance();
    }

    /**
     * Подписаться на событие.
     *
     * @param string $name Название события
     * @param callable $callback Функция или метод
     */
    public static function listen($name, $callback)
    {
        self::bus()->listen($name, $callback);
    }

    /**
     * Запустить событие немедленно.
     *
     * @param string $name Название события
     * @param mixed $data Данные события
     * @return bool
     */
    public static function fire($name, $data = NULL)
    {
        $payload = is_array($data) ? $data : array('data' => $data);
        // Автоматически подтягиваем текущего пользователя, если он авторизован
        $user = Auth::instance()->get_user();
        $initiator_id = $user ? $user->id : NULL;

        return self::bus()->publish($name, $payload, $initiator_id);
    }

    /**
     * Отложить выполнение (сейчас работает как fire).
     *
     * @param string $name
     * @param mixed $data
     */
    public static function queue($name, $data = NULL)
    {
        return self::fire($name, $data);
    }

    /**
     * Проверить наличие слушателей.
     *
     * @param string $name
     * @return bool
     */
    public static function has($name)
    {
        return self::bus()->has_listeners($name);
    }

    /**
     * Удалить всех слушателей конкретного события.
     *
     * @param string $name
     */
    public static function forget($name)
    {
        self::bus()->forget($name);
    }
}
