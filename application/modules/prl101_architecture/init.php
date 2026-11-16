<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты документации архитектуры.
 *
 *   /architecture           — собранная страница (HTML)
 *   /architecture/data.json — та же модель для программ (JSON)
 */
Route::set('prl101_architecture_data', 'architecture/data.json')
    ->defaults([
        'directory'  => 'Prl101',
        'controller' => 'Architecture',
        'action'     => 'data',
    ]);

Route::set('prl101_architecture', 'architecture')
    ->defaults([
        'directory'  => 'Prl101',
        'controller' => 'Architecture',
        'action'     => 'index',
    ]);
