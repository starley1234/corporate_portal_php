<?php defined('SYSPATH') or die('No direct script access.');

/**
 * CLI: bin/console prx300:import --file=/path/employees.csv --source=hris [--apply=1]
 *
 * Локальный CLI считается доверенным оператором портала. Без --apply файл
 * только staged/validated; применение остаётся отдельным явным действием.
 */
class Task_Prx300_Import extends Minion_Task
{
    protected $_options = ['file' => NULL, 'source' => 'hris', 'apply' => NULL];

    protected function _execute(array $params)
    {
        $file = (string) ($params['file'] ?? '');
        if ($file === '')
        {
            throw new InvalidArgumentException('Укажите --file=/path/employees.csv');
        }

        $actor = [
            'user_id' => 0,
            'roles' => ['admin'],
            'can_admin_view' => TRUE,
            'can_manage' => TRUE,
            'can_import' => TRUE,
            'can_identity' => TRUE,
            'can_directory' => TRUE,
        ];
        $rows = Service_Prx300_Import::parse_csv_file($file);
        $batch_id = Service_Prx300_Import::stage_rows(
            $actor,
            (string) ($params['source'] ?? 'hris'),
            $rows,
            basename($file),
            hash_file('sha256', $file)
        );
        $batch = Service_Prx300_Import::batch($actor, $batch_id, FALSE);
        Minion_CLI::write('Пакет #'.$batch_id.': '.$batch['status']
            .', строк '.$batch['total_rows'].', ошибок '.$batch['error_rows']
            .', пропущено устаревших '.$batch['skipped_count'].'.');

        if ( ! empty($params['apply']))
        {
            $batch = Service_Prx300_Import::apply_batch($actor, $batch_id);
            Minion_CLI::write('Применён: создано '.$batch['created_count']
                .', обновлено '.$batch['updated_count'].', пропущено '.$batch['skipped_count'].'.');
        }
        else
        {
            Minion_CLI::write('Не применён: добавьте --apply=1 или примените пакет в /prx300/admin/import/'.$batch_id.'.');
        }
    }
}
