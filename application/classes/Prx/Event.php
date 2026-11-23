<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Единая точка публикации доменных событий для новых модулей.
 *
 *   Prx_Event::publish('prx300.employee.linked', ['employee_id' => $id]);
 *
 * Имя — `prxNNN.entity.action`; payload — только ID и скаляры, не ORM-объекты.
 * Подписчик сам отвечает за идемпотентность; шина не гарантирует доставку.
 *
 * `Event::fire()` Kohana остаётся legacy: новые модули его не используют.
 * Если `prx208_events` не подключён, событие пропускается с предупреждением:
 * модуль не должен падать из-за отсутствия необязательной шины.
 */
final class Prx_Event
{
    public static function publish(string $name, array $payload = [], ?int $initiator_id = NULL): void
    {
        if ( ! preg_match('/^prx\d+[a-z0-9_]*\.[a-z0-9_.]+$/D', $name)) {
            throw new InvalidArgumentException('Имя события должно быть prxNNN.entity.action: '.$name);
        }

        if ( ! class_exists('Service_Prx208_EventBus')) {
            Kohana::$log->add(Log::WARNING, 'prx208_events не подключён, событие пропущено: :event', [
                ':event' => $name,
            ]);

            return;
        }

        Service_Prx208_EventBus::instance()->publish($name, $payload, $initiator_id);
    }
}
