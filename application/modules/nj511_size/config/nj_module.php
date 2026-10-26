<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id' => 511,
    'slug' => 'size',
    'title' => 'Размер по меркам',
    'short' => 'Подбор RU/EU/US: одежда, обувь, перчатки и кольца по вашим измерениям',
    'icon' => 'fas fa-ruler-combined',
    'href' => '/size',
    'group' => 'Единицы и быт',
    'sort' => 511,
    'keywords' => 'подобрать размер одежды обуви кольца перчатки грудь стопа ладонь RU EU US таблица размеров по меркам',
    'api' => ['/nj511/api/convert', '/nj511/api/categories'],
    'card_view' => 'nj511/card',
];
