<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 509,
    'slug'      => 'password',
    'title'     => 'Генератор паролей',
    'short'     => 'Криптостойкие пароли до 96 символов',
    'icon'      => 'fas fa-key',
    'href'      => '/password',
    'group'     => 'Разработчику',
    'sort'      => 509,
    'keywords'  => 'пароль пароли генератор password случайный надёжный безопасный',
    'api'       => ['/nj509/api/generate'],
    'card_view' => 'nj509/card',
	'auth'     => FALSE,
];
