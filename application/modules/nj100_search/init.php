<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Роуты nj100_search: /search/api — JSON-выдача (её дёргает поиск в шапке),
 * /search — ТОЛЬКО 410 Gone: страница удалена (роботы долбили её вариантами
 * ?q=…&t=…, каждый заход — девять ILIKE-запросов). Роут оставлен намеренно:
 * 410 говорит поисковикам «выкиньте URL», тогда как 404 — «попробуйте позже».
 * Порядок: api ДО страницы.
 */

Route::set('nj100_search_api', 'search/api')
    ->defaults([
        'controller' => 'Nj100_Api',
        'action'     => 'search',
    ]);

Route::set('nj100_search_page', 'search')
    ->defaults([
        'controller' => 'Nj100_Search',
        'action'     => 'index',
    ]);
