<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id' => 112,
    'slug' => 'prx112_portal_plan',
    'title' => 'План и архив',
    'short' => 'Одобренные, находящиеся в работе и запланированные сервисы по доменам',
    'status' => 'stable',
    'audience' => 'auth',
    'acl' => 'portal.access',
    'href' => '/prx112_portal_plan',
    'nav_paths' => ['/portal-plan'],
    'group' => 'Платформа',
    'icon' => 'fas fa-box-archive',
];
