<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 617,
    'slug'      => 'uuid',
    'title'     => 'UUID и ULID',
    'short'     => 'Генератор UUID v4, v7 и ULID пачками',
    'icon'      => 'fas fa-fingerprint',
    'href'      => '/uuid',
    'group'     => 'Разработчику',
    'sort'      => 617,
    'keywords'  => 'uuid v4 v7 ulid guid генератор идентификатор ключ база',
    'api'       => ['/nj617/api/generate'],
    'card_view' => 'nj617/card',
];
