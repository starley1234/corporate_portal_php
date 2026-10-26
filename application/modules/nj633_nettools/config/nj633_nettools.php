<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг «Сетевые инструменты» (nj633_nettools).
 *
 * Все пороговые значения — в одном месте; секреты — только через .env
 * (NJ633_*), сюда не кладём.
 */
return [
    'rate' => [
        // сколько сетевых проверок в минуту / час разрешено с одного IP.
        // Счётчик — по колонке client_ip таблицы nj_633_checks (не куки).
        'per_minute' => (int) (getenv('NJ633_RATE_PER_MINUTE') ?: 5),
        'per_hour'   => (int) (getenv('NJ633_RATE_PER_HOUR') ?: 60),
    ],

    // WHOIS: публичный сервер и таймаут. Сервер выбран по TLD-ветвлению
    // в Service (по умолчанию IANA → делегирование).
    'whois' => [
        'server'  => (string) (getenv('NJ633_WHOIS_SERVER') ?: 'whois.iana.org'),
        'timeout' => (int) (getenv('NJ633_WHOIS_TIMEOUT') ?: 8),
    ],

    // TCP-проверка доступности (без shell — только stream-сокет).
    'tcp' => [
        'timeout' => (int) (getenv('NJ633_TCP_TIMEOUT') ?: 6),
    ],

    // LLM-опция «объяснить»: модель и путь колбэка для очереди nj120.
    'llm' => [
        'model'         => 'local::qwen3-coder-30b-a3b-instruct',
        'callback_path' => '/net/api/llm_cb',
    ],
];
