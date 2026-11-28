<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * Фасад журнала действий администратора.
 *
 *   Audit::log('prx150', 'key_revoked', $id);
 *   Audit::log('prx595', 'user_roles_changed', $user_id, ['added' => ['admin'], 'removed' => []]);
 *
 * Кто действует — текущий пользователь сессии (CLI — «cli»). Секреты из data
 * вырезаются; запись не роняет журналируемое действие. Хранит prx103_platform
 * (точка Prx_Hooks `audit.write`, prx_103_audit_log, /prx100/audit); без prx103 — системный лог.
 */
class Audit {

    /**
     * @param string            $category  модуль/область: prx150, prx595, security…
     * @param string|array|null $action    что сделано (snake_case); массив — устаревший вызов «данные вместо действия»
     * @param mixed             $object_id над чем (id/ключ), NULL — без объекта
     * @param mixed             $data      подробности (массив); секреты вырезаются
     * @return int id записи журнала или 0
     */
    public static function log($category, $action = NULL, $object_id = NULL, $data = NULL): int
    {
        if (is_array($action))
        {
            $data   = $action;
            $action = 'event';
        }

        $data = is_array($data) ? $data : ($data === NULL ? [] : ['value' => $data]);

        // Хранилище журнала регистрирует модуль-владелец (prx103_platform, init.php):
        // ядро о нём не знает (Д2/Д7). Нет обработчика — системный лог.
        $id = Prx_Hooks::call('audit.write', (string) $category, (string) ($action ?? 'event'), $object_id, $data);
        if ($id !== NULL)
        {
            return (int) $id;
        }

        if (Kohana::$log)
        {
            Kohana::$log->add(Log::INFO, 'AUDIT: :category - :action (Object ID: :object_id, Data: :data)', [
                ':category'  => (string) $category,
                ':action'    => (string) $action,
                ':object_id' => is_array($object_id) ? json_encode($object_id) : (string) $object_id,
                ':data'      => json_encode($data, JSON_UNESCAPED_UNICODE),
            ]);
        }

        return 0;
    }

}
