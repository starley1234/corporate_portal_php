<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 648,
    'slug'      => 'timezones',
    'title'     => 'Созвон по часовым поясам',
    'short'     => 'Время в городах сейчас и час, удобный всем участникам встречи',
    'icon'      => 'fas fa-earth-europe',
    'href'      => '/timezones',
    'group'     => 'Даты и время',
    'sort'      => 648,
    'keywords'  => 'часовые пояса разница во времени время в городах сейчас планировщик созвона совещание мировое время UTC',
    'api'       => ['/nj648/api/now', '/nj648/api/slots', '/nj648/api/cities'],
    'card_view' => 'nj648/card',
];
