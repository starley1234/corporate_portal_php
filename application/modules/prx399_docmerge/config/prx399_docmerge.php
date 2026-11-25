<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг prx399_docmerge. Лимиты держим в ОДНОМ месте для PHP; воркер читает
 * те же лимиты из окружения (PRX399_*, см. .env.example и limits.py) —
 * значения и диапазоны синхронизированы тестом test_env_limits.py.
 */
return [
    // Спека модели для дельт/аудита: 'провайдер::модель' (llm.py); '' = модель
    // провайдера по умолчанию. Держим в env, чтобы крутить без деплоя.
    'model' => (string) getenv('PRX399_MODEL'),

    'temperature' => 0.0, // юридический текст: без креатива
    'max_tokens' => 4000,

    'max_files' => 12, // базовый договор + до 11 ДС
    'max_title' => 200,
    'max_policy' => 4000,

    'focus' => [
        'balanced' => 'Сбалансированный двусторонний анализ',
        'customer' => 'Аудит за Заказчика/Арендатора/Покупателя',
        'contractor' => 'Аудит за Исполнителя/Арендодателя/Поставщика',
    ],

    'statuses' => [
        'draft' => ['Черновик', 'prx-badge-wait', 'fa-pen-ruler'],
        'parsing' => ['Распознавание и дельты ДС', 'prx-badge-run', 'fa-robot'],
        'auditing' => ['Собран — идёт риск-аудит', 'prx-badge-run', 'fa-magnifying-glass-chart'],
        'audited' => ['Готово: редакция + карта рисков', 'prx-badge-ok', 'fa-circle-check'],
        'failed' => ['Ошибка пайплайна', 'prx-badge-err', 'fa-triangle-exclamation'],
    ],

    'file_statuses' => [
        'queued' => ['В очереди', 'prx-badge-wait'],
        'working' => ['Обрабатывается', 'prx-badge-run'],
        'parsed' => ['Готов', 'prx-badge-ok'],
        'failed' => ['Ошибка', 'prx-badge-err'],
    ],
];
