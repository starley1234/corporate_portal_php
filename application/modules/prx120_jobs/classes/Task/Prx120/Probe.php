<?php defined('SYSPATH') or die('No direct script access.');

class Task_Prx120_Probe extends Minion_Task
{
    protected $_options = ['wait' => '25'];

    protected function _execute(array $params)
    {
        [$id, $nonce] = Service_Prx120_Probe::submit();
        Minion_CLI::write('Задача #'.$id.' [echo] поставлена PHP. Монитор: /prx120/task/'.$id);
        $queue = Service_Prx120_JobQueue::instance();
        $until = hrtime(TRUE) + max(1, min(120, (int) $params['wait'])) * 1000000000;
        $last = NULL;
        do {
            $job = $queue->get($id);
            $state = $job['status'] ?? 'missing';
            if ($state !== $last) Minion_CLI::write('status='.$state.' · attempt='.(int) ($job['attempts'] ?? 0));
            if ($state === 'done' && ($job['result']['echo']['probe'] ?? NULL) === $nonce)
            {
                Minion_CLI::write('OK: ответ Python от '.($job['result']['worker'] ?? '?'));
                return;
            }
            if (in_array($state, ['failed', 'cancelled', 'missing', 'done'], TRUE))
                throw new Minion_Exception('Проверка не пройдена. Откройте /prx120/task/:id и журнал воркера.', [':id' => $id]);
            $last = $state;
            usleep(250000);
        } while (hrtime(TRUE) < $until);
        // Результат проверки нужен в мониторе, но не копим pending пробы на неработающем стенде.
        if ($last === 'pending') $queue->cancel($id);
        throw new Minion_Exception('Нет ответа Python. Если задача не была взята, сверьте БД/URL очереди, WORKER_CHANNELS (echo) и журнал запуска.');
    }
}
