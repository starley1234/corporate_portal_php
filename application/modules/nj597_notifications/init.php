<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj597_notifications («Центр уведомлений»).
 *
 * Адреса:
 *   /nj597                     — лента уведомлений и настройки пользователя;
 *   /nj597/admin               — управление: сводка, события, шаблоны,
 *                                каналы, лимиты, журнал, чистка;
 *   /nj597/api/*               — колокольчик, настройки, привязка каналов,
 *                                один проход доставки (outbox);
 *   /nj597/unsub               — отказ от уведомлений по ссылке из письма
 *                                (без входа, пропуск — подпись ссылки);
 *   /nj597/hook/telegram|max   — вебхуки ботов (привязка аккаунтов).
 *
 * Порядок маршрутов важен: admin и api — ДО общего /nj597, иначе «admin»
 * и «api» будут приняты за действие страницы.
 */

// Канал приходит как действие: /nj597/hook/telegram → action_telegram.
// Регэксп не давит подсунуть чужой канал (иначе 404 на маршруте).
Route::set('nj597_hook', 'nj597/hook/<action>', ['action' => '(telegram|max)'])
    ->defaults([
        'controller' => 'Nj597_Hook',
    ]);

Route::set('nj597_admin', 'nj597/admin(/<action>)')
    ->defaults([
        'controller' => 'Nj597_Admin',
        'action'     => 'index',
    ]);

Route::set('nj597_api', 'nj597/api(/<action>)')
    ->defaults([
        'controller' => 'Nj597_Api',
        'action'     => 'unread',
    ]);

// Отказ по ссылке из письма: работает без входа (пропуск — подпись ссылки).
// Ставим ДО общего /nj597, чтобы адрес не перехватила страница пользователя.
Route::set('nj597_unsub', 'nj597/unsub')
    ->defaults([
        'controller' => 'Nj597_Unsub',
        'action'     => 'index',
    ]);

Route::set('nj597_page', 'nj597')
    ->defaults([
        'controller' => 'Nj597_Notifications',
        'action'     => 'index',
    ]);
