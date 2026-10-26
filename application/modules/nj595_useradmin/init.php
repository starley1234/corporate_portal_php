<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj595_useradmin («Пользователи и роли»).
 *
 * Только для администратора: /nj595/admin — управление пользователями
 * (создание, поиск, роли, пароль, блокировка через снятие роли login),
 * /nj595/admin/roles — роли, /nj595/admin/activity — последняя активность
 * системы (включая гостей с кукой ubid: Visitor::uid() = 'g:<ubid>').
 *
 * Таблицы ядра users/roles/roles_users БЕЗ префикса (approved) —
 * без изменения схемы; блокировка = отсутствие роли 'login'.
 */

Route::set('nj595_admin', 'nj595/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj595_Admin',
        'action'     => 'index',
    ]);
