<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Обслуживание очереди: вернуть в ожидание задачи, на которых воркер
 * перестал сообщать heartbeat дольше timeout_sec.
 *
 * Запуск — манифест schedule.maintenance, исполняет bin/console schedule:run
 * (prx103). Вручную: bin/console Prx120:RequeueStale (имя чувствительно к регистру).
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
            // Ненулевой код выхода: schedule:run и cron должны видеть сбой,
            // иначе «обслуживание идёт» при мёртвом requeue_stale.
            throw new Minion_Exception('Ошибка обслуживания очереди: :msg', [':msg' => $e->getMessage()], 0, $e);
        }
    }
}
