<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Интерактивная карта сервисов N2J.
 *
 * Карта — дополнительный вход в каталог, поэтому старые /services, сайдбар
 * и палитра Ctrl+K не заменяются и не меняют своих адресов.
 */
Route::set('nj655_orbit', 'orbit')
    ->defaults([
        'controller' => 'Nj655_Nav3d',
        'action'     => 'index',
    ]);
