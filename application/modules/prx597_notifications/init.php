<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля prx597_notifications («Центр уведомлений»).
 *
 * Адреса:
 *   /prx597                     — лента уведомлений и настройки пользователя;
 *   /prx597/admin               — управление: сводка, события, шаблоны,
 *                                каналы, лимиты, журнал, чистка;
 *   /prx597/api/*               — колокольчик, настройки, привязка каналов,
 *                                один проход доставки (outbox);
 *   /prx597/unsub               — отказ от уведомлений по ссылке из письма
 *                                (без входа, пропуск — подпись ссылки);
 *   /prx597/hook/telegram|max   — вебхуки ботов (привязка аккаунтов).
 *
 * Порядок маршрутов важен: admin и api — ДО общего /prx597, иначе «admin»
 * и «api» будут приняты за действие страницы.
 */

// Канал приходит как действие: /prx597/hook/telegram → action_telegram.
// Регэксп не давит подсунуть чужой канал (иначе 404 на маршруте).
Route::set('prx597_hook', 'prx597/hook/<action>', ['action' => '(telegram|max)'])
    ->defaults([
        'controller' => 'Prx597_Hook',
    ]);

Route::set('prx597_admin', 'prx597/admin(/<action>)')
    ->defaults([
        'controller' => 'Prx597_Admin',
        'action'     => 'index',
    ]);

Route::set('prx597_api', 'prx597/api(/<action>)')
    ->defaults([
        'controller' => 'Prx597_Api',
        'action'     => 'unread',
    ]);

// Отказ по ссылке из письма: работает без входа (пропуск — подпись ссылки).
// Ставим ДО общего /prx597, чтобы адрес не перехватила страница пользователя.
Route::set('prx597_unsub', 'prx597/unsub')
    ->defaults([
        'controller' => 'Prx597_Unsub',
        'action'     => 'index',
    ]);

Route::set('prx597_page', 'prx597')
    ->defaults([
        'controller' => 'Prx597_Notifications',
        'action'     => 'index',
    ]);
