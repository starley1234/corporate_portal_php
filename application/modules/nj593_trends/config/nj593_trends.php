<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг nj593_trends («Тренды GitHub»).
 * Лимиты используются и в UI, и воркером
 * (workers/n2j_workers/handlers/nj593_trends.py читает свои env-аналоги).
 */

return [
    'code_prefix'    => 'trd',
    'code_bytes'     => 4,
    'max_items'      => 12,        // максимум позиций в обзоре
    'default_items'  => 8,
    'max_period'     => 7,         // глубина «свежести»: не старше N дней
    'default_period' => 1,         // «за сутки»
    'min_stars_min'  => 10,        // нижняя граница фильтра звёзд
    'max_focus_len'  => 500,       // «что считать интересным» от пользователя
    'list_limit'     => 30,        // «мои обзоры» на /trends
    'admin_limit'    => 100,
    // языки для фильтра (GitHub language:); пусто = любой
    'languages'      => ['', 'python', 'typescript', 'javascript', 'rust', 'go', 'c++', 'java', 'zig', 'swift', 'kotlin'],
];
