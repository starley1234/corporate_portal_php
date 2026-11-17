<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Обслуживание очереди: вернуть в ожидание задачи, на которых воркер
 * перестал сообщать heartbeat дольше timeout_sec.
 *
 * Запуск по cron:
 *   * * * * * /usr/bin/php /path/to/index.php --task=Prx120:RequeueStale
 */
class Task_Prx120_RequeueStale extends Minion_Task {

    protected $_options = [];

    protected function _execute(array $params)
    {
        try
        {
            $count = Service_Prx120_JobQueue::instance()->requeue_stale();

            Minion_CLI::write($count > 0
                ? "Возвращено в очередь зависших задач: {$count}"
                : 'Зависших задач нет.');
        }
        catch (Exception $e)
        {
            Minion_CLI::write('Ошибка обслуживания очереди: '.$e->getMessage());
        }
    }
}
