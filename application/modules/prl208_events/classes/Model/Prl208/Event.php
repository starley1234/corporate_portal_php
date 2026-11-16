<?php defined('SYSPATH') or die('No direct script access.');

/**
 * @package Prl208
 * @version 1.0.2
 * @author AI
 */
class Model_Prl208_Event extends ORM {

    protected $_table_name = 'prl_208_events';
    protected $_primary_key = 'id';

    protected $_table_columns = array(
        'id'           => array('type' => 'int'),
        'event_name'   => array('type' => 'string'),
        'initiator_id' => array('type' => 'int'),
        'payload'      => array('type' => 'string'),
        'created_at'   => array('type' => 'timestamp'),
    );

    protected $_belongs_to = array(
        'initiator' => array(
            'model'       => 'User',
            'foreign_key' => 'initiator_id',
        ),
    );

    protected $_has_many = array();

    /**
     * Validation rules
     *
     * @return array
     */
    public function rules()
    {
        return array(
            'event_name' => array(
                array('not_empty'),
                array('max_length', array(':value', 255)),
            ),
            'initiator_id' => array(
                array('digit'),
            ),
        );
    }

    /**
     * Saves the current object.
     * Overrides the default save method to handle JSON encoding for payload.
     *
     * @param Validation $validation
     * @return ORM
     */
    public function save(?Validation $validation = NULL)
    {
        if (is_array($this->payload) OR is_object($this->payload))
        {
            $this->payload = json_encode($this->payload, JSON_UNESCAPED_UNICODE);
        }
        return parent::save($validation);
    }

    /**
     * Returns the payload as a decoded array.
     *
     * @return array|null
     */
    public function get_payload_array()
    {
        if ($this->payload)
        {
            $decoded = json_decode($this->payload, TRUE);
            if (json_last_error() === JSON_ERROR_NONE)
            {
                return $decoded;
            }
        }
        return NULL;
    }

    /**
     * Triggers the event again using the Event Bus.
     * Useful for "Repeat Event" functionality.
     *
     * @return mixed Result of the Event Bus trigger
     */
    public function trigger()
    {
        // Класс шины — `Service_Prl208_EventBus` (было `Prl208_EventBus`,
        // «Class not found»), метод — `publish()`, а не `trigger()`: своей
        // публикации у шины нет. Найдено линтом ссылок на классы
        // (.tools/php_class_ref_lint.py) после такого же падения в prl646.
        return Service_Prl208_EventBus::instance()->publish(
            $this->event_name,
            (array) $this->get_payload_array()
        );
    }
}