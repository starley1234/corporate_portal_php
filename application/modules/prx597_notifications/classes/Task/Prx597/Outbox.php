<?php defined('SYSPATH') or die('No direct script access.');

/**
 * bin/console prx597:outbox — один проход центра уведомлений по расписанию
 * (манифест schedule.notifications, исполняет bin/console schedule:run).
 * Тот же проход, что POST /prx597/api/outbox, но без токена и HTTP.
 */
class Task_Prx597_Outbox extends Minion_Task
{
    protected function _execute(array $params)
    {
        Minion_CLI::write(json_encode(Service_Prx597_Outbox::tick(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
