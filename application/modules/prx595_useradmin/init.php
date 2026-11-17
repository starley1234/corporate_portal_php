<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля prx595_useradmin («Пользователи и роли»).
 *
 * Только для администратора: /prx595/admin — управление пользователями
 * (создание, поиск, роли, пароль, блокировка через снятие роли login),
 * /prx595/admin/roles — роли, /prx595/admin/activity — последняя активность
 * системы (включая гостей с кукой ubid: Visitor::uid() = 'g:<ubid>').
 *
 * Таблицы ядра users/roles/roles_users БЕЗ префикса (approved) —
 * без изменения схемы; блокировка = отсутствие роли 'login'.
 */

Route::set('prx595_admin', 'prx595/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Prx595_Admin',
        'action'     => 'index',
    ]);
