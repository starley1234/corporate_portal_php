<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля prl595_useradmin («Пользователи и роли»).
 *
 * Только для администратора: /prl595/admin — управление пользователями
 * (создание, поиск, роли, пароль, блокировка через снятие роли login),
 * /prl595/admin/roles — роли, /prl595/admin/activity — последняя активность
 * системы (включая гостей с кукой ubid: Visitor::uid() = 'g:<ubid>').
 *
 * Таблицы ядра users/roles/roles_users БЕЗ префикса (approved) —
 * без изменения схемы; блокировка = отсутствие роли 'login'.
 */

Route::set('prl595_admin', 'prl595/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Prl595_Admin',
        'action'     => 'index',
    ]);
