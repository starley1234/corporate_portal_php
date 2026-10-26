<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля prl597_notifications («Центр уведомлений»).
 *
 * Адреса:
 *   /prl597                     — лента уведомлений и настройки пользователя;
 *   /prl597/admin               — управление: сводка, события, шаблоны,
 *                                каналы, лимиты, журнал, чистка;
 *   /prl597/api/*               — колокольчик, настройки, привязка каналов,
 *                                один проход доставки (outbox);
 *   /prl597/unsub               — отказ от уведомлений по ссылке из письма
 *                                (без входа, пропуск — подпись ссылки);
 *   /prl597/hook/telegram|max   — вебхуки ботов (привязка аккаунтов).
 *
 * Порядок маршрутов важен: admin и api — ДО общего /prl597, иначе «admin»
 * и «api» будут приняты за действие страницы.
 */

// Канал приходит как действие: /prl597/hook/telegram → action_telegram.
// Регэксп не давит подсунуть чужой канал (иначе 404 на маршруте).
Route::set('prl597_hook', 'prl597/hook/<action>', ['action' => '(telegram|max)'])
    ->defaults([
        'controller' => 'Prl597_Hook',
    ]);

Route::set('prl597_admin', 'prl597/admin(/<action>)')
    ->defaults([
        'controller' => 'Prl597_Admin',
        'action'     => 'index',
    ]);

Route::set('prl597_api', 'prl597/api(/<action>)')
    ->defaults([
        'controller' => 'Prl597_Api',
        'action'     => 'unread',
    ]);

// Отказ по ссылке из письма: работает без входа (пропуск — подпись ссылки).
// Ставим ДО общего /prl597, чтобы адрес не перехватила страница пользователя.
Route::set('prl597_unsub', 'prl597/unsub')
    ->defaults([
        'controller' => 'Prl597_Unsub',
        'action'     => 'index',
    ]);

Route::set('prl597_page', 'prl597')
    ->defaults([
        'controller' => 'Prl597_Notifications',
        'action'     => 'index',
    ]);
