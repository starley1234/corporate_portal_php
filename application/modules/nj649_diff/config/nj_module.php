<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 649,
    'slug'      => 'diff',
    'title'     => 'Сравнение текстов',
    'short'     => 'Построчные отличия двух версий с подсветкой слов и готовым unified diff',
    'icon'      => 'fas fa-code-compare',
    'href'      => '/diff',
    'group'     => 'Текст',
    'sort'      => 649,
    'keywords'  => 'сравнение текстов онлайн найти отличия двух текстов diff сравнить версии документа построчно',
    'api'       => ['/nj649/api/compare'],
    'card_view' => 'nj649/card',
];
