<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx400_specflow — маршруты.
 *
 *   /prx400_specflow                     — список спецификаций + загрузка;
 *   /prx400_specflow/spec/<id>           — карточка (таблица, верификация, экспорт);
 *   /prx400_specflow/file/<id>           — исходный PDF (право prx400.view);
 *   /prx400_specflow/export/<id>         — JSON для ERP;
 *   /prx400_specflow/worker/<id>/<file|result> — протокол воркера (X-Worker-Token).
 */
Route::set('prx400_specflow_worker_health', 'prx400_specflow/worker/health')
    ->defaults(['controller' => 'Prx400_Worker', 'action' => 'health']);

Route::set('prx400_specflow_worker', 'prx400_specflow/worker/<id>/<action>', [
    'id' => '[1-9][0-9]{0,17}', 'action' => 'file|result',
])->defaults(['controller' => 'Prx400_Worker']);

Route::set('prx400_specflow_spec', 'prx400_specflow/<action>(/<id>)', [
    'action' => 'spec|verify|finish|launch|file|export',
    'id' => '[1-9][0-9]{0,17}',
])->defaults(['controller' => 'Prx400_Ui', 'action' => 'spec']);

Route::set('prx400_specflow', 'prx400_specflow(/<id>)', ['id' => '[1-9][0-9]{0,17}'])
    ->defaults(['controller' => 'Prx400_Ui', 'action' => 'index']);
