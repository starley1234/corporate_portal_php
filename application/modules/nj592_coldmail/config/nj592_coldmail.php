<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг nj592_coldmail («Холодное письмо»).
 * Воркер читает свои env-аналоги (NJ592_EMAIL_*, WEBREAD_*).
 */

return [
    'code_prefix'       => 'cld',
    'code_bytes'        => 4,
    'max_company_len'   => 300,
    'max_product_len'   => 2000,   // что вы предлагаете
    'max_audience_len'  => 300,
    'max_variants'      => 5,
    'default_variants'  => 3,
    'list_limit'        => 50,
    'admin_limit'       => 100,
];
