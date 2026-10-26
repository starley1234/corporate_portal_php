<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг шины событий erp208 (решение владельца 21.09: шина — ЕДИНЫЙ событийный
 * механизм платформы; доставка пользователю — nj597, см. AGENTS.md §3).
 */

return [
    'logging' => [
        // файловый лог шины выключен: журнал событий ведётся в БД
        // (log_published_events), ошибки и так идут в Kohana::$log
        'enabled' => FALSE,
        'level'   => Log::INFO,
        'file'    => 'erp208_bus.log',
    ],

    'event_bus' => [
        // ЖУРНАЛ СОБЫТИЙ: писать каждое publish() в erp_208_events
        // (активность платформы; чистка старых — /nj100/cleanup)
        'log_published_events' => TRUE,

        // Мост в центр уведомлений (nj597): publish() с target_user_id или
        // is_notification уходит в Service_Nj597_Notify::emit(). По умолчанию
        // ВЫКЛЮЧЕН — модуль сам решает, что считать уведомлением, и зовёт
        // Notify::emit() напрямую (см. AGENTS §3 и nj597/AGENTS.md).
        'proxy_to_notification_center' => FALSE,
    ],
];
