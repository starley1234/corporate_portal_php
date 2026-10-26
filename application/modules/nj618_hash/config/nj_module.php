<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 618,
    'slug'      => 'hash',
    'title'     => 'Хеш-инструмент',
    'short'     => 'MD5, SHA-1, SHA-256/384/512 для строк',
    'icon'      => 'fas fa-hashtag',
    'href'      => '/hash',
    'group'     => 'Разработчику',
    'sort'      => 618,
    'keywords'  => 'md5 sha sha1 sha256 sha384 sha512 хеш контрольная сумма онлайн',
    'api'       => ['/nj618/api/hash'],
    'card_view' => 'nj618/card',
];
