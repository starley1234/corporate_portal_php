<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля prl100_platform.
 *
 * CLI-инструменты (Minion): module:list, module:scaffold, dev:doctor.
 * Страницы управления платформой: /dashboard, /admin, /prl100/cleanup,
 * /prl100/errors, /prl100/update.
 */

// Системный дашборд (/dashboard): админу — обзор системы, пользователю — его задачи
Route::set('prl100_dashboard', 'dashboard')
    ->defaults([
        'controller' => 'Prl100_Dashboard',
        'action'     => 'index',
    ]);

// Пинок обновления сайта (/prl100/update — управление; /update/kick/<token> — агент/скрипты):
// запускает bin/deploy.sh --migrate в фоне вместо крона каждую минуту
Route::set('prl100_update_kick', 'update/kick/<token>', ['token' => '[A-Za-z0-9._-]+'])
    ->defaults([
        'controller' => 'Prl100_Update',
        'action'     => 'kick',
    ]);

Route::set('prl100_update', 'prl100/update(/<action>)')
    ->defaults([
        'controller' => 'Prl100_Update',
        'action'     => 'index',
    ]);

// «Чистка данных» — управление: реестр чисток config/prl100_cleanup.php (two-click, порциями)
Route::set('prl100_cleanup', 'prl100/cleanup')
    ->defaults([
        'controller' => 'Prl100_Cleanup',
        'action'     => 'index',
    ]);

// «Администрирование» — единая страница управления: инструменты платформы,
// сервисы платформенной группы и админ-разделы модулей (были пунктами панели).
Route::set('prl100_admin', 'admin')
    ->defaults([
        'controller' => 'Prl100_Admin',
        'action'     => 'index',
    ]);

// /admin_index — исторический адрес из меню профиля («Панель управления»);
// до 25.10.2026 вёл в 404. Та же страница, что и /admin.
Route::set('prl100_admin_index', 'admin_index')
    ->defaults([
        'controller' => 'Prl100_Admin',
        'action'     => 'index',
    ]);

// «Хвост ошибок» — управление: единая лента неисправностей (БД модулей + системный лог)
Route::set('prl100_errors', 'prl100/errors')
    ->defaults([
        'controller' => 'Prl100_Errors',
        'action'     => 'index',
    ]);
