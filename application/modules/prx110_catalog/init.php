<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация prx110_catalog: страницы каталога + JSON для палитры «Все сервисы».
 *
 *   GET /services          — полный каталог опубликованных сервисов (SSR, SEO);
 *   GET /services/<slug>   — хаб группы (slug из Service_Prx110_Catalog::group_slug);
 *   GET /prx110/api/catalog — JSON реестра видимых сервисов (палитра поиска);
 *   GET|POST /prx110/api/favorites — закладки текущего аккаунта.
 *
 * Роут group с ограничением [a-z0-9-]+ регистрируется ДО page-роута, иначе
 * «services» без слага перехватывает «services/json» (см. prx611 init).
 */

Route::set('prx110_catalog_group', 'services/<slug>', ['slug' => '[a-z0-9-]+'])
    ->defaults([
        'controller' => 'Prx110_Catalog',
        'action'     => 'group',
    ]);

Route::set('prx110_catalog_page', 'services')
    ->defaults([
        'controller' => 'Prx110_Catalog',
        'action'     => 'index',
    ]);

Route::set('prx110_catalog_api', 'prx110/api(/<action>)')
    ->defaults([
        'controller' => 'Prx110_Api',
        'action'     => 'catalog',
    ]);

// Избранное для sidebar: база страницы спрашивает точку расширения, модуль
// отвечает сам (docs/SKELETON-ADR.md Д2). Без этого модуля избранного нет.
Prx_Hooks::on('nav.favorites', function ($user_id, $uri) {
    $items = Service_Prx110_Favorites::items($user_id);

    return Service_Prx110_Favorites::mark_active($items, $uri);
});
