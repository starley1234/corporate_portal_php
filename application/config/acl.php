<?php defined('SYSPATH') or die('No direct script access.');
// Deny by default. Роль admin имеет полный доступ через ACL::check().
// login — техническая роль входа, а не должность сотрудника.
return [
    'portal.access' => ['login'],
    'admin.access' => ['admin'],
    'prx100.admin' => ['admin'],
    'prx100.cleanup.admin' => ['admin'],
    'prx101.view' => ['login'],
    'prx100.search' => ['login'],
    'prx111.view' => ['admin'],
    'prx111.apply' => ['admin'],
    'prx115.admin' => ['admin'],
    'prx120.monitor' => ['admin'],
    'prx120.usage.admin' => ['admin'],
    'prx300.view' => ['login'],
    'prx300.directory' => ['login'],
    'prx300.admin.view' => ['hr_viewer', 'hr_admin'],
    'prx300.manage' => ['hr_admin'],
    'prx300.import' => ['hr_admin'],
    'prx300.identity' => ['hr_admin'],
    'prx390.view' => ['admin'],
    'prx391.view' => ['admin'],
    'prx392.view' => ['admin'],
    'prx393.view' => ['admin'],
    'prx597.view' => ['login'],
    'prx599.view' => ['login'],
    'prx395.ui' => ['admin'],
    'prx150.admin' => ['admin'],
    'prx450.view' => ['admin'],
    'prx595.users.admin' => ['admin'],
    'prx597.admin' => ['admin'],
    'prx560.admin' => ['admin'],
    // Модули, чьи ключи исторически не совпадают с префиксом папки.
    // Маска объявляет весь реестр модуля; deny by default сохраняется.
    'prx212_storage.*' => ['admin'],
    'prx218.*' => ['admin'],
    'module_manager.*' => ['admin'],   // prx218_module_manager, старые ключи
    'prx208_events.*' => ['admin'],
    // Документооборот prx390–prx394 — проба пера: доступ только админу.
    'prx390.*' => ['admin'],
    'prx391.*' => ['admin'],
    'prx392.*' => ['admin'],
    'prx393.*' => ['admin'],
    'prx394.*' => ['admin'],
];
