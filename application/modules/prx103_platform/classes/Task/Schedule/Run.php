<?php defined('SYSPATH') or die('No direct script access.');

/**
 * bin/console schedule:run — исполнить расписания манифестов, которым пора.
 *
 * Ставится ОДНОЙ строкой cron (или сервисом `scheduler` в docker-compose):
 *
 *     * * * * *  cd /var/www && bin/console schedule:run
 *
 *   --only=<ключ>   только эту запись (например, --only=specflow_purge)
 *   --force=1       не ждать срока every (ручной запуск)
 *
 * Код выхода ненулевой, если хоть одна исполненная запись упала или
 * превысила таймаут: cron пришлёт письмо, systemd отметит сбой.
 * Подробности — Service_Prx103_Schedule; состояние — bin/console schedule:list.
 */
class Task_Schedule_Run extends Minion_Task
{
    protected $_options = ['only' => NULL, 'force' => NULL];

    protected function _execute(array $params)
    {
        $only = trim((string) $params['only']) !== '' ? trim((string) $params['only']) : NULL;
        $report = (new Service_Prx103_Schedule)->run_due($only, (bool) $params['force']);

        $failed = 0;
        foreach ($report as $key => $row)
        {
            if ($row['result'] === 'ran')
            {
                $failed += $row['status'] === 'ok' ? 0 : 1;
                Minion_CLI::write(Service_Prx103_Schedule::pad($key, 26).Service_Prx103_Schedule::pad($row['status'], 9)
                    .$row['duration_ms'].' мс, код '.($row['exit_code'] === NULL ? '—' : $row['exit_code']));
            }
            elseif ($only !== NULL OR in_array($row['result'], ['locked', 'invalid'], TRUE))
            {
                Minion_CLI::write(Service_Prx103_Schedule::pad($key, 26).$row['result'].(empty($row['problem']) ? '' : ' — '.$row['problem']));
            }
        }

        if ($only !== NULL AND ($report[$only]['result'] ?? '') === 'unknown')
        {
            throw new Minion_Exception('Расписания :key нет в манифестах (bin/console schedule:list)', [':key' => $only]);
        }
        if ($failed > 0)
        {
            throw new Minion_Exception('schedule:run: упало записей — :n (bin/console schedule:list)', [':n' => $failed]);
        }
    }
}
