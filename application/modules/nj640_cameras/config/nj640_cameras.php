<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг «Камеры цеха» (nj640_cameras).
 *
 * Пороги/лимиты — в одном месте; секреты — только через .env (NJ640_*).
 * Значения по умолчанию аккуратны: камеры генерируют много кадров, поэтому
 * событийные лимиты консервативнее, чем у разовых сервисов.
 */
return [
    'rate' => [
        'per_minute' => (int) (getenv('NJ640_RATE_PER_MINUTE') ?: 5),
        'per_hour'   => (int) (getenv('NJ640_RATE_PER_HOUR') ?: 40),
    ],

    // Максимальный размер принимаемого кадра, мегабайт
    'max_image_mb' => (int) (getenv('NJ640_MAX_IMAGE_MB') ?: 15),

    // Лимиты на один аккаунт: сценарных правил и камер
    'max_scenes' => (int) (getenv('NJ640_MAX_SCENES') ?: 20),
    'max_cameras' => (int) (getenv('NJ640_MAX_CAMERAS') ?: 12),

    // Дедупликация: одинаковое событие с одной камеры не старше окна — тихо
    // (обновляется only last_run_at, новое событие не заводится).
    'dedupe_window_sec' => (int) (getenv('NJ640_DEDUPE_WINDOW_SEC') ?: 600),

    // Минимальный интервал между похожими тревогами (cooldown), секунды
    'cooldown_min_sec' => (int) (getenv('NJ640_COOLDOWN_MIN_SEC') ?: 300),

    // Таймаут захвата кадра на воркере, секунды
    'capture_timeout' => (int) (getenv('NJ640_CAPTURE_TIMEOUT') ?: 15),
];
