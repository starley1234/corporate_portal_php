<?php defined('SYSPATH') or die('No direct script access.');

/**
 * CLI: bin/console prl393:escalate
 *
 * Для cron: проверяет ближайшие сроки и пишет эскалации T−24/T−4/overdue.
 */
class Task_Prl393_Escalate extends Minion_Task
{
    protected $_options = [];

    protected function _execute(array $params)
    {
        $n = Service_Prl393_Workflow::maybe_escalate();
        Minion_CLI::write('Новых эскалаций: '.$n);
    }
}
