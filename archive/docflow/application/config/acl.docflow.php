<?php defined('SYSPATH') or die('No direct script access.');
// Исторические права: аудит обязателен перед повторным включением.
return [
    // Документооборот prx390–prx394. Просмотр — всем авторизованным;
    // правка и применение предложений ИИ — делопроизводитель/юрист/руководитель.
    'prx390.view' => ['login'],
    'prx390.manage' => ['docflow_clerk', 'docflow_manager'],
    'prx391.view' => ['login'],
    'prx391.edit' => ['docflow_clerk', 'docflow_lawyer', 'docflow_manager'],
    'prx391.admin' => ['docflow_manager'],
    'prx392.view' => ['login'],
    'prx392.run' => ['docflow_clerk', 'docflow_lawyer', 'docflow_manager'],
    'prx392.admin' => ['docflow_manager'],
    'prx393.view' => ['login'],
    'prx393.manage' => ['docflow_manager'],
    'prx394.view' => ['login'],
    'prx394.draft' => ['docflow_clerk', 'docflow_lawyer'],
    'prx394.approve' => ['docflow_lawyer', 'docflow_manager'],
    'prx394.send' => ['docflow_manager'],
];
