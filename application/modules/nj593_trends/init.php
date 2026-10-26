<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj593_trends («Тренды GitHub»).
 *
 * Публичные адреса: /trends — ОТКРЫТЫЙ КАТАЛОГ опубликованных обзоров
 * (для людей и СЕО), /trends/mine — перечень МОИХ обзоров, /trends/new — форма
 * нового обзора (владелец, 15.10.2026: список отдельно, форма отдельно),
 * /trends/<code> — страница обзора (публичная, пока обзор не снят с публикации).
 * Владелец (16.10.2026): «вместо /trends показывай содержимое /trends/catalog,
 * /trends/catalog — убери» — отдельного адреса каталога больше нет.
 * API воркера — /trends/api/* (прогресс, приём позиций, завершение)
 * и /trends/api/create — автосоздание «обзора дня» по расписанию;
 * /trends/api/cancel — прерывание сборки из интерфейса (владелец/управление, CSRF).
 * Таблицы: nj_593_* (миграция 001 == ensure_tables). Генерация — воркер
 * nj593.trends (workers/n2j_workers/handlers/nj593_trends.py): поиск свежих
 * репозиториев GitHub API → чтение README → LLM отбирает интересное и
 * пишет тексты позиций → обзор публикуется на /trends/<code>.
 */

Route::set('nj593_api', 'trends/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj593_Api',
        'action'     => 'status',
    ]);

// mine, new и <code>/delete, <code>/publish — ВЫШЕ /trends/<code>: иначе
// «mine» и «new» съедаются как код обзора, а «delete» — как часть адреса
Route::set('nj593_mine', 'trends/mine')
    ->defaults([
        'controller' => 'Nj593_Trends',
        'action'     => 'mine',
    ]);

Route::set('nj593_new', 'trends/new')
    ->defaults([
        'controller' => 'Nj593_Trends',
        'action'     => 'new',
    ]);

Route::set('nj593_publish', 'trends/<code>/publish', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj593_Trends',
        'action'     => 'publish',
    ]);

Route::set('nj593_delete', 'trends/<code>/delete', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj593_Trends',
        'action'     => 'delete',
    ]);

Route::set('nj593_view', 'trends/<code>', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj593_Trends',
        'action'     => 'view',
    ]);

Route::set('nj593_page', 'trends')
    ->defaults([
        'controller' => 'Nj593_Trends',
        'action'     => 'index',
    ]);

Route::set('nj593_admin', 'nj593/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj593_Admin',
        'action'     => 'index',
    ]);
