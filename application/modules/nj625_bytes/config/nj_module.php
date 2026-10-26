<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 625,
    'slug'      => 'bytes',
    'title'     => 'Единицы информации',
    'short'     => 'Биты, байты, КиБ/МиБ/ГиБ и КБ/МБ/ГБ — точный перевод',
    'icon'      => 'fas fa-database',
    'href'      => '/bytes',
    'group'     => 'Конвертеры',
    'sort'      => 625,
    'keywords'  => 'байты биты килобайт мегабайт гигабайт кибибайт мебибайт конвертер перевод единицы информации',
    'api'       => ['/nj625/api/convert'],
    'card_view' => 'nj625/card',
];
