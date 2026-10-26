<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 626,
    'slug'      => 'unicode',
    'title'     => 'Unicode-анализатор',
    'short'     => 'Коды символов: U+XXXX, UTF-8, escape-последовательности',
    'icon'      => 'fas fa-spell-check',
    'href'      => '/unicode',
    'group'     => 'Разработчику',
    'sort'      => 626,
    'keywords'  => 'unicode код символа u+ utf-8 utf-16 декодировать escape эмодзи юникод онлайн',
    'api'       => ['/nj626/api/analyze'],
    'card_view' => 'nj626/card',
];
