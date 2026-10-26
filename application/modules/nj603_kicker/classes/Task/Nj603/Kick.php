<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Пиналка» (nj603): один проход по созревшим расписаниям.
 *
 * Cron — ОДНА строка на все модули (каждые 10 минут):
 *   *\/10 * * * * /usr/bin/php /path/to/index.php --task=Nj603:Kick
 */
class Task_Nj603_Kick extends Minion_Task {

    protected $_options = [];

    protected function _execute(array $params)
    {
        try
        {
            $res = Service_Nj603_Kicker::run();

            Minion_CLI::write('Пинков поставлено: '.(int) $res['pushed']);

            foreach ((array) $res['log'] as $line)
            {
                Minion_CLI::write('  '.$line);
            }
        }
        catch (Exception $e)
        {
            Minion_CLI::write('Ошибка пиналки: '.$e->getMessage());
        }
    }
}
