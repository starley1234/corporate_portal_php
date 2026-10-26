<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj596_guide («Путеводитель»).
 *
 * Автособираемая документация платформы:
 *  * /guide — для пользователей: презентации сервисов (кратко, по-человечески,
 *    с Mermaid-диаграммами) из манифестов nj110 + config/nj596_presentations.php;
 *  * /guide/<slug> — страница сервиса для пользователей;
 *  * /guide/dev — для разработчиков (роль admin): README/AGENTS каждого модуля
 *    (Parsedown), манифест, MCP-инструменты, API.
 */

Route::set('nj596_dev', 'guide/dev')
    ->defaults([
        'controller' => 'Nj596_Guide',
        'action'     => 'dev',
    ]);

Route::set('nj596_service', 'guide/<slug>', ['slug' => '[a-z0-9_-]+'])
    ->defaults([
        'controller' => 'Nj596_Guide',
        'action'     => 'service',
    ]);

Route::set('nj596_index', 'guide')
    ->defaults([
        'controller' => 'Nj596_Guide',
        'action'     => 'index',
    ]);
