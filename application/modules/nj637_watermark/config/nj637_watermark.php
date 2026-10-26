<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг «Водяные знаки на PDF» (nj637_watermark).
 *
 * Пороги — в одном месте; секреты/ключи — только через .env (NJ637_*).
 */
return [
    'rate' => [
        'per_minute' => (int) (getenv('NJ637_RATE_PER_MINUTE') ?: 5),
        'per_hour'   => (int) (getenv('NJ637_RATE_PER_HOUR') ?: 40),
    ],

    // приём файлов и текста наложения
    'max_upload_mb'  => (int) (getenv('NJ637_MAX_UPLOAD_MB') ?: 15),
    'max_text_chars' => (int) (getenv('NJ637_MAX_TEXT_CHARS') ?: 400),
];
