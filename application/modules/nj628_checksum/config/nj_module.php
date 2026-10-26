<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 628,
    'slug'      => 'checksum',
    'title'     => 'Контрольные цифры',
    'short'     => 'Проверка ИНН, ОГРН, СНИЛС, банковских карт, штрихкодов и CRC32',
    'icon'      => 'fas fa-check-double',
    'href'      => '/checksum',
    'group'     => 'Разработчику',
    'sort'      => 628,
    'keywords'  => 'контрольная цифра проверка инн огрн снилс луна банковская карта штрихкод ean upc crc32 валидация',
    'api'       => ['/nj628/api/check'],
    'card_view' => 'nj628/card',
];
