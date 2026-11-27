<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx399:maintain — по расписанию prx103 (portal:maintain): сверка идущих
 * пакетов с очередью (потерянная сборка, погибшие задачи файлов и аудита) и
 * уборка осиротевших PDF в prx212.
 */
class Task_Prx399_Maintain extends Minion_Task
{
    protected function _execute(array $params)
    {
        $result = (new Service_Prx399_Contracts)->maintain();
        Minion_CLI::write(json_encode($result, JSON_THROW_ON_ERROR));
        if ($result['errors']) throw new Minion_Exception('prx399: обслуживание завершилось с ошибками');
    }
}
