<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Диагностика окружения и платформы: bin/console dev:doctor
 *
 * С 25.11.2026 проверки живут в Service_Prx103_Doctor — их же выполняет
 * веб-страница «Проверка сервера» (/prx100/selfcheck). Задача остаётся
 * CLI-обёрткой: тот же набор, но без php -l (быстрый прогон в терминале).
 *
 * Проверяет: PHP и расширения, .env и секреты, каталоги для записи,
 * соединение с PostgreSQL, миграции, таблицы очереди, покрытие модулей
 * AGENTS.md. Поломанное показывает с подсказкой как чинить.
 */
class Task_Dev_Doctor extends Minion_Task {

    protected $_options = [];

    protected function _execute(array $params)
    {
        $rows = Service_Prx103_Doctor::run();

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
