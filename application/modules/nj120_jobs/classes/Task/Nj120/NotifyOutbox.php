<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Доставка уведомлений о завершённых задачах (outbox nj597).
 *
 * Python-воркеры в pg-режиме завершают задачи напрямую в БД, поэтому PHP-хук
 * в complete() не исполняется — уведомления доставляет это сгребание.
 * Запуск (если «Пиналки» нет или нужен более частый ритм):
 *   * * * * * /usr/bin/php /path/to/index.php --task=Nj120:NotifyOutbox
 */
class Task_Nj120_NotifyOutbox extends Minion_Task {

    protected $_options = [];

    protected function _execute(array $params)
    {
        try
        {
            $n = Service_Nj597_Notify::sweep_jobs();

            Minion_CLI::write($n > 0 ? 'Доставлено уведомлений: '.$n : 'Новых завершённых задач без уведомлений нет.');
        }
        catch (Exception $e)
        {
            Minion_CLI::write('Ошибка outbox-доставки: '.$e->getMessage());
        }
    }
}
