<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj635_scout («Разведка контрагента»).
 *
 * /scout/<code> — карта шагов (код проекта sct…), /scout — создание,
 * /check — публичная «Проверка компании»: одна строка ввода → карточка.
 * Порядок: view ВЫШЕ index, как /company/<code> в nj591.
 */

Route::set('nj635_check_api', 'check/api/status')
    ->defaults([
        'controller' => 'Nj635_Check',
        'action'     => 'api_status',
    ]);

Route::set('nj635_check', 'check')
    ->defaults([
        'controller' => 'Nj635_Check',
        'action'     => 'index',
    ]);

Route::set('nj635_scout_view', 'scout/<code>', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj635_Scout',
        'action'     => 'view',
    ]);

Route::set('nj635_scout_index', 'scout')
    ->defaults([
        'controller' => 'Nj635_Scout',
        'action'     => 'index',
    ]);
