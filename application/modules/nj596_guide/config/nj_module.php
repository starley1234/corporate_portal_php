<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Путеводитель» (nj596_guide) для nj110_catalog.
 */

return [
    'id'        => 596,
    'slug'      => 'nj596_guide',
    'title'     => 'Путеводитель',
    'short'     => 'Живая документация платформы: что умеет каждый сервис и как им пользоваться — с диаграммами и примерами',
    'icon'      => 'fas fa-map-location-dot',
    'href'      => '/guide',
    'group'     => 'Справка',
    'sort'      => 640,
    'keywords'  => 'документация справка путеводитель инструкция как пользоваться обзор сервисы презентация mermaid readme',
    'api'       => [],
    'auth'      => FALSE, // пользовательская часть открыта; /guide/dev — только admin
];
