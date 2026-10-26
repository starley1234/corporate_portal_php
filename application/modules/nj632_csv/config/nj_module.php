<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 632,
    'slug'      => 'csv',
    'title'     => 'CSV ↔ JSON',
    'short'     => 'Таблицу в JSON и обратно — с разделителем и экранированием',
    'icon'      => 'fas fa-table',
    'href'      => '/csv',
    'group'     => 'Конвертеры',
    'sort'      => 632,
    'keywords'  => 'csv json конвертер таблица массив разделитель запятая точка с запятой онлайн',
    'api'       => ['/nj632/api/convert'],
    'card_view' => 'nj632/card',
];
