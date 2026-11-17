<?php defined('SYSPATH') or die('No direct script access.');

return [
    'calendar_code'   => 'ru',
    'default_sla_hours' => 120,
    'default_business_days' => true,
    'escalation_hours' => [24, 4],   // T−24ч и T−4ч; просрочка — отдельное событие
    'escalation_interval_minutes' => 30,
    'dashboard_limit' => 60,
];
