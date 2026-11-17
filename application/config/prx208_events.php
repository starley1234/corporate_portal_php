<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг шины событий prx208 (решение владельца 21.09: шина — ЕДИНЫЙ событийный
 * механизм платформы; доставка пользователю — prx597, см. AGENTS.md §3).
 */

return [
    'logging' => [
        // файловый лог шины выключен: журнал событий ведётся в БД
        // (log_published_events), ошибки и так идут в Kohana::$log
        'enabled' => FALSE,
        'level'   => Log::INFO,
        'file'    => 'prx208_bus.log',
    ],

    'event_bus' => [
        // ЖУРНАЛ СОБЫТИЙ: писать каждое publish() в prx_208_events
        // (активность платформы; чистка старых — /prx100/cleanup)
        'log_published_events' => TRUE,

        // Мост в центр уведомлений (prx597): publish() с target_user_id или
        // is_notification уходит в Service_Prx597_Notify::emit(). По умолчанию
        // ВЫКЛЮЧЕН — модуль сам решает, что считать уведомлением, и зовёт
        // Notify::emit() напрямую (см. AGENTS §3 и prx597/AGENTS.md).
        'proxy_to_notification_center' => FALSE,
    ],
];
