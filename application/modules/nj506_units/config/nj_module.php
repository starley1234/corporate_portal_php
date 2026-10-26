<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id' => 506,
    'slug' => 'units',
    'title' => 'Конвертер единиц',
    'short' => 'Перевод величин и русских бытовых мер: сотки, пуды, сажени, вёрсты и вёдра',
    'icon' => 'fas fa-right-left',
    'href' => '/units',
    'group' => 'Единицы и быт',
    'sort' => 506,
    'keywords' => 'конвертер единиц величин длина масса объём площадь температура давление скорость сотка пуд сажень верста ведро',
    'api' => ['/nj506/api/calc', '/nj506/api/catalog'],
    'card_view' => 'nj506/card',
];
