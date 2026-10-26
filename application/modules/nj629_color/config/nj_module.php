<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 629,
    'slug'      => 'color',
    'title'     => 'Конвертер цветов',
    'short'     => 'HEX ↔ RGB ↔ HSL и оттенки цвета',
    'icon'      => 'fas fa-palette',
    'href'      => '/color',
    'group'     => 'Разработчику',
    'sort'      => 629,
    'keywords'  => 'цвет конвертер hex rgb hsl оттенки палитра css онлайн перевод цвета',
    'api'       => ['/nj629/api/convert'],
    'card_view' => 'nj629/card',
];
