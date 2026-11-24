<?php defined('SYSPATH') or die('No direct script access.');

class Task_Prx110_Publication_Backfill extends Minion_Task
{
    protected $_options = ['apply' => FALSE];

    protected function _execute(array $params)
    {
        $apply = (string) $params['apply'] === '1';
        $report = Service_Prx110_Publication::backfill($apply);
        $protected = array_intersect(Service_Prx110_Publication::PROTECTED_MODULES,
            array_merge(array_keys($report['insert']), array_keys($report['keep'])));
        Minion_CLI::write(sprintf('would_insert=%d inserted=%d kept=%d skipped=%d protected=%d',
            count($report['insert']), count($report['inserted']), count($report['keep']), count($report['skip']), count($protected)));
        foreach ($report['insert'] as $name => $row)
        {
            $action = ! $apply ? 'would_insert' : (in_array($name, $report['inserted'], TRUE) ? 'inserted' : 'kept_concurrently');
            Minion_CLI::write($action.' '.$name.' '.$row['state'].' ('.$row['note'].')');
        }
        foreach ($report['keep'] as $name => $state)
        {
            Minion_CLI::write('kept '.$name.' '.$state);
        }
        foreach ($report['skip'] as $name)
        {
            Minion_CLI::write('skipped '.($name !== '' ? $name : '(no module_name)'));
        }
    }
}
