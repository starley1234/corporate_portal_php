<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx400_specflow — маршруты.
 *
 *   /api/v1/spec-extractions(/<id>)              — внешнее API (ключ prx150 + scope prx400.extract);
 *   /prx400_specflow/worker/health|<job_id>/<file|result> — протокол воркера (X-Worker-Token);
 *   /prx400_specflow/api-docs                    — контракт API для интеграторов;
 *   /prx400_specflow/<action>(/<id>)             — UI (prx400.view / prx400.run);
 *   /prx400_specflow                             — список спецификаций + загрузка.
 */
Route::set('prx400_specflow_api', 'api/v1/spec-extractions(/<id>)', ['id' => '[1-9][0-9]{0,17}'])
    ->defaults(['controller' => 'Prx400_Api', 'action' => 'index']);

Route::set('prx400_specflow_worker_health', 'prx400_specflow/worker/health')
    ->defaults(['controller' => 'Prx400_Worker', 'action' => 'health']);
Route::set('prx400_specflow_worker', 'prx400_specflow/worker/<id>/<action>', [
    'id' => '[1-9][0-9]{0,17}', 'action' => 'file|result',
])->defaults(['controller' => 'Prx400_Worker']);

// Адрес с дефисом — отдельный маршрут: общий <action> матчит только имена действий.
Route::set('prx400_specflow_api_docs', 'prx400_specflow/api-docs')
    ->defaults(['controller' => 'Prx400_Ui', 'action' => 'api_docs']);

Route::set('prx400_specflow_ui', 'prx400_specflow/<action>(/<id>)', [
    'action' => 'spec|verify|edit|finish|launch|reupload|file|export|delete|catalog|catalog_delete|catalog_import|catalog_export|templates|template_enable|template_delete',
    'id' => '[1-9][0-9]{0,17}',
])->defaults(['controller' => 'Prx400_Ui']);

Route::set('prx400_specflow', 'prx400_specflow')
    ->defaults(['controller' => 'Prx400_Ui', 'action' => 'index']);
