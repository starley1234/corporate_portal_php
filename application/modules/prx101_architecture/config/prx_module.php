<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Наглядная документация архитектуры платформы.
 *
 * Модель — docs/architecture/model/*.yaml, сборка — `make architecture`.
 * Страница открывается после входа: внутри описаны границы доверия,
 * очередь и правила доступа.
 */
return [
    'acl' => 'prx101.view',
    'id'        => 101,
    'slug'      => 'prx101_architecture',
    'title'     => 'Архитектура',
    'short'     => 'Схемы, слои, модули и решения платформы: YAML как источник истины, страница и JSON собираются одной командой',
    'icon'      => 'fas fa-sitemap',
    'href'      => '/prx101_architecture',
    'group'     => 'Платформа',
    'sort'      => 105,
    'status'    => 'beta',
    'audience'  => 'auth',
    'keywords'  => 'архитектура схема документация yaml adr решения модули слои docflow очередь безопасность платформа',
    'api'       => ['/architecture/data.json'],
    'auth'      => TRUE,
];
