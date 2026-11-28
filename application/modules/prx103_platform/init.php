<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля prx103_platform.
 *
 * CLI-инструменты (Minion): module:list, module:scaffold, dev:doctor.
 * Страницы управления платформой: /dashboard, /admin, /prx100/cleanup,
 * /prx100/errors, /prx100/update, /prx100/selfcheck.
 */

// Внешний мониторинг: /health (жив ли сайт и БД, публично) и /health/metrics
// (Prometheus; токен PRX_METRICS_TOKEN или вход администратором).
Route::set('prx103_health', 'health(/<action>)', ['action' => 'metrics'])
    ->defaults([
        'controller' => 'Prx103_Health',
        'action'     => 'index',
    ]);

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

// Канонический адрес главной модуля (префикс+слаг): /prx103_platform.
// Короткий URL выше остаётся рабочим алиасом.
Route::set('prx103_platform_home', 'prx103_platform')

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

// Хранилище фасада Audit::log() — точка ядра audit.write (ядро модуль не знает).
Prx_Hooks::on('audit.write', static fn (string $category, string $action, $object_id, array $data): int
    => Service_Prx103_Audit::write($category, $action, $object_id, $data));

// «Журнал администратора» — кто, когда, что изменил (фасад Audit::log, prx_103_audit_log)
Route::set('prx100_audit', 'prx100/audit')
    ->defaults(['controller' => 'Prx103_Audit', 'action' => 'index']);

// «Логи» — управление: журнал сайта (Log_Plain) и php-лог Kohana по дням и уровням
Route::set('prx100_usage', 'prx100/usage')
    ->defaults(['controller' => 'Prx103_Usage', 'action' => 'index']);
Route::set('prx100_logs', 'prx100/logs')
    ->defaults([
        'controller' => 'Prx103_Logs',
        'action'     => 'index',
    ]);

// «Проверка сервера» — управление: проверки dev:doctor + php -l по порциям
// (быстрые проверки POST-ом, компиляция — AJAX-порциями из браузера:
// 750+ файлов одним запросом не влезают в таймауты веб-хостинга, 502)
Route::set('prx100_selfcheck', 'prx100/selfcheck(/<action>)', ['action' => '[a-z_]+'])
    ->defaults([
        'controller' => 'Prx103_Selfcheck',
        'action'     => 'index',
    ]);

