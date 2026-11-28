<?php defined('SYSPATH') or die('No direct script access.');

/**
 * bin/console prx115:prune [--older_days=7] [--collection=имя] — уборка
 * векторов удалённых записей по реестру источников (config/prx115.php
 * sources): вектор, чьей записи больше нет в SQL источника и который не
 * обновлялся older_days дней, удаляется. Запускается по расписанию
 * (манифест schedule.semantic_prune) — модулям не нужно помнить о
 * delete_entity() при каждом удалении, а «Убрать мусор» на /prx115
 * остаётся для ручной уборки сразу.
 */
class Task_Prx115_Prune extends Minion_Task
{
    protected $_options = [
        'older_days' => 7,
        'collection' => NULL,
    ];

    public function build_validation(Validation $validation)
    {
        return parent::build_validation($validation)
            ->rule('older_days', 'digit');
    }

    protected function _execute(array $params)
    {
        $collection = trim((string) ($params['collection'] ?? ''));
        $deleted    = Service_Prx115_Semantic::prune($collection !== '' ? $collection : NULL,
            (int) ($params['older_days'] ?? 7));

        Minion_CLI::write(json_encode(['deleted' => $deleted], JSON_THROW_ON_ERROR));
    }
}
