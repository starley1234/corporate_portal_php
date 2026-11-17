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
        Service_Prx393_Workflow::route((int) $payload['document_id'], [
            'user_id' => 0,
            'roles' => ['system'],
            'is_system' => TRUE,
        ]);
    }
    catch (Exception $e)
    {
        Log::instance()->add(Log::ERROR, 'prx393: не удалось назначить маршрут: :e', [
            ':e' => $e->getMessage(),
        ]);
    }
});

// Карточку изменили (вручную или принятием предложения ИИ) — пересчитываем
// срок. Метод route() обновляет снимок в prx391 без повторного события.
Event::listen('prx391.document.updated', function ($payload, $event_name, $initiator_id) {
    if (empty($payload['document_id']) OR empty($payload['recalc']) OR ! class_exists('Service_Prx393_Workflow'))
    {
        return;
    }

    try
    {
        Service_Prx393_Workflow::route((int) $payload['document_id'], [
            'user_id' => (int) ($payload['actor_id'] ?? 0),
            'roles' => ['system'],
            'is_system' => TRUE,
        ]);
    }
    catch (Exception $e)
    {
        Log::instance()->add(Log::WARNING, 'prx393: пересчёт срока не удался: :e', [
            ':e' => $e->getMessage(),
        ]);
    }
});
