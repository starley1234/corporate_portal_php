<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * Модель роли системы PRX100.
 * Расширяет стандартную модель Auth_Role.
 */
class Model_Prx102_Role extends Model_Auth_Role {

    // Auth-таблица общая и не зависит от префикса модуля.
    protected $_table_name = 'roles';

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
        'users' => array('model' => 'Prx102_User', 'through' => 'roles_users', 'foreign_key' => 'role_id'),
    );

}