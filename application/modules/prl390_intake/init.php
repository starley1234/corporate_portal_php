<?php defined('SYSPATH') or die('No direct script access.');

// API воркера. Проверка X-Worker-Token — внутри контроллера, до любой работы
// с файлом: эти маршруты объявлены public и не требуют сессии пользователя.
Route::set('prl390_intake_api_file', 'prl390/api/file/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl390_Api', 'action' => 'file']);
Route::set('prl390_intake_api_text', 'prl390/api/text/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl390_Api', 'action' => 'text']);
Route::set('prl390_intake_api', 'prl390/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => 'Prl390_Api', 'action' => 'health']);

Route::set('prl390_intake_scan', 'inbox/scan')
    ->defaults(['controller' => 'Prl390_Inbox', 'action' => 'scan']);
Route::set('prl390_intake_upload', 'inbox/upload')
    ->defaults(['controller' => 'Prl390_Inbox', 'action' => 'upload']);
Route::set('prl390_intake_retry', 'inbox/retry/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl390_Inbox', 'action' => 'retry']);
Route::set('prl390_intake', 'inbox')
    ->defaults(['controller' => 'Prl390_Inbox', 'action' => 'index']);

/**
 * Текст распознан — регистрируем документ в реестре. Слушатель в prl390,
 * а не в prl391: событие принадлежит владельцу конверта, а получатель может
 * быть отключён без поломки приёма.
 */
Event::listen('prl390.text.ready', function ($payload, $event_name, $initiator_id) {
    if (empty($payload['message_id']) OR ! class_exists('Service_Prl391_Documents'))
    {
        return;
    }

    try
    {
        Service_Prl391_Documents::register_from_message((int) $payload['message_id']);
    }
    catch (Exception $e)
    {
        Log::instance()->add(Log::ERROR, 'prl390: не удалось зарегистрировать документ: :e', [
            ':e' => $e->getMessage(),
        ]);
    }
});
