<?php defined('SYSPATH') or die('No direct script access.');

return [
    'page_size' => 40,
    'statuses'  => [
        'new'        => 'Новый',
        'in_review'  => 'На разборе',
        'in_work'    => 'В работе',
        'on_hold'    => 'Приостановлен',
        'closed'     => 'Закрыт',
        'archived'   => 'В архиве',
    ],
    'priorities' => [
        'low'    => 'Низкий',
        'normal' => 'Обычный',
        'high'   => 'Высокий',
        'urgent' => 'Срочный',
    ],
    'sensitivities' => [
        'public'     => 'Публичный',
        'internal'   => 'Внутренний',
        'personal'   => 'Персональные данные',
        'commercial' => 'Коммерческая тайна',
    ],
    'default_status' => 'new',
];
