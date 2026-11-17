<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Роуты prx100_search: /search/api — JSON-выдача (её дёргает поиск в шапке),
 * /search — ТОЛЬКО 410 Gone: страница удалена (роботы долбили её вариантами
 * ?q=…&t=…, каждый заход — девять ILIKE-запросов). Роут оставлен намеренно:
 * 410 говорит поисковикам «выкиньте URL», тогда как 404 — «попробуйте позже».
 * Порядок: api ДО страницы.
 */

Route::set('prx100_search_api', 'search/api')
    ->defaults([
        'controller' => 'Prx100_Api',
        'action'     => 'search',
    ]);

Route::set('prx100_search_page', 'search')
    ->defaults([
        'controller' => 'Prx100_Search',
        'action'     => 'index',
    ]);
