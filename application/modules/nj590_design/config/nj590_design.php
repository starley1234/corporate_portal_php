<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг nj590_design («Дизайн страницы»).
 * Лимиты используются и в UI, и воркером
 * (workers/n2j_workers/handlers/nj590_design.py читает свои env-аналоги).
 */

return [
    'code_prefix'       => 'dsn',
    'code_bytes'        => 4,
    'max_url_len'       => 2000,      // длина присылаемого URL
    'max_wishes_len'    => 4000,      // пожелания к дизайну
    'max_variants'      => 5,         // максимум вариантов за прогон
    'default_variants'  => 3,
    'max_page_bytes'    => 3145728,   // 3 МБ — скачиваемая страница
    'max_variant_bytes' => 2097152,   // 2 МБ — хранимый HTML-вариант
    'storage_dir'       => 'storage/nj590',   // своя папка модуля (от DOCROOT, ВНЕ public_html);
                                          // записываема php-fpm (проверено nj580); создаёт пинок (bin/deploy.sh)
    'list_limit'        => 50,        // «мои прогоны» на /design
    'admin_limit'       => 100,
];
