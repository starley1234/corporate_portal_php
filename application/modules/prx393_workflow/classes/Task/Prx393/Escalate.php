<?php defined('SYSPATH') or die('No direct script access.');

/**
 * CLI: bin/console prx393:escalate
 *
 * Для cron: проверяет ближайшие сроки и пишет эскалации T−24/T−4/overdue.
 */
class Task_Prx393_Escalate extends Minion_Task
{
    protected $_options = [];

    protected function _execute(array $params)
    {
        $n = Service_Prx393_Workflow::maybe_escalate();
        Minion_CLI::write('Новых эскалаций: '.$n);
    }
}
