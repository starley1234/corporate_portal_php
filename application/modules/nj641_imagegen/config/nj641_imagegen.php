<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг «Генератор изображений» (nj641_imagegen).
 *
 * Пороги/лимиты — в одном месте; адрес внешнего image-API и все NJ641_*
 * секреты — только через .env воркера. Здесь значения, которые читает сайт
 * (и зеркалит в UI): rate limit, пресеты размеров, порог памяти.
 */
return [
    'rate' => [
        'per_minute' => (int) (getenv('NJ641_RATE_PER_MINUTE') ?: 5),
        'per_hour'   => (int) (getenv('NJ641_RATE_PER_HOUR') ?: 30),
    ],

    // Пресеты размеров (ширина × высота, пиксели).
    'sizes' => [
        512  => [512, 512],
        768  => [768, 768],
        1024 => [1024, 1024],
        1280 => [1280, 1280],
    ],
    'default_size' => (int) (getenv('NJ641_DEFAULT_SIZE') ?: 1024),

    // Лимиты на один запрос.
    'max_input_mb'     => (int) (getenv('NJ641_MAX_INPUT_MB') ?: 10),
    'max_references'   => (int) (getenv('NJ641_MAX_REFERENCES') ?: 3),
    'max_prompt_chars' => (int) (getenv('NJ641_MAX_PROMPT_CHARS') ?: 2000),

    // Проверка видеопамяти бекенда. На сервере память может быть занята
    // другими процессами — воркер спрашивает GET /vram и при нехватке ждёт
    // (включается/выключается флагами, см. NJ641_VRAM_CHECK / NJ641_VRAM_WAIT).
    'vram' => [
        'check'       => ((string) getenv('NJ641_VRAM_CHECK')) !== '0',
        'wait'        => ((string) getenv('NJ641_VRAM_WAIT')) !== '0',
        'min_free_mb' => (int) (getenv('NJ641_VRAM_MIN_FREE_MB') ?: 4096),
        'wait_sec'    => (int) (getenv('NJ641_VRAM_WAIT_SEC') ?: 240),
        'poll_sec'    => (int) (getenv('NJ641_VRAM_POLL_SEC') ?: 20),
    ],
];
