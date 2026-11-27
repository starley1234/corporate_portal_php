<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Справочник НСИ prx400 из CLI (первичная загрузка, выгрузка из 1С по cron):
 *
 *   bin/console prx400:catalog --import=/path/nsi.xlsx            — проверка, ничего не пишет
 *   bin/console prx400:catalog --import=/path/nsi.xlsx --apply=1  — применить
 *   bin/console prx400:catalog --export=/path/nsi.csv             — выгрузить справочник
 *
 * Правила импорта — Service_Prx400_CatalogImport (те же, что у формы в UI).
 * Ошибки в файле — ненулевой код выхода и номера строк в отчёте.
 */
class Task_Prx400_Catalog extends Minion_Task
{
    protected $_options = [
        'import' => NULL,
        'export' => NULL,
        'apply'  => '0',
    ];

    protected function _execute(array $params)
    {
        $import = trim((string) Arr::get($params, 'import'));
        $export = trim((string) Arr::get($params, 'export'));
        if (($import === '') === ($export === ''))
        {
            throw new Minion_Exception('prx400:catalog: укажите ровно одно из --import=ФАЙЛ или --export=ФАЙЛ');
        }

        if ($export !== '')
        {
            if (file_put_contents($export, Service_Prx400_CatalogImport::export_csv(), LOCK_EX) === FALSE)
            {
                throw new Minion_Exception('prx400:catalog: не удалось записать '.$export);
            }
            Minion_CLI::write(json_encode(['exported' => Model_Prx400_Catalog::count(), 'file' => $export], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return;
        }

        $apply = in_array((string) Arr::get($params, 'apply'), ['1', 'true', 'yes'], TRUE);
        try {
            $report = Service_Prx400_CatalogImport::run($import, $apply);
        } catch (Prx400_Error $e) {
            throw new Minion_Exception('prx400:catalog: '.$e->getMessage().' — '.Prx400_Messages::text($e->getMessage()));
        }
        Minion_CLI::write(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($report['error_count'] > 0) throw new Minion_Exception('prx400:catalog: в файле ошибки ('.$report['error_count'].') — импорт не применён');
    }
}
