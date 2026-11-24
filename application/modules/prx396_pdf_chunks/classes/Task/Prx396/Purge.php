<?php defined('SYSPATH') or die('No direct script access.');

class Task_Prx396_Purge extends Minion_Task
{
    protected function _execute(array $params)
    {
        $result = (new Service_Prx396_Extraction)->purge();
        Minion_CLI::write(json_encode($result, JSON_THROW_ON_ERROR));
        if ($result['errors']) throw new Minion_Exception('prx396: остались неудалённые артефакты');
    }
}
