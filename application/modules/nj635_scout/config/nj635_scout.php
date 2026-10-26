<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сервис «Разведка контрагента» (nj635_scout).
 * Пороги мягкого лимита маршрутов на пользователя и префикс кодов артефактов.
 */

return [
    // мягкий лимит: сколько маршрутов разведки можно создать на пользователя
    'rate_per_minute' => 3,
    'rate_per_hour'   => 30,

    // длина входа
    'max_query_len'   => 300,

    // код проекта
    'code_prefix' => 'sct',
    'code_bytes'  => 4,

    // список моих маршрутов
    'list_limit'  => 50,
];
