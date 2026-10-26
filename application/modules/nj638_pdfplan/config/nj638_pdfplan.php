<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг «Конструктор А4» (nj638_pdfplan).
 *
 * Пороги — в одном месте; секреты/ключи — только через .env (NJ638_*).
 */
return [
    'rate' => [
        'per_minute' => (int) (getenv('NJ638_RATE_PER_MINUTE') ?: 5),
        'per_hour'   => (int) (getenv('NJ638_RATE_PER_HOUR') ?: 40),
    ],

    'max_text_chars' => (int) (getenv('NJ638_MAX_TEXT_CHARS') ?: 120000),
];
