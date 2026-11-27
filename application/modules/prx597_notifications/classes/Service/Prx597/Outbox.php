<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Один проход центра уведомлений — общий для расписания и HTTP.
 *
 * Зовут: bin/console prx597:outbox (манифест schedule.notifications →
 * bin/console schedule:run) и POST /prx597/api/outbox с токеном воркера.
 * Что делает: догоняет завершённые задачи очереди, разбирает очередь доставки
 * (почта, Telegram, MAX, отложенное), рассылает сводки, при режиме «опрос»
 * забирает обновления ботов.
 */
class Service_Prx597_Outbox
{
    public static function tick(): array
    {
        $jobs   = Service_Prx597_Notify::sweep_jobs(100);
        $run    = Service_Prx597_Engine::run(50);
        $digest = Service_Prx597_Engine::flush_digests(50);

        // Старые письма, поставленные в очередь до появления движка.
        $legacy = Service_Prx597_Notify::send_digests(50, 30);

        $poll = [];
        foreach (['telegram', 'max'] as $bot)
        {
            if (Service_Prx597_Bind::mode($bot) === 'poll')
            {
                $poll[$bot] = Service_Prx597_Bind::poll($bot, 20);
            }
        }

        return [
            'ok'     => TRUE,
            'jobs'   => (int) $jobs,
            'run'    => $run,
            'digest' => $digest,
            'legacy' => ['sent' => (int) Arr::get($legacy, 'sent', 0), 'failed' => (int) Arr::get($legacy, 'failed', 0)],
            'poll'   => $poll,
        ];
    }
}
