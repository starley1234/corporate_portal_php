<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 520,
    'slug'      => 'poll',
    'title'     => 'Быстрый опрос',
    'short'     => 'Опрос за 10 секунд: ссылка, голосование, живые результаты',
    'icon'      => 'fas fa-chart-simple',
    'href'      => '/poll',
    // Страница голосования — объект этого сервиса, не отдельный модуль.
    'nav_paths' => ['/p'],
    'group'     => 'Опросы',
    'sort'      => 520,
    'keywords'  => 'опрос голосование голоса рейтинг выбор за кого ask vote',
    'api'       => ['/nj520/api/stats'],
    // раздел управления модуля: чип в шапке (только администратору)
    'admin'     => ['title' => 'Опросы · управление', 'href' => '/nj520/admin'],
    'card_view' => 'nj520/card',
];
