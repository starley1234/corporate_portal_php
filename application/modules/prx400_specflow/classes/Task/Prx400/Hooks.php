<?php defined('SYSPATH') or die('No direct script access.');

/**
 * bin/console prx400:hooks [--limit=50] — доставка webhook API-ключей
 * (prx_400_deliveries, манифест schedule.specflow_hooks): подпись HMAC,
 * повтор с отсрочкой, после max_attempts — dead.
 */
class Task_Prx400_Hooks extends Minion_Task
{
    protected $_options = ['limit' => 50];

    protected function _execute(array $params)
    {
        $report = Service_Prx400_Hooks::deliver_due(max(1, min(500, (int) $params['limit'])));
        Minion_CLI::write(json_encode($report, JSON_THROW_ON_ERROR));
    }
}
