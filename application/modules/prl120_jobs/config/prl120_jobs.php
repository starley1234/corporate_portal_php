<?php defined('SYSPATH') or die('No direct script access.');
return [
    'db_group' => NULL,
    'notify_enabled' => TRUE,
    'defaults' => ['priority' => 100, 'max_attempts' => 3, 'timeout_sec' => 600],
    'silent_stop_channels' => [],
    // Каналы описываются владельцем в config/prl_module.php → worker.channels.
];
