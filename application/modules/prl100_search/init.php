<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Роуты prl100_search: /search/api — JSON-выдача (её дёргает поиск в шапке),
 * /search — ТОЛЬКО 410 Gone: страница удалена (роботы долбили её вариантами
 * ?q=…&t=…, каждый заход — девять ILIKE-запросов). Роут оставлен намеренно:
 * 410 говорит поисковикам «выкиньте URL», тогда как 404 — «попробуйте позже».
 * Порядок: api ДО страницы.
 */

Route::set('prl100_search_api', 'search/api')
    ->defaults([
        'controller' => 'Prl100_Api',
        'action'     => 'search',
    ]);

Route::set('prl100_search_page', 'search')
    ->defaults([
        'controller' => 'Prl100_Search',
        'action'     => 'index',
    ]);
