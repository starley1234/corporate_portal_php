<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 621,
    'slug'      => 'cron',
    'title'     => 'Cron-человечизатор',
    'short'     => 'Выражение cron → слова + следующие запуски',
    'icon'      => 'fas fa-list-check',
    'href'      => '/cron',
    'group'     => 'Разработчику',
    'sort'      => 621,
    'keywords'  => 'cron crontab выражение расписание запуск расшифровка человекочитаемый онлайн',
    'api'       => ['/nj621/api/explain'],
    'card_view' => 'nj621/card',
];
