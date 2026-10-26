<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 619,
    'slug'      => 'unixtime',
    'title'     => 'Unix-время',
    'short'     => 'Timestamp ↔ дата: мс/с, часовые пояса, ISO',
    'icon'      => 'fas fa-clock',
    'href'      => '/unixtime',
    'group'     => 'Разработчику',
    'sort'      => 619,
    'keywords'  => 'timestamp unix unixtime эпоха конвертер дата время iso часовой пояс',
    'api'       => ['/nj619/api/convert'],
    'card_view' => 'nj619/card',
];
