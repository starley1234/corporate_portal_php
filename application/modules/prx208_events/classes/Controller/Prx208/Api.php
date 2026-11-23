<?php defined('SYSPATH') or die('No direct script access.');

/**
 * @package Prx208
 * @version 1.0.0
 * @author AI
 */
class Controller_Prx208_Api extends Controller_Base {

    /**
     * @var bool Отключаем автоматический рендеринг шаблона для API-контроллера.
     */
    public $auto_render = FALSE;

    /**
     * Инициализация контроллера.
     */
    public function before()
    {
        parent::before();

        // Устанавливаем тип контента для всех ответов API
        $this->response->headers('Content-Type', 'application/json; charset=' . Kohana::$charset);
    }

    /** Инициатор события: id авторизованного пользователя или NULL (система). */
    protected function _initiator_id(): ?int
    {
        return $this->_current_user ? (int) $this->_current_user->id : NULL;
    }

    /**
     * Обрабатывает POST-запрос для публикации нового события.
     *
     * POST-параметры:
     * - event_name (string, required): Название события (например, 'prx502.task_done').
     * - payload (json string, optional): JSON-строка с данными события.
     *
     * @return void
     */
    public function action_publish_event()
    {
        if ( ! $this->can('prx208_events.publish_event'))
        {
            $this->response->status(403);
            $this->response->body(json_encode(array(
                'status'  => 'error',
                'message' => 'Доступ запрещен. У пользователя нет прав на публикацию событий.'
            )));
            return;
        }

        if ( ! $this->_require_post_csrf())
        {
            return;
        }

        if ($post = $this->request->post())
        {
            if ((isset($post['event_name']) AND ! is_string($post['event_name']))
                OR (isset($post['payload']) AND ! is_string($post['payload'])))
            {
                $this->response->status(400);
                $this->response->body(json_encode(array('status' => 'error', 'message' => 'Некорректные поля события.')));
                return;
            }
            $event_name = isset($post['event_name']) ? trim($post['event_name']) : NULL;
            $payload_raw = isset($post['payload']) ? (string) $post['payload'] : '{}';

            if (empty($event_name))
            {
                $this->response->status(400);
                $this->response->body(json_encode(array(
                    'status'  => 'error',
                    'message' => 'Название события обязательно.'
                )));
                return;
            }

            $payload = json_decode($payload_raw, TRUE);
            if (json_last_error() !== JSON_ERROR_NONE OR ! is_array($payload))
            {
                $this->response->status(400);
                $this->response->body(json_encode(array(
                    'status'  => 'error',
                    'message' => 'Данные события должны быть JSON-объектом или массивом.'
                )));
                return;
            }

            try
            {
                // Тот же контракт, что у кода модулей: имя prxNNN.entity.action и
                // скалярный payload проверяет Event::publish(); ID записи журнала
                // шина не возвращает.
                $initiator_id = $this->_initiator_id();
                Event::publish($event_name, $payload, $initiator_id);

                $this->response->status(200);
                $this->response->body(json_encode(array(
                    'status'    => 'success',
                    'message'   => 'Событие опубликовано; выполнение обработчиков проверяется отдельно.',
                    'event_name' => $event_name,
                    'event_id'  => NULL
                )));
                Event::publish('prx208.event.published', array('event_name' => $event_name), $initiator_id);
            }
            catch (InvalidArgumentException $e)
            {
                $this->response->status(400);
                $this->response->body(json_encode(array('status' => 'error', 'message' => $e->getMessage())));
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::ERROR, 'prx208_diag stage=api_publish result=error error_type=:type', array(
                    ':type' => get_class($e),
                ));
                $this->response->status(500);
                $this->response->body(json_encode(array(
                    'status'  => 'error',
                    'message' => 'Внутренняя ошибка при публикации события.'
                )));
            }
        }
        else
        {
            $this->response->status(405); // Method Not Allowed
            $this->response->body(json_encode(array(
                'status'  => 'error',
                'message' => 'Для этой конечной точки разрешены только POST-запросы.'
            )));
        }
    }

    /**
     * Возвращает список событий в формате JSON.
     *
     * GET-параметры:
     * - page (int, optional): Номер страницы для пагинации, по умолчанию 1.
     * - limit (int, optional): Количество событий на странице, по умолчанию 10.
     * - event_name (string, optional): Фильтр по названию события.
     * - start_date (string, optional): Фильтр по дате начала (YYYY-MM-DD HH:MM:SS).
     * - end_date (string, optional): Фильтр по дате окончания (YYYY-MM-DD HH:MM:SS).
     *
     * @return void
     */
    public function action_get_events()
    {
        if ( ! $this->can('prx208_events.view_events'))
        {
            $this->response->status(403);
            $this->response->body(json_encode(array(
                'status'  => 'error',
                'message' => 'Доступ запрещен. У пользователя нет прав на просмотр событий.'
            )));
            return;
        }

        $query_params = $this->request->query();

        $page = isset($query_params['page']) ? (int) $query_params['page'] : 1;
        $limit = isset($query_params['limit']) ? (int) $query_params['limit'] : 10;
        $event_name_filter = isset($query_params['event_name']) ? (string) $query_params['event_name'] : NULL;
        $start_date_filter = isset($query_params['start_date']) ? (string) $query_params['start_date'] : NULL;
        $end_date_filter = isset($query_params['end_date']) ? (string) $query_params['end_date'] : NULL;

        // Убедимся, что page и limit положительны
        $page = max(1, $page);
        $limit = max(1, $limit);

        try
        {
            $event_model = ORM::factory('Prx208_Event');

            if ( ! empty($event_name_filter))
            {
                $event_model->where('event_name', 'LIKE', '%' . $event_name_filter . '%');
            }
            if ( ! empty($start_date_filter))
            {
                $event_model->where('created_at', '>=', $start_date_filter);
            }
            if ( ! empty($end_date_filter))
            {
                $event_model->where('created_at', '<=', $end_date_filter);
            }

            $total_events = (clone $event_model)->count_all();

            $events = $event_model
                ->order_by('created_at', 'DESC')
                ->limit($limit)
                ->offset(($page - 1) * $limit)
                ->find_all();

            $data = array();
            foreach ($events as $event)
            {
                $data[] = array(
                    'id'         => $event->id,
                    'event_name' => $event->event_name,
                    'payload'    => $event->get_payload_array(), // Декодируем payload
                    'created_at' => $event->created_at,
                );
            }

            $this->response->status(200);
            $this->response->body(json_encode(array(
                'status'       => 'success',
                'total_items'  => $total_events,
                'current_page' => $page,
                'per_page'     => $limit,
                'total_pages'  => ceil($total_events / $limit),
                'data'         => $data
            )));
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'Ошибка получения списка событий через API: :error', array(':error' => $e->getMessage()));
            $this->response->status(500);
            $this->response->body(json_encode(array(
                'status'  => 'error',
                'message' => 'Внутренняя ошибка при получении списка событий.'
            )));
        }
    }

    /**
     * Возвращает детальную информацию о конкретном событии в формате JSON.
     *
     * URL-параметры:
     * - id (int, required): ID события.
     *
     * @return void
     */
    public function action_get_event()
    {
        if ( ! $this->can('prx208_events.view_event'))
        {
            $this->response->status(403);
            $this->response->body(json_encode(array(
                'status'  => 'error',
                'message' => 'Доступ запрещен. У пользователя нет прав на просмотр события.'
            )));
            return;
        }

        $event_id = (int) $this->request->param('id');

        if (empty($event_id))
        {
            $this->response->status(400);
            $this->response->body(json_encode(array(
                'status'  => 'error',
                'message' => 'Идентификатор события обязателен.'
            )));
            return;
        }

        try
        {
            $event = ORM::factory('Prx208_Event', $event_id);

            if ( ! $event->loaded())
            {
                $this->response->status(404);
                $this->response->body(json_encode(array(
                    'status'  => 'error',
                    'message' => 'Событие не найдено.'
                )));
                return;
            }

            $this->response->status(200);
            $this->response->body(json_encode(array(
                'status'     => 'success',
                'data'       => array(
                    'id'         => $event->id,
                    'event_name' => $event->event_name,
                    'payload'    => $event->get_payload_array(), // Декодируем payload
                    'created_at' => $event->created_at,
                )
            )));
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'Ошибка получения события :id через API: :error', array(
                ':id'    => $event_id,
                ':error' => $e->getMessage()
            ));
            $this->response->status(500);
            $this->response->body(json_encode(array(
                'status'  => 'error',
                'message' => 'Внутренняя ошибка при получении события.'
            )));
        }
    }

    /**
     * Регистрирует новую подписку на событие.
     *
     * POST-параметры:
     * - event_name (string, required): Название события, на которое подписываемся.
     * - module_id (string, required): Идентификатор модуля-подписчика.
     * - callback_method (string, required): Метод или функция, которая будет вызвана.
     * - is_active (bool, optional): Активна ли подписка, по умолчанию TRUE.
     *
     * @return void
     */
    public function action_subscribe()
    {
        if ( ! $this->can('prx208_events.manage_subscriptions'))
        {
            $this->response->status(403);
            $this->response->body(json_encode(array(
                'status'  => 'error',
                'message' => 'Доступ запрещен. У пользователя нет прав на управление подписками.'
            )));
            return;
        }

        if ( ! $this->_require_post_csrf())
        {
            return;
        }

        if ($post = $this->request->post())
        {
            foreach (array('event_name', 'module_id', 'callback_method') as $field)
            {
                if (isset($post[$field]) AND ! is_string($post[$field]))
                {
                    $this->response->status(400);
                    $this->response->body(json_encode(array('status' => 'error', 'message' => 'Некорректное поле подписки.')));
                    return;
                }
            }
            $event_name      = isset($post['event_name']) ? trim($post['event_name']) : NULL;
            $module_id       = isset($post['module_id']) ? trim($post['module_id']) : NULL;
            $callback_method = isset($post['callback_method']) ? trim($post['callback_method']) : NULL;
            $is_active       = $this->_boolean_post($post, 'is_active', TRUE);
            if ($is_active === NULL)
            {
                $this->response->status(400);
                $this->response->body(json_encode(array('status' => 'error', 'message' => 'Некорректный флаг активности.')));
                return;
            }

            if (empty($event_name) || empty($module_id) || empty($callback_method))
            {
                $this->response->status(400);
                $this->response->body(json_encode(array(
                    'status'  => 'error',
                    'message' => 'Название события, идентификатор модуля и метод обратного вызова обязательны.'
                )));
                return;
            }

            $initiator_id = $this->_initiator_id();

            try
            {
                $subscriber = ORM::factory('Prx208_Subscriber');

                // Проверяем на дубликат
                if ($subscriber->is_duplicate($event_name, $module_id, $callback_method))
                {
                    $this->response->status(409); // Conflict
                    $this->response->body(json_encode(array(
                        'status'  => 'error',
                        'message' => 'Подписка с такими параметрами уже существует.'
                    )));
                    return;
                }

                $subscriber->event_name      = $event_name;
                $subscriber->module_id       = $module_id;
                $subscriber->callback_method = $callback_method;
                $subscriber->is_active       = $is_active;
                $subscriber->save();

                $this->response->status(201); // Created
                $this->response->body(json_encode(array(
                    'status'        => 'success',
                    'message'       => 'Подписка успешно создана.',
                    'subscriber_id' => $subscriber->id
                )));
                Event::publish('prx208.subscriber.created', array('subscriber_id' => (int) $subscriber->id), $initiator_id);
            }
            catch (ORM_Validation_Exception $e)
            {
                $errors = $e->errors('models');
                $this->response->status(400);
                $this->response->body(json_encode(array(
                    'status'  => 'error',
                    'message' => 'Ошибка валидации.',
                    'errors'  => $errors
                )));
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::ERROR, 'prx208_diag stage=api_subscribe result=error error_type=:type', array(
                    ':type' => get_class($e),
                ));
                $this->response->status(500);
                $this->response->body(json_encode(array(
                    'status'  => 'error',
                    'message' => 'Внутренняя ошибка при создании подписки.'
                )));
            }
        }
        else
        {
            $this->response->status(405); // Method Not Allowed
            $this->response->body(json_encode(array(
                'status'  => 'error',
                'message' => 'Для этой конечной точки разрешены только POST-запросы.'
            )));
        }
    }

    /**
     * Отменяет подписку на событие (удаляет или деактивирует).
     *
     * POST-параметры:
     * - subscriber_id (int, required): ID подписки для отмены.
     * - deactivate_only (bool, optional): Если TRUE, подписка деактивируется, но не удаляется. По умолчанию FALSE (удаление).
     *
     * @return void
     */
    public function action_unsubscribe()
    {
        if ( ! $this->can('prx208_events.manage_subscriptions'))
        {
            $this->response->status(403);
            $this->response->body(json_encode(array(
                'status'  => 'error',
                'message' => 'Доступ запрещен. У пользователя нет прав на управление подписками.'
            )));
            return;
        }

        if ( ! $this->_require_post_csrf())
        {
            return;
        }

        if ($post = $this->request->post())
        {
            $raw_id = $post['subscriber_id'] ?? NULL;
            $subscriber_id = is_string($raw_id) && ctype_digit($raw_id) ? (int) $raw_id : 0;
            $deactivate_only = $this->_boolean_post($post, 'deactivate_only', FALSE);
            if ($deactivate_only === NULL)
            {
                $this->response->status(400);
                $this->response->body(json_encode(array('status' => 'error', 'message' => 'Некорректный флаг деактивации.')));
                return;
            }

            if ($subscriber_id <= 0)
            {
                $this->response->status(400);
                $this->response->body(json_encode(array(
                    'status'  => 'error',
                    'message' => 'Идентификатор подписки обязателен.'
                )));
                return;
            }

            $initiator_id = $this->_initiator_id();

            try
            {
                $subscriber = ORM::factory('Prx208_Subscriber', $subscriber_id);

                if ( ! $subscriber->loaded())
                {
                    $this->response->status(404);
                    $this->response->body(json_encode(array(
                        'status'  => 'error',
                        'message' => 'Подписка не найдена.'
                    )));
                    return;
                }

                if ($deactivate_only)
                {
                    $subscriber->is_active = FALSE;
                    $subscriber->save();
                    $message = 'Подписка успешно деактивирована.';
                    Event::publish('prx208.subscriber.deactivated', array('subscriber_id' => $subscriber_id), $initiator_id);
                }
                else
                {
                    $subscriber->delete();
                    $message = 'Подписка успешно удалена.';
                    Event::publish('prx208.subscriber.deleted', array('subscriber_id' => $subscriber_id), $initiator_id);
                }

                $this->response->status(200);
                $this->response->body(json_encode(array(
                    'status'  => 'success',
                    'message' => $message
                )));
            }
            catch (Throwable $e)
            {
                Log_Plain::event(Log::ERROR, 'prx208_diag stage=api_unsubscribe result=error subscriber_id=:id error_type=:type', array(
                    ':id' => $subscriber_id, ':type' => get_class($e),
                ));
                $this->response->status(500);
                $this->response->body(json_encode(array(
                    'status'  => 'error',
                    'message' => 'Внутренняя ошибка при обработке подписки.'
                )));
            }
        }
        else
        {
            $this->response->status(405); // Method Not Allowed
            $this->response->body(json_encode(array(
                'status'  => 'error',
                'message' => 'Для этой конечной точки разрешены только POST-запросы.'
            )));
        }
    }

    /** Изменяющий API использует сессионную авторизацию, поэтому нужен CSRF. */
    protected function _require_post_csrf()
    {
        if ($this->request->method() !== Request::POST)
        {
            $this->response->status(405);
            $this->response->headers('Allow', 'POST');
            $this->response->body(json_encode(array('status' => 'error', 'message' => 'Нужен POST.')));
            return FALSE;
        }
        $csrf = $this->request->post('csrf');
        if ( ! is_string($csrf) OR ! Security::check($csrf))
        {
            $this->response->status(403);
            $this->response->body(json_encode(array('status' => 'error', 'message' => 'Сессия устарела, обновите страницу.')));
            return FALSE;
        }
        return TRUE;
    }

    /** TRUE/FALSE или NULL для ошибочного значения формы. */
    protected function _boolean_post(array $post, $key, $default)
    {
        if ( ! array_key_exists($key, $post))
        {
            return $default;
        }
        if ( ! is_string($post[$key]))
        {
            return NULL;
        }
        $value = strtolower(trim($post[$key]));
        if (in_array($value, array('1', 'true', 'yes', 'on'), TRUE))
        {
            return TRUE;
        }
        if (in_array($value, array('0', 'false', 'no', 'off', ''), TRUE))
        {
            return FALSE;
        }
        return NULL;
    }
}
