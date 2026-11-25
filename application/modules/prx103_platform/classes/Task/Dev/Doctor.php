<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Диагностика окружения и платформы: bin/console dev:doctor
 *
 * С 25.11.2026 проверки живут в Service_Prx103_Doctor — их же выполняет
 * веб-страница «Проверка сервера» (/prx100/selfcheck). CLI не ограничен
 * таймаутами, поэтому компилирует весь код одним прогоном lint_chunk();
 * веб-версия гоняет те же порции из браузера по частям.
 *
 * Проверяет: PHP и расширения, .env и секреты, каталоги для записи,
 * соединение с PostgreSQL, миграции, таблицы очереди, покрытие модулей
 * AGENTS.md, синтаксис всех php-файлов. Поломанное — с подсказкой как чинить.
 */
class Task_Dev_Doctor extends Minion_Task {

    protected $_options = [];

    protected function _execute(array $params)
    {
        $rows = Service_Prx103_Doctor::run();

        // CLI не ограничен таймаутами веб-хостинга — весь список целиком
        $lint = Service_Prx103_Doctor::lint_chunk(Service_Prx103_Doctor::php_files());

        if ($lint['ok'])
        {
            if ($lint['bad'] === [])
            {
                $rows[] = ['status' => 'OK', 'group' => 'Синтаксис PHP',
                    'message' => 'php -l: '.$lint['total'].' файлов — ошибок нет'];
            }
            else
            {
                foreach ($lint['bad'] as $i => [$file, $error])
                {
                    if ($i >= Service_Prx103_Doctor::LINT_MAX_REPORTED)
                    {
                        $rows[] = ['status' => 'FAIL', 'group' => 'Синтаксис PHP',
                            'message' => '…и ещё '.(count($lint['bad']) - Service_Prx103_Doctor::LINT_MAX_REPORTED).' ошибок'];
                        break;
                    }

                    $rows[] = ['status' => 'FAIL', 'group' => 'Синтаксис PHP',
                        'message' => Service_Prx103_Doctor::lint_error_text((string) $error)];
                }
            }
        }
        else
        {
            $rows[] = ['status' => 'WARN', 'group' => 'Синтаксис PHP',
                'message' => 'CLI-интерпретатор PHP не найден или proc_open запрещён — php -l пропущен'];
        }

        $lines = ['', 'PRX — диагностика', str_repeat('-', 60)];
        foreach ($rows as $row)
        {
            $lines[] = sprintf('[%s] %s', $row['status'] === 'OK' ? ' OK ' : $row['status'], $row['message']);
        }

        $fails = count(array_filter($rows, function ($row) {
            return $row['status'] === 'FAIL';
        }));

        $lines[] = str_repeat('-', 60);
        $lines[] = $fails > 0
            ? "Проблем: $fails. Подсказки по разворачиванию — docs/ENVIRONMENT.md"
            : 'Всё критичное в порядке. Хорошей работы!';

        Minion_CLI::write(implode("\n", $lines));
    }
}
