<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id' => 513,
    'slug' => 'date-words',
    'title' => 'Дата прописью',
    'short' => 'Дата для договора или приказа: словами, «01» сентября 2026 г. и диапазон дат',
    'icon' => 'fas fa-calendar-check',
    'href' => '/date-words',
    'group' => 'Даты и время',
    'sort' => 513,
    'keywords' => 'дата прописью написать дату словами число месяц год договор приказ акт диапазон дат',
    'api' => ['/nj513/api/convert'],
    'card_view' => 'nj513/card',
];
