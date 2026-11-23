<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Единая публикация доменных событий.
 *
 *   Event::publish('prx312.claim.approved', ['claim_id' => $id], $actor->id());
 *   Event::listen('prx312.claim.approved', function ($payload, $name, $initiator_id) { ... });
 *
 * Контракт publish():
 *  - имя — `prxNNN.<entity>.<action>` (NAME_PATTERN), иначе InvalidArgumentException;
 *  - payload — только скаляры, массивы скаляров и NULL: ID вместо ORM-объектов,
 *    иначе InvalidArgumentException (журнал шины хранит JSON);
 *  - инициатор — третьим аргументом явно; сессия не читается, NULL — система.
 *
 * Проводник — Service_Prx208_EventBus (prx208_events): журнал в БД, слушатели
 * кода, DB-подписки. Модуль не подключён — publish() пишет предупреждение в
 * Kohana::$log и возвращается, не роняя запрос.
 *
 * listen()/has() — тонкие прокси шины: слушатели регистрируют в init.php модулей.
 * Подписчик получает ($payload, $event_name, $initiator_id) и сам отвечает за
 * идемпотентность; шина не гарантирует доставку.
 */
class Event {

    /** Имя события: `prxNNN.entity.action`, `prxNNN_entity.action` — тоже допустимо. */
    const NAME_PATTERN = '/^prx\d+[a-z0-9_]*\.[a-z0-9_.]+$/D';

    /**
     * Опубликовать событие.
     *
     * @param string     $name         prxNNN.entity.action
     * @param array      $payload      ID и скаляры
     * @param int|null   $initiator_id кто инициировал; NULL — система
     * @return void
     * @throws InvalidArgumentException
     */
    public static function publish(string $name, array $payload = [], ?int $initiator_id = NULL): void
    {
        self::assert_name($name);
        self::assert_payload($payload, $name);

        $bus = self::bus();
        if ($bus === NULL)
        {
            Kohana::$log->add(Log::WARNING, 'prx208_events не подключён, событие пропущено: :event', [
                ':event' => $name,
            ]);
            return;
        }

        $bus->publish($name, $payload, $initiator_id > 0 ? $initiator_id : NULL);
    }

    /**
     * Подписаться на событие: callback($payload, $event_name, $initiator_id).
     *
     * @param string   $name
     * @param callable $callback
     * @throws InvalidArgumentException
     */
    public static function listen(string $name, callable $callback): void
    {
        self::assert_name($name);

        $bus = self::bus();
        if ($bus !== NULL)
        {
            $bus->listen($name, $callback);
        }
    }

    /**
     * Есть ли слушатели события (код и DB-подписки).
     *
     * @param string $name
     * @return bool
     */
    public static function has(string $name): bool
    {
        $bus = self::bus();
        return $bus !== NULL AND $bus->has_listeners($name);
    }

    /**
     * Шина событий или NULL, если prx208_events не подключён. Загрузка класса —
     * с подавлением E_DEPRECATED: в PHP 8.4 она срабатывает при разборе старых
     * модулей, а Kohana превращает её в ErrorException.
     *
     * @return Service_Prx208_EventBus|null
     */
    protected static function bus()
    {
        if ( ! class_exists('Service_Prx208_EventBus', false))
        {
            $prev_reporting = error_reporting();
            error_reporting($prev_reporting & ~E_DEPRECATED);
            class_exists('Service_Prx208_EventBus', true);
            error_reporting($prev_reporting);
        }

        return class_exists('Service_Prx208_EventBus', false) ? Service_Prx208_EventBus::instance() : NULL;
    }

    /**
     * @param string $name
     * @throws InvalidArgumentException
     */
    protected static function assert_name(string $name): void
    {
        if ( ! preg_match(self::NAME_PATTERN, $name))
        {
            throw new InvalidArgumentException('Имя события должно быть prxNNN.entity.action: '.$name);
        }
    }

    /**
     * Рекурсивно: массивы обходим, всё кроме скаляров и NULL — ошибка.
     *
     * @param array  $payload
     * @param string $name
     * @throws InvalidArgumentException
     */
    protected static function assert_payload(array $payload, string $name): void
    {
        foreach ($payload as $key => $value)
        {
            if (is_array($value))
            {
                self::assert_payload($value, $name);
            }
            elseif ($value !== NULL AND ! is_scalar($value))
            {
                throw new InvalidArgumentException(sprintf(
                    'Payload события %s: поле %s — %s, допустимы только скаляры и ID',
                    $name, $key, gettype($value)
                ));
            }
        }
    }
}
