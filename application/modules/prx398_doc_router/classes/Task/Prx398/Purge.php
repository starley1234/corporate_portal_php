<?php defined('SYSPATH') or die('No direct script access.');

class Task_Prx398_Purge extends Minion_Task
{
    protected function _execute(array $params)
    {
        $result = (new Service_Prx398_Route)->purge();
        Minion_CLI::write(json_encode($result, JSON_THROW_ON_ERROR));
        if ($result['errors']) throw new Minion_Exception('prx398: остались неудалённые артефакты');
    }
}
