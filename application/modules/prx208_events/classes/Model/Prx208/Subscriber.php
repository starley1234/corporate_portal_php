<?php defined('SYSPATH') or die('No direct script access.');

/**
 * @package Prx208
 * @version 1.0.0
 * @author AI
 */
class Model_Prx208_Subscriber extends ORM {

    protected $_table_name = 'prx_208_event_subscribers';
    protected $_primary_key = 'id';

    protected $_table_columns = array(
        'id'              => array('type' => 'int'),
        'event_name'      => array('type' => 'string'),
        'module_id'       => array('type' => 'string'),
        'callback_method' => array('type' => 'string'),
        'is_active'       => array('type' => 'boolean'),
        'is_notification' => array('type' => 'boolean'),
        'description' => array('type' => 'string'),
        'created_at'      => array('type' => 'timestamp'),
        
    );

    /** Нормализуем булевы значения до строк, которые проходят not_empty и SQL. */
    public function set($column, $value)
    {
        if (in_array($column, array('is_active', 'is_notification'), TRUE))
        {
            if (in_array($value, array(TRUE, 1, '1', 't', 'true'), TRUE))
            {
                $value = '1';
            }
            elseif (in_array($value, array(FALSE, 0, '0', 'f', 'false'), TRUE))
            {
                $value = '0';
            }
        }

        return parent::set($column, $value);
    }

    /** PostgreSQL возвращает BOOLEAN как строки 't'/'f'; 'f' в PHP истинно. */
    public function get($column)
    {
        $value = parent::get($column);
        if (in_array($column, array('is_active', 'is_notification'), TRUE) AND $value !== NULL)
        {
            return in_array($value, array(TRUE, 1, '1', 't', 'true'), TRUE);
        }

        return $value;
    }

    /**
     * Определяет связи "один ко многим" (has many).
     * В данной модели нет прямых связей "один ко многим" с другими моделями.
     * Подписчик сам по себе является конечной сущностью, описывающей подписку.
     * Если бы, например, у подписчика были "логи вызовов", то это было бы has_many.
     */
    protected $_has_many = array(
        // 'call_logs' => array(
        //     'model'       => 'Prx208_SubscriberCallLog', // Пример: если бы была модель для логов вызовов подписчика
        //     'foreign_key' => 'subscriber_id',
        // ),
    );

    /**
     * Определяет связи "многие к одному" (belongs to).
     * Подписчик не принадлежит напрямую к какой-либо другой модели в контексте ORM.
     * `module_id` является строковым идентификатором модуля, а не внешним ключом к таблице модулей.
     * `event_name` является строковым названием события, а не внешним ключом к таблице событий.
     */
    protected $_belongs_to = array(
        // 'event' => array(
        //     'model'       => 'Prx208_Event', // Если бы event_name был внешним ключом к таблице prx_208_events
        //     'foreign_key' => 'event_name',
        // ),
        // 'module' => array(
        //     'model'       => 'Prx_Module', // Если бы module_id был внешним ключом к таблице модулей
        //     'foreign_key' => 'module_id',
        // ),
    );

    /**
     * Правила валидации для полей модели.
     *
     * @return array
     */
    public function rules()
    {
        return array(
            'event_name' => array(
                array('not_empty'),
                array('max_length', array(':value', 255)),
                // То же правило, что и в Event::publish(): подписка на другое имя никогда не сработает.
                array('regex', array(':value', Event::NAME_PATTERN)),
            ),
            'module_id' => array(
                array('max_length', array(':value', 50)),
            ),
            'callback_method' => array(
                array('max_length', array(':value', 255)),
            ),
            'is_active' => array(
                array('not_empty'),
                array('in_array', array(':value', array('0', '1', 't', 'f'), TRUE)),
            ),
            'is_notification' => array(
                array('in_array', array(':value', array('0', '1', 't', 'f'), TRUE)),
            ),
            'description' => array(
                array('max_length', array(':value', 255)),
            ),
        );
    }

    /**
     * Метки полей для использования в сообщениях об ошибках.
     *
     * @return array
     */
    public function labels()
    {
        return array(
            'event_name'      => __('Event Name'),
            'module_id'       => __('Module ID'),
            'callback_method' => __('Callback Method'),
            'is_active'       => __('Is Active'),
        );
    }

    /**
     * Проверяет, существует ли уже подписка с такими же event_name, module_id и callback_method.
     *
     * @param string $event_name
     * @param string $module_id
     * @param string $callback_method
     * @return bool
     */
    public function is_duplicate($event_name, $module_id, $callback_method)
    {
        return (bool) DB::select(array(DB::expr('COUNT(*)'), 'total_count'))
            ->from($this->_table_name)
            ->where('event_name', '=', $event_name)
            ->and_where('module_id', '=', $module_id)
            ->and_where('callback_method', '=', $callback_method)
            ->execute($this->_db)
            ->get('total_count');
    }
}