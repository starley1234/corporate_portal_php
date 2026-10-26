<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj594_orgregistry («Справочник организаций»).
 *
 * Публичные адреса: /orgs — поиск и список, /orgs/<ogrn> — карточка организации.
 * Управление — /orgs/admin (роль admin). API — /orgs/api/{search,card,refresh,import}.
 * Таблицы: nj_594_* (ensure_tables + миграция 001).
 * Хаб для модулей «про организацию»: nj580 и другие привязывают артефакты
 * через Service_Nj594_Orgs::link(), карточка показывает все связи.
 * Полный импорт открытых данных ЕГРЮЛ/ЕГРИП — воркером через /orgs/api/import
 * (X-Worker-Token), порциями ≤500 карточек, без больших файлов на диске сайта.
 */

// админка и API — ВЫШЕ карточки /orgs/<ogrn>, иначе «admin»/«api» съедаются как ОГРН
Route::set('nj594_admin', 'orgs/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj594_Admin',
        'action'     => 'index',
    ]);

Route::set('nj594_lookup', 'orgs/lookup')
    ->defaults([
        'controller' => 'Nj594_Orgs',
        'action'     => 'lookup',
    ]);

Route::set('nj594_api', 'orgs/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj594_Api',
        'action'     => 'search',
    ]);

Route::set('nj594_view', 'orgs/<ogrn>', ['ogrn' => '\d{13,15}'])
    ->defaults([
        'controller' => 'Nj594_Orgs',
        'action'     => 'view',
    ]);

Route::set('nj594_page', 'orgs')
    ->defaults([
        'controller' => 'Nj594_Orgs',
        'action'     => 'index',
    ]);
