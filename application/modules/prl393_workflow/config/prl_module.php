<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест prl393_workflow — маршруты, календарь и сроки.
 *
 * Источник истины по сроку — этот модуль. prl391 получает `deadline_at`
 * только через публичный метод prl393, а не читает чужие таблицы.
 */
return [
    'id' => 393,
    'slug' => 'prl393_workflow',
    'title' => 'Сроки и маршруты',
    'short' => 'Правила маршрутизации, производственный календарь и контроль сроков',
    'icon' => 'fas fa-clock',
    'href' => '/sla',
    'group' => 'Документооборот',
    'sort' => 393,
    'status' => 'beta',
    'audience' => 'auth',
    'acl' => 'prl393.view',
    'keywords' => 'срок дедлайн маршрут исполнитель календарь просрочка эскалация',
    'api' => ['/prl393/api/health'],

    'notify' => [
        'events' => [
            'prl393.deadline.approaching' => [
                'title' => 'Срок по документу приближается',
                'description' => 'До срока осталось меньше суток или несколько часов.',
                'category' => 'documents',
                'icon' => 'fas fa-hourglass-half',
                'channels' => 'in_app,email',
                'roles' => 'docflow_clerk,docflow_lawyer,docflow_manager',
                'digest' => FALSE,
                'placeholders' => 'title,reg_no,due_at,hours_left,href',
            ],
            'prl393.deadline.overdue' => [
                'title' => 'Срок по документу просрочен',
                'description' => 'Срок истёк, документ не закрыт.',
                'category' => 'documents',
                'icon' => 'fas fa-fire',
                'channels' => 'in_app,email',
                'roles' => 'docflow_clerk,docflow_manager',
                'digest' => FALSE,
                'placeholders' => 'title,reg_no,due_at,href',
            ],
        ],
    ],
];
