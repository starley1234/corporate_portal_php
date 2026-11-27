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
    // Тот же предел, что у воркера (limits.py: 25 МиБ по умолчанию, 1–200 МиБ):
    // больший PDF воркер отверг бы после ожидания в очереди — отказываем сразу.
    'max_pdf_bytes' => (static function (): int {
        // Разбор — как limits._integer: только целое без знака, вне диапазона — умолчание.
        $raw = trim((string) getenv('PRX399_MAX_PDF_BYTES'), " \t\r\n\v");
        $value = preg_match('/\A[1-9][0-9]{0,8}\z/', $raw) === 1 ? (int) $raw : 0;

        return $value >= 1048576 && $value <= 209715200 ? $value : 26214400;
    })(),
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
