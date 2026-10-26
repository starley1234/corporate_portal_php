<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 624,
    'slug'      => 'base',
    'title'     => 'Системы счисления',
    'short'     => 'Перевод между 2, 8, 10 и 16 системами + битовые операции',
    'icon'      => 'fas fa-table-cells',
    'href'      => '/base',
    'group'     => 'Разработчику',
    'sort'      => 624,
    'keywords'  => 'системы счисления перевод двоичная восьмеричная шестнадцатеричная биты and or xor not сдвиг калькулятор',
    'api'       => ['/nj624/api/convert', '/nj624/api/bitop'],
    'card_view' => 'nj624/card',
];
