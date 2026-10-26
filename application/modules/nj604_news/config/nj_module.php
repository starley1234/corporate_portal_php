<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Новости сайта» (nj604_news) для nj110_catalog.
 */

return [
    'id'        => 604,
    'slug'      => 'nj604_news',
    'title'     => 'Новости сайта',
    'short'     => 'Что нового на N2J.ru: обновления платформы и новые инструменты',
    'icon'      => 'fas fa-bullhorn',
    'href'      => '/news',
    'group'     => 'Платформа',
    'sort'      => 604,
    'badge'     => 'новое',
    'card_view' => '604/card',
    'keywords'  => 'новости сайта обновления что нового changelog релизы платформа rss фичи',
    'api'       => ['/news/rss.xml'],
    'admin'     => ['title' => 'Новости · управление', 'href' => '/nj604/admin'],
    'auth'      => FALSE,
];
