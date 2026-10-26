<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Роуты nj599_account: /account/security (профиль: 2FA, сессии, журнал),
 * /auth/2fa — второй шаг входа (контроллер приложения Controller_Auth).
 */

Route::set('nj599_security', 'account/security')
    ->defaults([
        'controller' => 'Nj599_Security',
        'action'     => 'index',
    ]);

Route::set('nj599_2fa', 'auth/2fa')
    ->defaults([
        'controller' => 'Auth',
        'action'     => '2fa',
    ]);
