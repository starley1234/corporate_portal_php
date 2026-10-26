<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ProcessHub (nj670_processhub) — адреса модуля.
 *
 *   GET      /process                     витрина и список процессов
 *   GET|POST /process/create              синтез схемы по тексту
 *   GET|POST /process/ontology            справочники и разбор документов
 *   GET      /process/map                 карта организации
 *   GET      /process/admin               все рабочие пространства (nj670.admin)
 *   POST     /process/demo                загрузить демо-сценарий
 *   GET      /process/<code>              холст процесса (план)
 *   GET      /process/<code>/editor       холст, ?kind=fact — контур «Факт»
 *   GET      /process/<code>/bpmn         выгрузка BPMN 2.0 XML
 *   GET      /process/<code>/plantuml     выгрузка PlantUML (activity)
 *   GET      /process/<code>/versions     история версий и сравнение двух редакций
 *   GET|POST /process/<code>/fact         загрузка журнала и метрики факта
 *   GET|POST /process/<code>/audit        сравнение плана и факта
 *   GET|POST /process/<code>/report       просмотр и выпуск регламента
 *   POST     /process/<code>/approve      утверждение версии плана (владелец)
 *   GET      /process/r/<id>/<kind>       скачивание DOCX/PDF
 *   *        /process/api/<action>        JSON API (аккаунт + CSRF или Bearer-ключ)
 *   POST     /process/w/<action>          приёмная python-воркера (токен)
 *
 * Служебные страницы начинаются с /process, публичный адрес сервиса —
 * короткое существительное (правило §23 корневого AGENTS.md).
 */

// --- Страницы -----------------------------------------------------------------

Route::set('nj670_process_index', 'process')
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'index']);

Route::set('nj670_process_create', 'process/create')
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'create']);

Route::set('nj670_process_ontology', 'process/ontology')
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'ontology']);

Route::set('nj670_process_map', 'process/map')
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'map']);

Route::set('nj670_process_admin', 'process/admin')
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'admin']);

Route::set('nj670_process_demo', 'process/demo')
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'demo']);

Route::set('nj670_process_editor', 'process/<code>/editor', ['code' => '[23456789a-z]{8,24}'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'editor']);

Route::set('nj670_process_versions', 'process/<code>/versions', ['code' => '[23456789a-z]{8,24}'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'versions']);

Route::set('nj670_process_plantuml', 'process/<code>/plantuml', ['code' => '[23456789a-z]{8,24}'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'plantuml']);

Route::set('nj670_process_simulate', 'process/<code>/simulate', ['code' => '[23456789a-z]{8,24}'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'simulate']);

Route::set('nj670_process_passport', 'process/<code>/passport', ['code' => '[23456789a-z]{8,24}'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'passport']);

Route::set('nj670_process_bpmn', 'process/<code>/bpmn', ['code' => '[23456789a-z]{8,24}'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'bpmn']);

Route::set('nj670_process_fact', 'process/<code>/fact', ['code' => '[23456789a-z]{8,24}'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'fact']);

Route::set('nj670_process_audit', 'process/<code>/audit', ['code' => '[23456789a-z]{8,24}'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'audit']);

Route::set('nj670_process_report', 'process/<code>/report', ['code' => '[23456789a-z]{8,24}'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'report']);

Route::set('nj670_process_approve', 'process/<code>/approve', ['code' => '[23456789a-z]{8,24}'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'approve']);

Route::set('nj670_process_report_file', 'process/r/<id>/<kind>', ['id' => '[0-9]+', 'kind' => 'docx|pdf'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'report_file']);

Route::set('nj670_process_view', 'process/<code>', ['code' => '[23456789a-z]{8,24}'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Process', 'action' => 'editor']);

// --- JSON API -----------------------------------------------------------------

Route::set('nj670_process_api', 'process/api/<action>', ['action' => '[a-z_]+'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Api', 'action' => 'index']);

// --- Приёмная воркера ----------------------------------------------------------

Route::set('nj670_process_worker', 'process/w/<action>',
	['action' => 'context|graph|audit|report|fail|ping|ontology|ontology_fail'])
	->defaults(['directory' => 'Nj670', 'controller' => 'Worker', 'action' => 'index']);
