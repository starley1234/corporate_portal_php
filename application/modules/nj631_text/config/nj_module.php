<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 631,
    'slug'      => 'text',
    'title'     => 'Текст-инструменты',
    'short'     => 'Регистр, статистика, дубликаты, сортировка строк',
    'icon'      => 'fas fa-font',
    'href'      => '/text',
    'group'     => 'Текст',
    'sort'      => 631,
    'keywords'  => 'текст регистр статистика символы слова счётчик удалить дубликаты сортировка строки uppercase lowercase',
    'api'       => ['/nj631/api/tool'],
    'card_view' => 'nj631/card',
];
