<?php defined('SYSPATH') or die('No direct script access.');

/**
 * CLI: bin/console prl390:scan [--limit=50]
 *
 * Для cron: забирает PDF из папки входящих без участия человека.
 */
class Task_Prl390_Scan extends Minion_Task
{
    protected $_options = ['limit' => 50];

    protected function _execute(array $params)
    {
        $actor = ['user_id' => 0, 'roles' => ['system'], 'is_system' => TRUE];
        $report = Service_Prl390_Intake::scan_folder($actor, (int) $params['limit']);

        Minion_CLI::write('Папка: '.Service_Prl390_Intake::path('inbox_dir'));
        Minion_CLI::write('Найдено: '.$report['found'].', принято: '.$report['accepted']
            .', дублей: '.$report['duplicates'].', ошибок: '.$report['errors']);

        foreach ($report['items'] as $item)
        {
            Minion_CLI::write('  '.$item['file'].' — '.$item['state']
                .(isset($item['message_id']) ? ' (входящий #'.$item['message_id'].')' : '')
                .(isset($item['error']) ? ': '.$item['error'] : ''));
        }
    }
}
