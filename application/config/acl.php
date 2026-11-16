<?php defined('SYSPATH') or die('No direct script access.');
// Deny by default. Роль admin имеет полный доступ через ACL::check().
// login — техническая роль входа, а не должность сотрудника.
return [
    'portal.access' => ['login'],
    'admin.access' => ['admin'],
    'prl100.admin' => ['admin'],
    'prl100.cleanup.admin' => ['admin'],
    'prl111.view' => ['admin'],
    'prl111.apply' => ['admin'],
    'prl115.admin' => ['admin'],
    'prl120.monitor' => ['admin'],
    'prl120.usage.admin' => ['admin'],
    'prl300.view' => ['login'],
    'prl300.directory' => ['login'],
    'prl300.admin.view' => ['hr_viewer', 'hr_admin'],
    'prl300.manage' => ['hr_admin'],
    'prl300.import' => ['hr_admin'],
    'prl300.identity' => ['hr_admin'],
    'prl595.users.admin' => ['admin'],
    'prl597.admin' => ['admin'],
    'prl560.admin' => ['admin'],
    'prl212_storage.*' => ['admin'],
    'prl218.*' => ['admin'],
];
