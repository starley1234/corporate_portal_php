<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 505,
    'slug'      => 'penalty',
    'title'     => 'Калькулятор пеней и неустойки',
    'short'     => 'Пени по налогам (ст. 75 НК), проценты по ст. 395 ГК по ключевой ставке и договорная неустойка',
    'icon'      => 'fas fa-gavel',
    'href'      => '/penalty',
    'group'     => 'Бухгалтерия',
    'sort'      => 505,
    'keywords'  => 'пени калькулятор ключевая ставка 1/300 1/150 проценты 395 гк неустойка просрочка штраф договор расчёт задолженность мораторий',
    'api'       => ['/nj505/api/calc', '/nj505/api/rates'],
    'card_view' => 'nj505/card',
];
