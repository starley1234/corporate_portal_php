<?php defined('SYSPATH') or die('No direct script access.');

/**
 * bin/console schedule:list — расписания манифестов и их последний запуск.
 *
 *   --verbose=1   с хвостом вывода последнего неудачного запуска
 */
class Task_Schedule_List extends Minion_Task
{
    protected $_options = ['verbose' => NULL];

    protected function _execute(array $params)
    {
        $service = new Service_Prx103_Schedule;
        $state = $service->state();
        $row_text = static fn (array $cells): string => implode('', array_map(
            static fn ($cell, $width) => Service_Prx103_Schedule::pad((string) $cell, $width), $cells, [26, 24, 12, 10, 14, 0]));
        $lines = [$row_text(['КЛЮЧ', 'МОДУЛЬ', 'КАЖДЫЕ', 'СТАТУС', 'ЗАПУСК', 'ПРИМЕЧАНИЕ'])];

        foreach ($service->entries() as $key => $entry)
        {
            $row = $state[$key] ?? NULL;
            $status = $entry['kind'] === 'command' ? ($row['status'] ?? 'never') : $entry['kind'];
            $when = $row && $row['age_sec'] !== NULL ? Service_Prx103_Schedule::human((int) $row['age_sec']).' назад' : '—';
            $note = $entry['problem'] !== '' ? $entry['problem']
                : ($entry['kind'] === 'external' ? 'внешний пинок (url), не исполняется' : $entry['command']);
            if ($row && $row['consecutive_failures'] > 0) $note .= ' · подряд неудач: '.$row['consecutive_failures'];
            $lines[] = rtrim($row_text([$key, $entry['module'], $entry['every'], $status, $when, $note]));

            if ($params['verbose'] AND $row AND $row['status'] !== 'ok' AND (string) $row['output'] !== '')
            {
                foreach (explode("\n", (string) $row['output']) as $out) $lines[] = '    | '.$out;
            }
        }

        Minion_CLI::write(implode("\n", $lines));
    }
}
