<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг nj591_company_profile («Профиль компании»).
 * Воркер читает свои env-аналоги (NJ591_PROFILE_*, WEBREAD_*).
 */

return [
    'code_prefix'    => 'cmp',
    'code_bytes'     => 4,
    'max_query_len'  => 300,     // домен или название компании
    'list_limit'     => 50,
    'admin_limit'    => 100,
];
