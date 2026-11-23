<?php defined('SYSPATH') or die('No direct script access.');

// Настройки API-шлюза. Секреты — только в .env: PRX150_IP_HASH_KEY.
return [
    // Лимит по умолчанию для нового ключа, запросов в минуту.
    'default_rate_limit' => max(1, (int) (getenv('PRX150_DEFAULT_RATE_LIMIT') ?: 60)),
    // Потолок, который можно выставить ключу из админки.
    'max_rate_limit' => 600,
    // Срок ключа по умолчанию (0 — бессрочно).
    'key_ttl_days' => max(0, (int) (getenv('PRX150_KEY_TTL_DAYS') ?: 0)),
    // Ключ HMAC для хеша IP: без него IP не сохраняем вовсе.
    'ip_hash_key' => (string) (getenv('PRX150_IP_HASH_KEY') ?: ''),
];
