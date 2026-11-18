<?php defined('SYSPATH') or die('No direct script access.');
// Deny by default. Роль admin имеет полный доступ через ACL::check().
// login — техническая роль входа, а не должность сотрудника.
return [
    'portal.access' => ['login'],
    'admin.access' => ['admin'],
    'prx100.admin' => ['admin'],
    'prx100.cleanup.admin' => ['admin'],
    'prx101.view' => ['login'],
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
    'prx595.users.admin' => ['admin'],
    'prx597.admin' => ['admin'],
    'prx560.admin' => ['admin'],
    'prx212_storage.*' => ['admin'],
    'prx218.*' => ['admin'],
];
