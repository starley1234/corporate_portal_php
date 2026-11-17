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
        
        $this->_load_subscribers_from_db();
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
                catch (Exception $e)
                {
                    $this->_log(Log::ERROR, 'Listener Error in ":event_name": :error', array(
                        ':event_name' => $event_name,
                        ':error'      => $e->getMessage()
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
                ->where('is_active', '=', 1)
                ->find_all();

            foreach ($subscribers as $subscriber)
            {
                // Если это уведомление, можно добавить специальную логику проксирования
                if ($subscriber->is_notification) {
                    $this->listen($subscriber->event_name, array($this, '_bridge_to_prx597'));
                }

                if ($subscriber->callback_method) {
                    $callback = $this->_create_callable_from_string($subscriber->callback_method);
                    if ($callback !== NULL)
                    {
                        $this->listen($subscriber->event_name, $callback);
                    }
                }
            }
        }
        catch (Exception $e)
        {
            $this->_log(Log::ERROR, 'Failed to load DB subscribers: :error', array(':error' => $e->getMessage()));
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
     * События, которых нет в реестре prx597, движок положит в ленту с кодом
     * `prx597.legacy.<имя>` — мост не ломается на незнакомом событии.
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

        // 3. ФИЛЬТРАЦИЯ: Убираем дубли и исключаем того, кто совершил действие (initiator_id)
        $targets = array_unique($targets);

        foreach ($targets as $user_id) {
            // ГЛАВНОЕ ПРАВИЛО: Не уведомляем инициатора о его собственных действиях
            if ($initiator_id !== NULL && (int)$user_id === (int)$initiator_id) {
                // Если в событии нет флага "принудительно", пропускаем
                if (empty($payload['force_notification'])) {
                    continue; 
                }
            }

            // 4. Публикация в центр уведомлений: получателя передаём явно,
            // дальше prx597 сам решит, кому и по каким каналам доставлять.
            Service_Prx597_Notify::emit($event_name, (array) $payload, array(
                'user_id' => (int) $user_id,
            ));
        }
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
            return (class_exists($class) && method_exists($class, $method)) ? array($class, $method) : NULL;
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
                        return method_exists($obj, $method) ? array($obj, $method) : NULL;
                    } else {
                        $this->_log(Log::ERROR, 'Cannot instantiate class :class for callback :method: constructor requires parameters', array(
                            ':class' => $class,
                            ':method' => $method
                        ));
                        return NULL;
                    }
                } catch (Exception $e) {
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