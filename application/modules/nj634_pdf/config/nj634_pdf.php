<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг «PDF-инструменты» (nj634_pdf).
 *
 * Все пороговые значения — в одном месте; секреты/ключи — только через .env
 * (NJ634_*), сюда не кладём.
 */
return [
    'rate' => [
        // сколько операций в минуту/час разрешено с одного IP.
        // Счётчик — по nj_634_runs.client_ip (не куки).
        'per_minute' => (int) (getenv('NJ634_RATE_PER_MINUTE') ?: 5),
        'per_hour'   => (int) (getenv('NJ634_RATE_PER_HOUR') ?: 40),
    ],

    // приём файлов
    'max_upload_mb'  => (int) (getenv('NJ634_MAX_UPLOAD_MB') ?: 15),
    'max_text_chars' => (int) (getenv('NJ634_MAX_TEXT_CHARS') ?: 200000),

    // генерация текст → PDF
    'txt2pdf_font' => (string) getenv('NJ634_FONT_TTF'),
];
