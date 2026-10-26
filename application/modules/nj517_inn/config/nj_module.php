<?php defined('SYSPATH') or die('No direct script access.');

/** Манифест публичного сервиса «Проверка ИНН» (nj517_inn). */
return [
    'id'        => 517,
    'slug'      => 'inn',
    'title'     => 'Проверка ИНН',
    'short'     => 'Проверка формата и контрольных цифр; открытые сведения ФНС — только по отдельному запросу',
    'icon'      => 'fas fa-building',
    'href'      => '/inn',
    'group'     => 'Данные',
    'sort'      => 517,
    'keywords'  => 'инн проверить налоговый номер контрольная цифра организация юридическое лицо физлицо ип фнс егрюл егрип',
    'api'       => ['/nj517/api/lookup'],
    'card_view' => 'nj517/card',
    'auth'      => FALSE,
];
