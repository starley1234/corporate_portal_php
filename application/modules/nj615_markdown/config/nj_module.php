<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 615,
    'slug'      => 'markdown',
    'title'     => 'Markdown-инструмент',
    'short'     => 'Живой предпросмотр и конвертация в HTML',
    'icon'      => 'fas fa-hashtag',
    'href'      => '/markdown',
    'group'     => 'Разработчику',
    'sort'      => 615,
    'keywords'  => 'markdown md предпросмотр html конвертер редактор разметка заметки',
    'api'       => ['/nj615/api/render'],
    'card_view' => 'nj615/card',
];
