<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация nj110_catalog: страницы каталога + JSON для палитры «Все сервисы».
 *
 *   GET /services          — полный каталог опубликованных сервисов (SSR, SEO);
 *   GET /services/<slug>   — хаб группы (slug из Service_Nj110_Catalog::group_slug);
 *   GET /nj110/api/catalog — JSON реестра видимых сервисов (палитра поиска).
 *
 * Роут group с ограничением [a-z0-9-]+ регистрируется ДО page-роута, иначе
 * «services» без слага перехватывает «services/json» (см. nj611 init).
 */

Route::set('nj110_catalog_group', 'services/<slug>', ['slug' => '[a-z0-9-]+'])
    ->defaults([
        'controller' => 'Nj110_Catalog',
        'action'     => 'group',
    ]);

Route::set('nj110_catalog_page', 'services')
    ->defaults([
        'controller' => 'Nj110_Catalog',
        'action'     => 'index',
    ]);

Route::set('nj110_catalog_api', 'nj110/api(/<action>)')
    ->defaults([
        'controller' => 'Nj110_Api',
        'action'     => 'catalog',
    ]);
