<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * Модель роли системы ERP100.
 * Расширяет стандартную модель Auth_Role.
 */
class Model_Erp100_Role extends Model_Auth_Role {

    /**
     * ВРУЧНУЮ прописываем колонки.
     * Теперь ORM вообще не будет опрашивать базу о структуре.
     */
    protected $_table_columns = [
        'id'          => ['type' => 'int'],
        'name'        => ['type' => 'string'],
        'description' => ['type' => 'string'],
    ];

    /**
     * @var array Связи типа "многие ко многим"
     */
    protected $_has_many = array(
        'users' => array('model' => 'Erp100_User', 'through' => 'roles_users'),
    );

}