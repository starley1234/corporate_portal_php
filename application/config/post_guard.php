<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Исключения глобального CSRF- guard (`Prx_PostGuard`).
 *
 * Сюда попадают ТОЛЬКО входящие вебхуки внешних систем с собственной
 * подписью (секрет в заголовке, HMAC в URL). Обычные формы и AJAX сюда не
 * добавлять: им нужен токен `csrf`.
 */
return [
    // URI-префиксы входящих вебхуков внешних систем с собственной подписью
    'webhooks' => [
        // Telegram/messenger: подпись X-Telegram-Bot-Api-Secret-Token
        'prx597/hook',
        // Отписка из письма: HMAC в ссылке (Notify::unsub_token)
        'prx597/unsub',
    ],
];
