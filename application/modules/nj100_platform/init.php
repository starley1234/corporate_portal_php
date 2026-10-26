<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj100_platform.
 *
 * CLI-инструменты (Minion): module:list, module:scaffold, dev:doctor.
 * Страницы управления платформой: /dashboard, /admin, /nj100/cleanup,
 * /nj100/errors, /nj100/update.
 */

// Системный дашборд (/dashboard): админу — обзор системы, пользователю — его задачи
Route::set('nj100_dashboard', 'dashboard')
    ->defaults([
        'controller' => 'Nj100_Dashboard',
        'action'     => 'index',
    ]);

// Пинок обновления сайта (/nj100/update — управление; /update/kick/<token> — агент/скрипты):
// запускает bin/deploy.sh --migrate в фоне вместо крона каждую минуту
Route::set('nj100_update_kick', 'update/kick/<token>', ['token' => '[A-Za-z0-9._-]+'])
    ->defaults([
        'controller' => 'Nj100_Update',
        'action'     => 'kick',
    ]);

Route::set('nj100_update', 'nj100/update(/<action>)')
    ->defaults([
        'controller' => 'Nj100_Update',
        'action'     => 'index',
    ]);

// «Чистка данных» — управление: реестр чисток config/nj100_cleanup.php (two-click, порциями)
Route::set('nj100_cleanup', 'nj100/cleanup')
    ->defaults([
        'controller' => 'Nj100_Cleanup',
        'action'     => 'index',
    ]);

// «Администрирование» — единая страница управления: инструменты платформы,
// сервисы платформенной группы и админ-разделы модулей (были пунктами панели).
Route::set('nj100_admin', 'admin')
    ->defaults([
        'controller' => 'Nj100_Admin',
        'action'     => 'index',
    ]);

// /admin_index — исторический адрес из меню профиля («Панель управления»);
// до 25.10.2026 вёл в 404. Та же страница, что и /admin.
Route::set('nj100_admin_index', 'admin_index')
    ->defaults([
        'controller' => 'Nj100_Admin',
        'action'     => 'index',
    ]);

// «Хвост ошибок» — управление: единая лента неисправностей (БД модулей + системный лог)
Route::set('nj100_errors', 'nj100/errors')
    ->defaults([
        'controller' => 'Nj100_Errors',
        'action'     => 'index',
    ]);
