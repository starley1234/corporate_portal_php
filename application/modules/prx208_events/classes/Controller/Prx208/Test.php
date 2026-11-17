<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Контроллер для тестирования функционала подписок и callback-методов Prx208_Events.
 * 
 * @package Prx208
 * @version 1.0.0
 * @author AI
 */
class Controller_Prx208_Test extends Controller_Prx208_Main {

    /**
     * Тестовый метод для инициации события.
     * Маршрут: /prx208_events/prx208test/run_test
     */
    public function action_run_test()
    {
        $event_name = $this->request->query('event') ? : 'test.event.run';
        $payload = array(
            'message' => 'Test execution at ' . date('Y-m-d H:i:s'),
            'test_id' => rand(100, 999),
            'is_notification' => TRUE,
            'target_user_id' => Auth::instance()->get_user() ? Auth::instance()->get_user()->id : 1
        );

        // Вызов через глобальный прокси-класс Event
        $result = Event::fire($event_name, $payload);

        $this->template->content = json_encode(array(
            'status' => $result ? 'success' : 'no_listeners',
            'event' => $event_name,
            'payload' => $payload,
            'instruction' => 'Чтобы протестировать обратный вызов, убедитесь, что подписчик существует в prx_208_event_subscribers с помощью callback_method: Controller_Prx208_Test::test_callback_method'
        ));
    }

    /**
     * Callback метод для тестирования подписок.
     * Должен быть указан в таблице prx_208_event_subscribers как:
     * Controller_Prx208_Test::test_callback_method
     * 
     * ВНИМАНИЕ: Для callback-методов событий необходимо использовать ТОЛЬКО public static function.
     * Использование обычных public function (методов объекта) в данном контексте крайне нежелательно и может привести к ошибкам.
     * 
     * ПОЧЕМУ:
     * 1. Проблема конструктора: Service_Prx208_EventBus при обработке "->", пытается создать экземпляр класса через "new $class()".
     *    Контроллеры Kohana (как этот) требуют передачи объектов Request и Response в конструктор. Вызов "new Controller_..." без параметров
     *    вызовет Fatal Error.
     * 2. Производительность: Статический вызов не требует создания лишних объектов в памяти при каждом срабатывании события.
     * 3. Чистота архитектуры: Обработчик события не должен зависеть от состояния конкретного экземпляра контроллера или текущего HTTP-запроса.
     * 
     * @param array $payload Данные события
     * @param string $event_name Имя события
     * @param int|null $initiator_id ID пользователя
     */
    public static function test_callback_method($payload, $event_name, $initiator_id)
    {
        // Логируем факт вызова callback-метода для проверки в логах Kohana
        Kohana::$log->add(Log::INFO, 'PRX208 TEST CALLBACK EXECUTED: Event: :event, Initiator: :user, Payload: :payload', array(
            ':event' => $event_name,
            ':user' => $initiator_id,
            ':payload' => json_encode($payload)
        ))->write();

        // Здесь можно добавить проверочную запись в БД или файл
    }
}