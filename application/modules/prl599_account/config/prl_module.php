<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Аккаунт и безопасность» (prl599_account) для prl110_catalog.
 */

return [
    'id'        => 599,
    'slug'      => 'prl599_account',
    'title'     => 'Аккаунт и безопасность',
    'short'     => 'Двухфакторный вход (TOTP), журнал входов, активные сессии и «выйти везде»',
    'icon'      => 'fas fa-shield-halved',
    'href'      => '/account/security',
    'group'     => 'Аккаунт',
    'sort'      => 600,
    'hidden'    => TRUE, // аккаунтная страница: из левого меню убрана (доступ по прямой ссылке)
    'keywords'  => 'аккаунт безопасность двухфакторная аутентификация 2FA TOTP код аутентификатор журнал входов сессии выйти везде устройства пароль профиль',
    'api'       => [],
    'auth'      => TRUE,

    // --- Пятый контракт: чистки данных (/prl100/cleanup) ----------------------------
    'cleanup' => [
        'prl599_logins' => [
            'label'      => 'prl599 · журнал входов старше 90 дней',
            'note'       => 'prl599 чистит сам при входе; кнопка — для мгновенной уборки.',
            'days'       => 90,
            'count_sql'  => 'SELECT count(*) FROM prl_599_logins WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM prl_599_logins WHERE id IN (
                                SELECT id FROM prl_599_logins WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
