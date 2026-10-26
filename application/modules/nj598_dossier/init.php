<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj598_dossier («AI Досье на работодателя»).
 *
 * /dossier — создание и мои досье, /dossier/<code> — результат разведки,
 * /dossier/api/* — статус (публично) и выгрузка воркера (токен).
 * Задача очереди — канал nj598.dossier (workers handlers/nj598_dossier.py).
 */

Route::set('nj598_api', 'dossier/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj598_Api',
        'action'     => 'status',
    ]);

Route::set('nj598_retry', 'dossier/retry/<code>', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj598_Dossier',
        'action'     => 'retry',
    ]);

Route::set('nj598_admin', 'nj598/admin')
    ->defaults([
        'controller' => 'Nj598_Admin',
        'action'     => 'index',
    ]);

Route::set('nj598_stop', 'dossier/stop/<code>', ['code' => '[0-9a-z]+'])
    ->defaults(['controller' => 'Nj598/Dossier', 'action' => 'stop']);

Route::set('nj598_delete', 'dossier/delete/<code>', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj598_Dossier',
        'action'     => 'delete',
    ]);

Route::set('nj598_view', 'dossier/<code>', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj598_Dossier',
        'action'     => 'view',
    ]);

Route::set('nj598_page', 'dossier')
    ->defaults([
        'controller' => 'Nj598_Dossier',
        'action'     => 'index',
    ]);
