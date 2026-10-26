<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 614,
    'slug'      => 'json',
    'title'     => 'JSON-инструмент',
    'short'     => 'Форматирование, валидация и минификация с подсветкой',
    'icon'      => 'fas fa-code',
    'href'      => '/json',
    'group'     => 'Разработчику',
    'sort'      => 614,
    'keywords'  => 'json форматировать валидатор минифицировать beautify сжать отсортировать ключи парсер',
    'api'       => ['/nj614/api/format', '/nj614/api/validate'],
    'card_view' => 'nj614/card',
];
