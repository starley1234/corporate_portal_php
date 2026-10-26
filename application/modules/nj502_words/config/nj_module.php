<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 502,
    'slug'      => 'summa-propisyu',
    'title'     => 'Сумма прописью',
    'short'     => 'Число словами для договоров и платёжек',
    'icon'      => 'fas fa-spell-check',
    'href'      => '/summa-propisyu',
    'group'     => 'Бухгалтерия',
    'sort'      => 502,
    'keywords'  => 'сумма прописью число словами рубли копейки договор платежка',
    'api'       => ['/nj502/api/convert'],
    'card_view' => 'nj502/card',
];
