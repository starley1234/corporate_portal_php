<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля llm.
 *
 * Создан 28.09.2026 вместе с пятым контрактом (см. application/classes/Manifest.php):
 * модуль объявляет свои каналы очереди сам, а не строкой в чужом конфиге.
 * Полей title/href здесь нет намеренно — это не карточка витрины, в меню и на
 * /services модуль не показывается.
 */

return [
    // Назначение модуля: отсюда его берёт реестр в docs/ARCHITECTURE.md §2
    // (bin/console module:list --markdown). Без `href` в меню и на /services
    // модуль не показывается.
    'title' => 'Фасад LLM',
    'short' => 'провайдеры, vision, JSON-извлечение, tool-calling, лог всех запросов в БД',

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/prx120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'llm.summarize' => [
                'label'     => 'Обработка текста LLM',
                'url'       => '/tasks',
            ],
        ],
    ],
];
