<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Роуты prl599_account: /account/security (профиль: 2FA, сессии, журнал),
 * /auth/2fa — второй шаг входа (контроллер приложения Controller_Auth).
 */

Route::set('prl599_security', 'account/security')
    ->defaults([
        'controller' => 'Prl599_Security',
        'action'     => 'index',
    ]);

Route::set('prl599_2fa', 'auth/2fa')
    ->defaults([
        'controller' => 'Auth',
        'action'     => '2fa',
    ]);
