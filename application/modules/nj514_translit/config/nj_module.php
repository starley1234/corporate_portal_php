<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id' => 514,
    'slug' => 'translit',
    'title' => 'Транслитерация',
    'short' => 'Русский ↔ латиница: МВД для загранпаспорта, ГОСТ 7.79-2000 Б и ICAO',
    'icon' => 'fas fa-language',
    'href' => '/translit',
    'group' => 'Текст',
    'sort' => 514,
    'keywords' => 'транслит транслитерация русский латиница загранпаспорт МВД ФМС ICAO ГОСТ 7.79 имя фамилия английский',
    'api' => ['/nj514/api/convert', '/nj514/api/standards'],
    'card_view' => 'nj514/card',
];
