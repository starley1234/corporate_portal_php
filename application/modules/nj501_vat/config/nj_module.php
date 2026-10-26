<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 501,
    'slug'      => 'vat',
    'title'     => 'Калькулятор НДС',
    'short'     => 'Выделить или начислить НДС по ставкам 2026',
    'icon'      => 'fas fa-percent',
    'href'      => '/vat',
    'group'     => 'Бухгалтерия',
    'sort'      => 501,
    'keywords'  => 'ндс vat налог 22 20 10 5 7 успн выделить начислить калькулятор',
    'api'       => ['/nj501/api/calc', '/nj501/api/rates'],
    'card_view' => 'nj501/card',
];
