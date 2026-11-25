<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Роуты prx599_account: /account/security (профиль: 2FA, сессии, журнал),
 * /auth/2fa — второй шаг входа (свой контроллер модуля).
 */

Route::set('prx599_security', 'account/security')
    ->defaults([
        'controller' => 'Prx599_Security',
        'action'     => 'index',
    ]);

// Канонический адрес главной модуля (префикс+слаг): /prx599_account.
// Короткий URL выше остаётся рабочим алиасом.
Route::set('prx599_account_home', 'prx599_account')

    ->defaults([
        'controller' => 'Prx599_Security',
        'action'     => 'index',
    ]);

Route::set('prx599_2fa', 'auth/2fa')
    ->defaults([
        'controller' => 'Prx599_2fa',
        'action'     => 'index',
    ]);

// «Выйти везде»: сторож сессии, который база страницы вызывает через точку
// расширения (docs/SKELETON-ADR.md Д2). Без модуля проверок нет.
Prx_Hooks::on('auth.session_check', function ($user_id) {
    return Service_Prx599_Account::session_valid($user_id);
});

// Второй фактор и журнал входа: ядро (Controller_Auth, Controller_Welcome)
// спрашивает точки расширения вместо прямого вызова Service_Prx599_Account
// (docs/SKELETON-ADR.md Д2/Д7). Без модуля хуки не зарегистрированы —
// Prx_Hooks::call() вернёт NULL, и ядро ведёт себя как при отсутствии модуля
// (2FA не требуется, журнал входа не пишется, ссылка на аккаунт скрыта).

// Пауза после верных логина/пароля: если TOTP включён — гасим парольную
// сессию, кладём пейлоад prx599_pending и отдаём ядру URL второго шага;
// если выключен — NULL, вход завершается как раньше.
Prx_Hooks::on('account.login_stage2', function ($user_id, $remember, $redirect) {
    if ( ! Service_Prx599_Account::totp_enabled((int) $user_id))
    {
        return NULL;
    }

    Auth::instance()->logout();

    Session::instance()->set('prx599_pending', [
        'uid'      => (int) $user_id,
        'remember' => (bool) $remember,
        'redirect' => (string) $redirect,
        'tries'    => 0,
        'ts'       => time(),
    ]);

    return '/auth/2fa';
});

// Журнал входа + регистрация активной сессии (best effort: исключение
// пробрасывается ядру, которое логирует и не рвёт вход).
Prx_Hooks::on('account.after_login', function ($user_id, $via = 'password') {
    return Service_Prx599_Account::after_login((int) $user_id, $via);
});

// Ссылка «Мой аккаунт» на «Рабочем столе» (без модуля ссылки нет).
Prx_Hooks::on('account.security_link', function () {
    return '/account/security';
});
