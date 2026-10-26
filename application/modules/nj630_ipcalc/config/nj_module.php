<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 630,
    'slug'      => 'ipcalc',
    'title'     => 'IP и подсети',
    'short'     => 'Диапазон, маска, адреса сети и хостов по CIDR',
    'icon'      => 'fas fa-network-wired',
    'href'      => '/ipcalc',
    'group'     => 'Разработчику',
    'sort'      => 630,
    'keywords'  => 'ip калькулятор подсеть cidr маска диапазон адресов сеть broadcast хосты ipv4',
    'api'       => ['/nj630/api/range'],
    'card_view' => 'nj630/card',
];
