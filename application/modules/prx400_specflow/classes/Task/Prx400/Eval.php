<?php defined('SYSPATH') or die('No direct script access.');

/**
 * bin/console prx400:eval [--days=30] — качество разбора в бою по журналу:
 *  - исходы спецификаций (review/done/failed с кодами);
 *  - доля строк, получивших код НСИ без человека, и откуда код (обозначение,
 *    память сопоставлений, нечёткий матч, вручную);
 *  - сколько автокодов оператор заменил (ошибки матчинга) и какие поля
 *    правит чаще всего (ошибки распознавания) — Model_Prx400_Edit;
 *  - кандидаты в НСИ: позиции без кода, повторяющиеся в разных спецификациях.
 * То же — на странице /prx400_specflow/quality.
 *
 * Качество самого парсера на размеченном корпусе — отдельно, в воркере:
 * python3 -m prx_workers.handlers.prx400_specflow.evaluate <корпус>.
 */
class Task_Prx400_Eval extends Minion_Task
{
    protected $_options = ['days' => 30];

    protected function _execute(array $params)
    {
        $days = max(1, min(3650, (int) $params['days']));
        Minion_CLI::write(json_encode(Service_Prx400_Quality::report($days), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
