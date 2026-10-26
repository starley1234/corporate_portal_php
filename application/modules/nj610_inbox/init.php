<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj610_inbox: «Входящие под контролем».
 *
 * Воркер по расписанию забирает непрочитанные письма из ящика (IMAP,
 * env NJ610_INBOX_*), LLM сортирует и готовит черновики ответов.
 * Владелец просматривает в /inbox и подтверждает ответы.
 *
 * /inbox                 — лента входящих с черновиками (только управление/владелец);
 * /inbox/api/mail        — приём писем от воркера (X-Worker-Token);
 * /inbox/api/drafts      — приём категорий и черновиков.
 */

Route::set('nj610_api', 'inbox/api(/<action>)')
    ->defaults([
        'controller' => 'Nj610_Api',
        'action'     => 'mail',
    ]);

Route::set('nj610_page', 'inbox')
    ->defaults([
        'controller' => 'Nj610_Inbox',
        'action'     => 'index',
    ]);
