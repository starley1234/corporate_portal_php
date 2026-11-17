<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Наглядная документация архитектуры портала.
 *
 * Модель — docs/architecture/model/*.yaml, сборка — `make architecture`.
 * Страница открывается после входа: внутри описаны границы доверия,
 * очередь и правила доступа.
 */
return [
    'id'        => 101,
    'slug'      => 'prl101_architecture',
    'title'     => 'Архитектура',
    'short'     => 'Схемы, слои, модули и решения портала: YAML как источник истины, страница и JSON собираются одной командой',
    'icon'      => 'fas fa-sitemap',
    'href'      => '/architecture',
    'group'     => 'Платформа',
    'sort'      => 105,
    'status'    => 'beta',
    'audience'  => 'auth',
    'keywords'  => 'архитектура схема документация yaml adr решения модули слои docflow очередь безопасность портал',
    'api'       => ['/architecture/data.json'],
    'auth'      => TRUE,
];
