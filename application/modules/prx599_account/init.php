<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Роуты prx599_account: /account/security (профиль: 2FA, сессии, журнал),
 * /auth/2fa — второй шаг входа (контроллер приложения Controller_Auth).
 */

Route::set('prx599_security', 'account/security')
    ->defaults([
        'controller' => 'Prx599_Security',
        'action'     => 'index',
    ]);

Route::set('prx599_2fa', 'auth/2fa')
    ->defaults([
        'controller' => 'Auth',
        'action'     => '2fa',
    ]);
