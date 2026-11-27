<?php defined('SYSPATH') or die('No direct script access.');

/**
 * bin/console prx400:purge — обслуживание prx400 по расписанию (манифест
 * schedule.specflow_purge): сверка зависших разборов с очередью, срок
 * хранения, осиротевшие файлы. Ошибки — ненулевой код выхода.
 */
class Task_Prx400_Purge extends Minion_Task
{
    protected function _execute(array $params)
    {
        $result = (new Service_Prx400_Pipeline)->maintain();
        Minion_CLI::write(json_encode($result, JSON_THROW_ON_ERROR));
        if ($result['errors']) throw new Minion_Exception('prx400: обслуживание завершилось с ошибками');
    }
}
