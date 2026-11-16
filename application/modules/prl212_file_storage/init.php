<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRL212 Storage Module Initialization
 * Абстракция хранилища: единый интерфейс для работы с файлами
 * ID модуля: 212
 */

// Регистрация роутов модуля
// Роуты соглашению: prl212_<controller>/<action>/<params>


Route::set('prl212_storage', 'prl212_storage(/<action>(/<id>))')
    ->defaults(array(
        'directory'  => 'Prl212',
        'controller' => 'Storage',
        'action'     => 'index',
    ));    

// 2. REST API для работы с файлами (JSON)
Route::set('prl212_storage_api', 'prl212_api/<action>(/<id>)')
    ->defaults(array(
        'directory'  => 'Prl212',
        'controller' => 'Api',
    ));

// 3. Контроллер скачивания файлов (Binary Stream)
// Альтернативный роут для скачивания
Route::set('prl212_storage_download_short', 'storage/get/<id>')
    ->defaults(array(
        'directory'  => 'Prl212',
        'controller' => 'Download',
        'action'     => 'file',
    ));

Route::set('prl212_storage_download_file', 'prl212_download/file/<id>')
    ->defaults(array(
        'directory'  => 'Prl212',
        'controller' => 'Download',
        'action'     => 'file',
    ));

Route::set('prl212_storage_download_shared', 'prl212_download/shared/<token>')
    ->defaults(array(
        'directory'  => 'Prl212',
        'controller' => 'Download',
        'action'     => 'shared',
    ));

// 4. HMVC виджеты для вставки в другие модули
Route::set('prl212_storage_widget', 'prl212_widget/<action>')
    ->defaults(array(
        'directory'  => 'Prl212',
        'controller' => 'Widget',
        'action'     => 'uploader',
    ));

// 5. Корневой роут модуля (редирект на интерфейс)
Route::set('prl212_storage_root', 'prl212')
    ->defaults(array(
        'directory'  => 'Prl212',
        'controller' => 'Index',
        'action'     => 'index',
    ));

// Регистрация событий (если требуется)
// Пример: очистка просроченных временных ссылок при завершении запроса
Event::listen('system.shutdown', function() {
    // Запуск фоновой задачи очистки (реализуется в Minion задаче)
    // Service_Prl212_Storage::cleanup_expired_links();
});



// Инициализация конфигурации модуля
// Загружаем конфигурационные настройки
$config = Kohana::$config->load('prl212_storage');

// Проверка обязательных настроек
/*
if (!$config->get('allowed_mime_types') || !$config->get('max_file_size')) {
    Kohana::$log->add(Log::ERROR, 'PRL212 Storage: Не настроены обязательные параметры allowed_mime_types или max_file_size');
}
*/


// Регистрация кастомного исключения для доступа к файлам
// HTTP_Exception_403::factory('storage_access_denied');