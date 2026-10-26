<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 620,
    'slug'      => 'jwt',
    'title'     => 'JWT-декодер',
    'short'     => 'Разбор токена: header, claims, сроки exp/iat',
    'icon'      => 'fas fa-key',
    'href'      => '/jwt',
    'group'     => 'Разработчику',
    'sort'      => 620,
    'keywords'  => 'jwt декодер расшифровать токен payload claims exp iat nbf null онлайн',
    'api'       => ['/nj620/api/decode'],
    'card_view' => 'nj620/card',
];
