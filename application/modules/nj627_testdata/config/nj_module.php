<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 627,
    'slug'      => 'testdata',
    'title'     => 'Тестовые данные',
    'short'     => 'ФИО, телефоны и реквизиты с корректными контрольными цифрами',
    'icon'      => 'fas fa-people-arrows',
    'href'      => '/testdata',
    'group'     => 'Разработчику',
    'sort'      => 627,
    'keywords'  => 'тестовые данные генератор фейк фио имя фамилия телефон инн кпп огрн снилс контрольная цифра валидные',
    'api'       => ['/nj627/api/generate'],
    'card_view' => 'nj627/card',
];
