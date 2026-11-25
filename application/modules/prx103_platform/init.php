<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля prx103_platform.
 *
 * CLI-инструменты (Minion): module:list, module:scaffold, dev:doctor.
 * Страницы управления платформой: /dashboard, /admin, /prx100/cleanup,
 * /prx100/errors, /prx100/update, /prx100/selfcheck.
 */

// Системный дашборд (/dashboard): админу — обзор системы, пользователю — его задачи
Route::set('prx100_dashboard', 'dashboard')
    ->defaults([
        'controller' => 'Prx103_Dashboard',
        'action'     => 'index',
    ]);

// Пинок обновления сайта (/prx100/update — управление; /update/kick/<token> — агент/скрипты):
// запускает bin/deploy.sh --migrate в фоне вместо крона каждую минуту
Route::set('prx100_update_kick', 'update/kick/<token>', ['token' => '[A-Za-z0-9._-]+'])
    ->defaults([
        'controller' => 'Prx103_Update',
        'action'     => 'kick',
    ]);

Route::set('prx100_update', 'prx100/update(/<action>)')
    ->defaults([
        'controller' => 'Prx103_Update',
        'action'     => 'index',
    ]);

// «Чистка данных» — управление: реестр чисток config/prx100_cleanup.php (two-click, порциями)
Route::set('prx100_cleanup', 'prx100/cleanup')
    ->defaults([
        'controller' => 'Prx103_Cleanup',
        'action'     => 'index',
    ]);

// «Администрирование» — единая страница управления: инструменты платформы,
// сервисы платформенной группы и админ-разделы модулей (были пунктами панели).
Route::set('prx100_admin', 'admin')
    ->defaults([
        'controller' => 'Prx103_Admin',
        'action'     => 'index',
    ]);

// /admin_index — исторический адрес из меню профиля («Панель управления»);
// до 25.10.2026 вёл в 404. Та же страница, что и /admin.
Route::set('prx100_admin_index', 'admin_index')
    ->defaults([
        'controller' => 'Prx103_Admin',
        'action'     => 'index',
    ]);

// «Хвост ошибок» — управление: единая лента неисправностей (БД модулей + системный лог)
Route::set('prx100_errors', 'prx100/errors')
    ->defaults([
        'controller' => 'Prx103_Errors',
        'action'     => 'index',
    ]);

// «Логи» — управление: журнал сайта (Log_Plain) и php-лог Kohana по дням и уровням
Route::set('prx100_logs', 'prx100/logs')
    ->defaults([
        'controller' => 'Prx103_Logs',
        'action'     => 'index',
    ]);

// «Проверка сервера» — управление: проверки dev:doctor + php -l по всему коду
// кнопкой из браузера (после деплоя, без shell)
Route::set('prx100_selfcheck', 'prx100/selfcheck')
    ->defaults([
        'controller' => 'Prx103_Selfcheck',
        'action'     => 'index',
    ]);

