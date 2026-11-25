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

// Канонический адрес главной модуля (префикс+слаг): /prx110_catalog.
// Короткий URL выше остаётся рабочим алиасом.
Route::set('prx110_catalog_home', 'prx110_catalog')

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

// Меню раздела «Страницы», палитра сервисов и план «Рабочего стола»: ядро
// спрашивает точки расширения вместо прямого вызова сервиса каталога
// (docs/SKELETON-ADR.md Д2/Д7). Без модуля каталога — пусто.
Prx_Hooks::on('nav.catalog_menu', function () {
    return Service_Prx110_Catalog::menu_items();
});
Prx_Hooks::on('nav.catalog_pages', function () {
    return Service_Prx110_Catalog::pages_items();
});
Prx_Hooks::on('nav.catalog_plan', function () {
    return Service_Prx110_Catalog::plan_modules();
});

// План «Рабочего стола»: какие сервисы в какую колонку. Ядро не знает слагов
// модулей — имена приходят отсюда, видимость из каталога, доступ по ACL из
// манифеста (проверка остаётся в ядре).
Prx_Hooks::on('nav.welcome_plan', function () {
    return [
        'work'  => ['prx395_document_extract', 'prx597_notifications'],
        'stand' => ['prx390_intake', 'prx391_documents', 'prx392_analysis', 'prx393_workflow'],
    ];
});
