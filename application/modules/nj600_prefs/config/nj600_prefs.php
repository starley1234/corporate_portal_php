<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг nj600_prefs. Env NJ600_RAW_TTL_DAYS (см. .env.example) перекрывает
 * raw_ttl_days — задан в bootstrap-обвязке модуля не нужен: читаем getenv тут.
 */

$ttl = (int) getenv('NJ600_RAW_TTL_DAYS');

return [
    // сколько дней хранить СЫРЫЕ события (nj_600_events); суммаризация не чистится
    'raw_ttl_days'  => $ttl > 0 ? $ttl : 21,

    // Не пишем событие чаще этого интервала с одного браузера: сбор
    // предпочтений не должен добавлять запросы к БД на каждый быстрый переход.
    'track_min_interval' => max(0, (int) getenv('NJ600_TRACK_MIN_INTERVAL') ?: 60),

    // капы компактного профиля — БД не должна разбухать
    'sections_cap'  => 40,
    'phrases_cap'   => 20,
    'ref_hosts_cap' => 10,

    // разделы, которые не считаем интересами (служебные/шумные)
    'sections_ignore' => [
        'auth', 'nj597', 'prefs', 'nj600', 'search', 'update', 'migrate_pg',
        'robots', 'cron', 'favicon.ico',
    ],
];
