<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 518,
    'slug'      => 'percent',
    'title'     => 'Калькулятор процентов',
    'short'     => 'Процент от числа, доля, прирост, наценка и скидка — с пошаговой формулой',
    'icon'      => 'fas fa-percent',
    'href'      => '/percent',
    'group'     => 'Бухгалтерия',
    'sort'      => 518,
    'keywords'  => 'проценты процент от числа сколько процентов прирост убыль наценка маржа скидка калькулятор доля',
    'api'       => ['/nj518/api/calc', '/nj518/api/modes'],
    'card_view' => 'nj518/card',
];
