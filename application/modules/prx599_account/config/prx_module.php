<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Аккаунт и безопасность» (prx599_account) для prx110_catalog.
 */

return [
    'audience' => 'auth',
    'acl' => 'prx599.view',
    'status' => 'stable',
    'id'        => 599,
    'slug'      => 'prx599_account',
    'title'     => 'Аккаунт и безопасность',
    'short'     => 'Двухфакторный вход (TOTP), журнал входов, активные сессии и «выйти везде»',
    'icon'      => 'fas fa-shield-halved',
    'href'      => '/prx599_account',
    'group'     => 'Аккаунт',
    'sort'      => 600,
    'hidden'    => TRUE, // аккаунтная страница: из левого меню убрана (доступ по прямой ссылке)
    'keywords'  => 'аккаунт безопасность двухфакторная аутентификация 2FA TOTP код аутентификатор журнал входов сессии выйти везде устройства пароль профиль',
    'api'       => [],
    'auth'      => TRUE,

    // --- Пятый контракт: чистки данных (/prx100/cleanup) ----------------------------
    'cleanup' => [
        'prx599_logins' => [
            'label'      => 'prx599 · журнал входов старше 90 дней',
            'note'       => 'prx599 чистит сам при входе; кнопка — для мгновенной уборки.',
            'days'       => 90,
            'count_sql'  => 'SELECT count(*) FROM prx_599_logins WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM prx_599_logins WHERE id IN (
                                SELECT id FROM prx_599_logins WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
