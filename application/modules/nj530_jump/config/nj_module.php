<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 530,
    'slug'      => 'jump',
    'title'     => 'Сократить ссылку',
    'short'     => 'Короткий /x/код со счётчиком переходов',
    'icon'      => 'fas fa-link',
    'href'      => '/jump',
    'group'     => 'Ссылки',
    'sort'      => 530,
    'keywords'  => 'сократить ссылку короткий url шорткат редирект клики переходы jump short',
    'api'       => ['/nj530/api/shorten', '/nj530/api/stats'],
    // раздел управления модуля: чип в шапке (только администратору)
    'admin'     => ['title' => 'Ссылки · управление', 'href' => '/nj530/admin'],
    'card_view' => 'nj530/card',

    // --- Пятый контракт: своё в ленте «последнее» (/nj100) -----------------------
    'recent' => [
        'nj_530_links' => [
            'title'  => "host",   // SQL-выражение заголовка
            'status' => NULL,
            'base'   => '/nj530',
            'icon'   => 'fas fa-link',
        ],
    ],
];
