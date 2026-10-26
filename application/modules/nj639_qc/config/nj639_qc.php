<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг «Контроль качества» (nj639_qc).
 *
 * Пороги/лимиты — в одном месте; секреты — только через .env (NJ639_*).
 */
return [
    'rate' => [
        'per_minute' => (int) (getenv('NJ639_RATE_PER_MINUTE') ?: 5),
        'per_hour'   => (int) (getenv('NJ639_RATE_PER_HOUR') ?: 40),
    ],

    // Максимальный размер принимаемого изображения, мегабайт
    'max_image_mb' => (int) (getenv('NJ639_MAX_IMAGE_MB') ?: 15),

    // Лимит эталонов/если что, вариантов ответа на один прогон
    'max_images' => (int) (getenv('NJ639_MAX_IMAGES') ?: 6),
];
