<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация prl110_catalog: страницы каталога + JSON для палитры «Все сервисы».
 *
 *   GET /services          — полный каталог опубликованных сервисов (SSR, SEO);
 *   GET /services/<slug>   — хаб группы (slug из Service_Prl110_Catalog::group_slug);
 *   GET /prl110/api/catalog — JSON реестра видимых сервисов (палитра поиска).
 *
 * Роут group с ограничением [a-z0-9-]+ регистрируется ДО page-роута, иначе
 * «services» без слага перехватывает «services/json» (см. prl611 init).
 */

Route::set('prl110_catalog_group', 'services/<slug>', ['slug' => '[a-z0-9-]+'])
    ->defaults([
        'controller' => 'Prl110_Catalog',
        'action'     => 'group',
    ]);

Route::set('prl110_catalog_page', 'services')
    ->defaults([
        'controller' => 'Prl110_Catalog',
        'action'     => 'index',
    ]);

Route::set('prl110_catalog_api', 'prl110/api(/<action>)')
    ->defaults([
        'controller' => 'Prl110_Api',
        'action'     => 'catalog',
    ]);
