<?php defined('SYSPATH') or die('No direct script access.');

/**
 * CLI: bin/console prx390:scan [--limit=50]
 *
 * Для cron: забирает PDF из папки входящих без участия человека.
 */
class Task_Prx390_Scan extends Minion_Task
{
    protected $_options = ['limit' => 50];

    protected function _execute(array $params)
    {
        $report = Service_Prx390_Intake::scan_folder_automatically((int) $params['limit']);

        Minion_CLI::write('Папка: '.Service_Prx390_Intake::path('inbox_dir'));
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
