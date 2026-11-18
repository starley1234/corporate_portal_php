<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Шина событий (Event Bus) для модуля Prx208.
 * 
 * @package Prx208
 * @version 1.0.7
 */
class Service_Prx208_EventBus {

    /**
     * @var Service_Prx208_EventBus Singleton instance
     */
    protected static $_instance;

    /**
     * @var array Зарегистрированные слушатели
     */
    protected $_listeners = array();

    /**
     * @var array Конфигурация
     */
    protected $_config;

    /**
     * Приватный конструктор.
     */
    protected function __construct()
    {
        $config_object = Kohana::$config->load('prx208_events');
        $config_array = ($config_object instanceof Kohana_Config_Group) 
            ? $config_object->as_array() 
            : (array) $config_object;

        $this->_config = isset($config_array['event_bus']) ? $config_array['event_bus'] : array();
        $this->_config['logging'] = isset($config_array['logging']) ? $config_array['logging'] : array();

        if ( ! empty($this->_config['load_db_subscribers']))
        {
            $this->_load_subscribers_from_db();
        }
    }

    /**
     * @return Service_Prx208_EventBus
     */
    public static function instance()
    {
        if (self::$_instance === NULL)
        {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Регистрация слушателя
     */
    public function listen($event_name, $callback)
    {
        if ( ! isset($this->_listeners[$event_name]))
        {
            $this->_listeners[$event_name] = array();
        }
        $this->_listeners[$event_name][] = $callback;

        $this->_log(Log::DEBUG, 'Registered listener for: :event_name', array(':event_name' => $event_name));
    }

    /**
     * Проверка наличия слушателей
     */
    public function has_listeners($event_name)
    {
        return ! empty($this->_listeners[$event_name]);
    }

    /**
     * Удаление слушателей
     */
    public function forget($event_name)
    {
        if (isset($this->_listeners[$event_name]))
        {
            unset($this->_listeners[$event_name]);
        }
    }

    /**
     * Публикация события
     * 
     * @param string $event_name
     * @param array $payload
     * @param int|null $initiator_id
     * @return bool
     */
    public function publish($event_name, array $payload = array(), $initiator_id = NULL)
    {
        $this->_log(Log::INFO, 'Publishing event: :event_name by user :user', array(
            ':event_name' => $event_name,
            ':user' => $initiator_id
        ));
		
        // 1. Логирование в БД (если включено)
        if ( ! empty($this->_config['log_published_events']))
        {
            try
            {
                $event_model = ORM::factory('Prx208_Event');
                $event_model->event_name = $event_name;
                $event_model->initiator_id = $initiator_id;
                
                // Кодируем в JSON для сохранения в текстовое поле БД
                $json_payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
                
                $event_model->values(array(
                    'payload' => $json_payload
                ));
                
                $event_model->save(NULL);
            }
            catch (Exception $e)
            {
                $this->_log(Log::ERROR, 'DB Log Error: :error', array(':error' => $e->getMessage()));
            }
        }

        // 2. Wildcard-мост в центр уведомлений (prx597) — ТОЛЬКО если включено
        // конфигом (config/prx208_events.php): по умолчанию выключено, чтобы
        // шина не плодила уведомления без ведома модуля-источника.
        if ( ! empty($this->_config['proxy_to_notification_center'])
            AND ( ! empty($payload['is_notification']) OR isset($payload['target_user_id'])))
        {
            $this->_bridge_to_prx597($payload, $event_name, $initiator_id);
        }

        // 3. Уведомление слушателей
        if (isset($this->_listeners[$event_name]))
        {
            foreach ($this->_listeners[$event_name] as $callback)
            {
                try
                {
                    // Передаем массив (payload), имя события и инициатора в callback
                    call_user_func($callback, $payload, $event_name, $initiator_id);
                }
                catch (Throwable $e)
                {
                    // Исключение обработчика может содержать данные события.
                    // Ошибка одного слушателя не должна ломать остальных.
                    Log_Plain::event(Log::ERROR, 'prx208_diag stage=listener result=error error_type=:type', array(
                        ':type' => get_class($e),
                    ));
                }
            }
            return TRUE;
        }

        return FALSE;
    }

    /**
     * Загрузка активных подписчиков из БД
     */
    protected function _load_subscribers_from_db()
    {
        try
        {
            $subscribers = ORM::factory('Prx208_Subscriber')
                ->where('is_active', '=', DB::expr('TRUE'))
                ->find_all();

            $notification_events = array();
            foreach ($subscribers as $subscriber)
            {
                // Несколько подписок одного события не должны многократно
                // отправлять одно и то же уведомление одинаковым получателям.
                if ($subscriber->is_notification AND ! isset($notification_events[$subscriber->event_name])) {
                    $notification_events[$subscriber->event_name] = TRUE;
                    $this->listen($subscriber->event_name, array($this, '_bridge_to_prx597'));
                }

                if ($subscriber->callback_method) {
                    try
                    {
                        $callback = $this->_create_callable_from_string($subscriber->callback_method);
                        if ($callback !== NULL)
                        {
                            $this->listen($subscriber->event_name, $callback);
                        }
                    }
                    catch (Throwable $e)
                    {
                        // Ошибка автозагрузки одной подписки не мешает остальным.
                        Log_Plain::event(Log::WARNING, 'prx208_diag stage=callback_init result=error error_type=:type', array(
                            ':type' => get_class($e),
                        ));
                    }
                }
            }
        }
        catch (Throwable $e)
        {
            // Сообщение БД может содержать SQL и данные; логируем только класс.
            Log_Plain::event(Log::ERROR, 'prx208_diag stage=subscribers result=error error_type=:type', array(
                ':type' => get_class($e),
            ));
        }
    }
    
    
    /**
     * Мост в центр уведомлений (prx597).
     *
     * Было: `Service_Prx217_Bus::send()` — склад в таблицы prx_217_*, которые
     * никто не показывал (модуль prx217 снесён 09.10.2026). Теперь событие
     * уходит в prx597: там реестр событий, шаблоны, лимиты и реальная доставка
     * в колокольчик, на почту и в мессенджеры.
     *
     * Неизвестное событие prx597 регистрирует на лету с нормализованным
     * именем и настройками по умолчанию; их должен проверить администратор.
     *
     * Поддерживает массивы получателей и исключает инициатора.
     *
     * @param array      $payload
     * @param string     $event_name
     * @param int|null   $initiator_id
     * @return void
     */
    protected function _bridge_to_prx597($payload, $event_name, $initiator_id)
    {
        // 1. Собираем всех потенциальных получателей в один массив
        $targets = array();

        if (isset($payload['target_user_ids']) && is_array($payload['target_user_ids'])) {
            $targets = $payload['target_user_ids'];
        } elseif (isset($payload['target_user_id'])) {
            $targets[] = $payload['target_user_id'];
        }

        // 2. Если получателей нет - выходим
        if (empty($targets)) {
            return;
        }

        if ( ! class_exists('Service_Prx597_Notify')) {
            return;   // центр уведомлений не подключён — молча, шина не виновата
        }

        // 3. Только положительные ID, без дублей и (если не разрешено явно)
        // инициатора. Массив/boolean нельзя неявно превратить в user_id=1.
        $recipients = array();
        $force_value = $payload['force_notification'] ?? FALSE;
        $force = ($force_value === TRUE || $force_value === 1
            || (is_string($force_value) && in_array(strtolower(trim($force_value)), array('1', 'true', 'yes', 'on'), TRUE)));
        foreach ($targets as $user_id)
        {
            if ( ! is_int($user_id) AND ! (is_string($user_id) AND ctype_digit($user_id)))
            {
                continue;
            }
            $user_id = (int) $user_id;
            if ($user_id <= 0 OR ( ! $force AND $initiator_id !== NULL AND $user_id === (int) $initiator_id))
            {
                continue;
            }
            $recipients[$user_id] = $user_id;
        }
        if ( ! $recipients)
        {
            return;
        }

        // prx597 трактует часть полей payload как служебные: дополнительные
        // адресаты, channels (обходит роли/матрицу события) и force_self.
        // Данные события не должны расширять рассылку или менять её политику.
        // Значения для шаблона при необходимости передаются внутри data.
        $notification = (array) $payload;
        unset($notification['user_id'], $notification['user_ids'], $notification['recipients'],
            $notification['channels'], $notification['force_self']);
        $options = array('user_ids' => array_values($recipients));
        if ($initiator_id !== NULL)
        {
            $options['initiator_id'] = (int) $initiator_id;
        }
        if ($force)
        {
            $options['force_self'] = TRUE;
        }
        Service_Prx597_Notify::emit($event_name, $notification, $options);
    }

    /**
     * Преобразование строки callback в callable
     */
    protected function _create_callable_from_string($callback_string)
    {
        // Статический метод: Class::method
        if (strpos($callback_string, '::') !== FALSE)
        {
            list($class, $method) = explode('::', $callback_string, 2);
            $callback = array($class, $method);
            // method_exists() также принимает private и нестатические методы:
            // вызов такого обработчика в PHP 8 завершился бы ошибкой.
            return (class_exists($class) && is_callable($callback)) ? $callback : NULL;
        }
        
        // Метод объекта: Class->method
        if (strpos($callback_string, '->') !== FALSE)
        {
            list($class, $method) = explode('->', $callback_string, 2);
            if (class_exists($class))
            {
                // Если это контроллер Kohana, он требует Request/Response в конструкторе.
                // Для событийной модели лучше использовать статические методы или Service-классы.
                // Пытаемся создать объект без параметров, если это возможно.
                try {
                    $reflection = new ReflectionClass($class);
                    $constructor = $reflection->getConstructor();
                    
                    // Если конструктора нет или он не требует обязательных параметров
                    if ($constructor === NULL || $constructor->getNumberOfRequiredParameters() === 0) {
                        $obj = new $class();
                        $callback = array($obj, $method);
                        return is_callable($callback) ? $callback : NULL;
                    } else {
                        $this->_log(Log::ERROR, 'Cannot instantiate class :class for callback :method: constructor requires parameters', array(
                            ':class' => $class,
                            ':method' => $method
                        ));
                        return NULL;
                    }
                } catch (Throwable $e) {
                    Log_Plain::event(Log::WARNING, 'prx208_diag stage=callback_init result=error error_type=:type', array(
                        ':type' => get_class($e),
                    ));
                    return NULL;
                }
            }
        }

        return function_exists($callback_string) ? $callback_string : NULL;
    }

    /**
     * Системное логирование
     */
    protected function _log($level, $message, array $values = NULL)
    {
        $log_cfg = isset($this->_config['logging']) ? $this->_config['logging'] : array();
        
        if ( ! empty($log_cfg['enabled']) && $level <= $log_cfg['level'])
        {
            // 'file' может отсутствовать в конфиге — дефолт; сбой лога НЕ должен
            // становиться ErrorException (урок 21.09)
            try
            {
                $log_file = APPPATH . 'logs/' . (isset($log_cfg['file']) ? $log_cfg['file'] : 'prx208_bus.log');
                Log::instance()->add($level, $message, $values)->write($log_file);
            }
            catch (Exception $e)
            {
                Kohana::$log->add($level, 'prx208 bus: :m', array(':m' => $message));
            }
        }
    }
}