<?php defined('SYSPATH') or die('No direct script access.');

// API воркера. Проверка X-Worker-Token — внутри контроллера, до любой работы
// с файлом: эти маршруты объявлены public и не требуют сессии пользователя.
Route::set('prx390_intake_api_file', 'prx390/api/file/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx390_Api', 'action' => 'file']);
Route::set('prx390_intake_api_text', 'prx390/api/text/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx390_Api', 'action' => 'text']);
Route::set('prx390_intake_api', 'prx390/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => 'Prx390_Api', 'action' => 'health']);

Route::set('prx390_intake_scan', 'inbox/scan')
    ->defaults(['controller' => 'Prx390_Inbox', 'action' => 'scan']);
Route::set('prx390_intake_upload', 'inbox/upload')
    ->defaults(['controller' => 'Prx390_Inbox', 'action' => 'upload']);
Route::set('prx390_intake_retry', 'inbox/retry/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx390_Inbox', 'action' => 'retry']);
Route::set('prx390_intake', 'inbox')
    ->defaults(['controller' => 'Prx390_Inbox', 'action' => 'index']);

/**
 * Текст распознан — регистрируем документ в реестре. Слушатель в prx390,
 * а не в prx391: событие принадлежит владельцу конверта, а получатель может
 * быть отключён без поломки приёма.
 */
Event::listen('prx390.text.ready', function ($payload, $event_name, $initiator_id) {
    if (empty($payload['message_id']) OR ! class_exists('Service_Prx391_Documents'))
    {
        return;
    }

    try
    {
        $document_id = Service_Prx391_Documents::register_from_message((int) $payload['message_id']);
        Log_Plain::event(Log::INFO, 'prx390_diag stage=register result=ok message_id=:message document_id=:document', [
            ':message' => (int) $payload['message_id'], ':document' => (int) $document_id,
        ]);
    }
    catch (Exception $e)
    {
        Log_Plain::event(Log::ERROR, 'prx390_diag stage=register result=error message_id=:message error_type=:type', [
            ':message' => (int) $payload['message_id'], ':type' => get_class($e),
        ]);
    }
});
