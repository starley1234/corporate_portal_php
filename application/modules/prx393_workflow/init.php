<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prx393_workflow_api', 'prx393/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => 'Prx393_Api', 'action' => 'health']);

Route::set('prx393_workflow_route', 'sla/route/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx393_Sla', 'action' => 'route']);
Route::set('prx393_workflow_recalc', 'sla/recalc')
    ->defaults(['controller' => 'Prx393_Sla', 'action' => 'recalc']);
Route::set('prx393_workflow', 'sla')
    ->defaults(['controller' => 'Prx393_Sla', 'action' => 'index']);

// Документ зарегистрирован — назначаем маршрут и срок. Слушатель здесь, а не
// в prx391: реестр не должен зависеть от того, включён ли контроль сроков.
Event::listen('prx391.document.registered', function ($payload, $event_name, $initiator_id) {
    if (empty($payload['document_id']) OR ! class_exists('Service_Prx393_Workflow'))
    {
        return;
    }

    try
    {
        Service_Prx393_Workflow::route_automatically((int) $payload['document_id'], $initiator_id);
    }
    catch (Throwable $e)
    {
        Log_Plain::event(Log::ERROR, 'prx393_diag stage=register_route result=error document_id=:id error_type=:type', [
            ':id' => (int) $payload['document_id'], ':type' => get_class($e),
        ]);
    }
});

// Карточку изменили или закрыли — обновляем срок. Даже при recalc=false
// закрытие должно снять открытый срок с контроля. Снимок не создаёт событие.
Event::listen('prx391.document.updated', function ($payload, $event_name, $initiator_id) {
    if (empty($payload['document_id'])
        OR (empty($payload['recalc']) AND ($payload['reason'] ?? '') !== 'closed')
        OR ! class_exists('Service_Prx393_Workflow'))
    {
        return;
    }

    try
    {
        Service_Prx393_Workflow::route_automatically((int) $payload['document_id'], $initiator_id);
    }
    catch (Throwable $e)
    {
        Log_Plain::event(Log::WARNING, 'prx393_diag stage=update_route result=error document_id=:id error_type=:type', [
            ':id' => (int) $payload['document_id'], ':type' => get_class($e),
        ]);
    }
});
