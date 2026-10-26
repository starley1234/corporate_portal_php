<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 508,
    'slug'      => 'calendar',
    'title'     => 'Календарь и даты',
    'short'     => 'Производственный календарь РФ, рабочие дни между датами, дни до даты, точный возраст и стаж',
    'icon'      => 'fas fa-calendar-days',
    'href'      => '/calendar',
    'group'     => 'Даты и время',
    'sort'      => 508,
    'keywords'  => 'производственный календарь рабочие дни выходные праздники переносы сколько дней до возраст стаж норма часов прибавить дни рабочие',
    'api'       => ['/nj508/api/calc', '/nj508/api/year'],
    'card_view' => 'nj508/card',
    // Дополнительные адреса для sitemap.xml (поддерживает nj602_content): по странице на каждый год
    'sitemap'   => ['/calendar/2026', '/calendar/2027', '/calendar/2025', '/calendar/2024'],
];
