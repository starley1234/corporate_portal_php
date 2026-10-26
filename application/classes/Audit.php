<?php defined('SYSPATH') OR die('No direct script access.');

class Audit {

    public static function log($category, $action = NULL, $object_id = NULL, $data = NULL)
    {
        // Это заглушка. В реальной системе здесь будет логика записи в БД или файл.
        // Например:
        // ORM::factory('Audit_Log')->values([
        //     'category' => $category,
        //     'action' => $action,
        //     'object_id' => $object_id,
        //     'data' => json_encode($data),
        //     'user_id' => Auth::instance()->logged_in() ? Auth::instance()->get_user()->id : NULL,
        //     'timestamp' => time(),
        // ])->save();

        // Для целей тестирования или отладки можно просто выводить в лог Kohana
        // Исправлено: приводим $object_id к строке, если это массив, чтобы избежать ErrorException [ Warning ]: Array to string conversion в Kohana_Log
        Kohana::$log->add(Log::INFO, 'AUDIT: :category - :action (Object ID: :object_id, Data: :data)', [
            ':category' => (string) $category,
            ':action' => (string) $action,
            ':object_id' => is_array($object_id) ? json_encode($object_id) : (string) $object_id,
            ':data' => json_encode($data),
        ]);
    }

}