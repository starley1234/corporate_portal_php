<?php defined('SYSPATH') or die('No direct script access.');

/** portal:maintain — один проход очереди и outbox; запускать таймером раз в минуту. */
class Task_Portal_Maintain extends Minion_Task {
    protected function _execute(array $params)
    {
        $queue = Service_Prl120_JobQueue::instance();
        $result = [
            'reconciled' => $queue->reconcile_outcomes(),
            'requeued' => $queue->requeue_stale(),
            'notifications' => Service_Prl597_Notify::sweep_jobs(100),
            'outbox' => Service_Prl597_Engine::run(50),
            'digests' => Service_Prl597_Engine::flush_digests(50),
            'legacy' => Service_Prl597_Notify::send_digests(50, 30),
        ];
        foreach (['telegram', 'max'] as $bot)
        {
            if (Service_Prl597_Bind::mode($bot) === 'poll')
            {
                $result['poll'][$bot] = Service_Prl597_Bind::poll($bot, 20);
            }
        }
        Minion_CLI::write(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
