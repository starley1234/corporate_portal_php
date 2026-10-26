<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг «Подписи и реквизиты PDF» (nj636_pdfsign).
 *
 * Пороги — в одном месте; секреты/ключи — только через .env (NJ636_*).
 */
return [
    'rate' => [
        'per_minute' => (int) (getenv('NJ636_RATE_PER_MINUTE') ?: 5),
        'per_hour'   => (int) (getenv('NJ636_RATE_PER_HOUR') ?: 40),
    ],

    'max_upload_mb'  => (int) (getenv('NJ636_MAX_UPLOAD_MB') ?: 15),
    'max_sign_lines' => (int) (getenv('NJ636_MAX_SIGN_LINES') ?: 48),
];
