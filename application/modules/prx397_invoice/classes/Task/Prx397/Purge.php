<?php defined('SYSPATH') or die('No direct script access.');

class Task_Prx397_Purge extends Minion_Task
{
    protected function _execute(array $params)
    {
        $result = (new Service_Prx397_Extraction)->purge();
        Minion_CLI::write(json_encode($result, JSON_THROW_ON_ERROR));
        if ($result['errors']) throw new Minion_Exception('prx397: остались неудалённые артефакты');
    }
}
