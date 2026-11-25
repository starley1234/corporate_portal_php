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

// «Выйти везде»: сторож сессии, который база страницы вызывает через точку
// расширения (docs/SKELETON-ADR.md Д2). Без модуля проверок нет.
Prx_Hooks::on('auth.session_check', function ($user_id) {
    return Service_Prx599_Account::session_valid($user_id);
});
