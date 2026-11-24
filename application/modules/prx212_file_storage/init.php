<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRX212 Storage Module Initialization
 * Абстракция хранилища: единый интерфейс для работы с файлами
 * ID модуля: 212
 */

// Регистрация роутов модуля
// Роуты соглашению: prx212_<controller>/<action>/<params>


Route::set('prx212_storage_home', 'prx212_file_storage')
    ->defaults(['directory' => 'Prx212', 'controller' => 'Index', 'action' => 'index']);

Route::set('prx212_storage_ui', 'prx212_file_storage/<action>(/<id>)')
    ->defaults(['directory' => 'Prx212', 'controller' => 'Storage']);

Route::set('prx212_storage', 'prx212_storage(/<action>(/<id>))')
    ->defaults(array(
        'directory'  => 'Prx212',
        'controller' => 'Storage',
        'action'     => 'index',
    ));    

// 2. REST API для работы с файлами (JSON)
Route::set('prx212_storage_api', 'prx212_api/<action>(/<id>)')
    ->defaults(array(
        'directory'  => 'Prx212',
        'controller' => 'Api',
    ));

// 3. Контроллер скачивания файлов (Binary Stream)
// Альтернативный роут для скачивания
Route::set('prx212_storage_download_short', 'storage/get/<id>')
    ->defaults(array(
        'directory'  => 'Prx212',
        'controller' => 'Download',
        'action'     => 'file',
    ));

Route::set('prx212_storage_download_file', 'prx212_download/file/<id>')
    ->defaults(array(
        'directory'  => 'Prx212',
        'controller' => 'Download',
        'action'     => 'file',
    ));

Route::set('prx212_storage_download_shared', 'prx212_download/shared/<token>')
    ->defaults(array(
        'directory'  => 'Prx212',
        'controller' => 'Download',
        'action'     => 'shared',
    ));

// 4. HMVC виджеты для вставки в другие модули
Route::set('prx212_storage_widget', 'prx212_widget/<action>')
    ->defaults(array(
        'directory'  => 'Prx212',
        'controller' => 'Widget',
        'action'     => 'uploader',
    ));

// Старые закладки сохраняются, но главная ведёт на канонический URL.
Route::set('prx212_storage_root', 'prx212')
    ->defaults(['controller' => 'ModuleHome', 'action' => 'index', 'module_id' => '212']);
