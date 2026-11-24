<?php defined('SYSPATH') or die('No direct script access.');

// Keep defaults and ranges in sync with the worker limits.py and .env.example.
$integer = static function ($raw, int $default, int $min, int $max): int {
    $raw = trim((string) $raw);
    if ( ! preg_match('/\A[1-9][0-9]{0,8}\z/', $raw)) return $default;
    $value = (int) $raw;
    return $value >= $min && $value <= $max ? $value : $default;
};
$mib = 1024 ** 2;

return [
    'max_pdf_bytes' => $integer(getenv('PRX395_MAX_PDF_BYTES'), 10 * $mib, $mib, 100 * $mib),
    'max_pages' => $integer(getenv('PRX395_MAX_PAGES'), 20, 1, 200),
    'max_text_bytes' => $integer(getenv('PRX395_MAX_TEXT_BYTES'), $mib, 1024, 8 * $mib),
    'result_ttl_hours' => $integer(getenv('PRX395_RESULT_TTL_HOURS'), 24, 1, 168),
    'task_timeout_sec' => $integer(getenv('PRX395_TASK_TIMEOUT_SEC'), 300, 1, 3600),
    'max_attempts' => $integer(getenv('PRX395_MAX_ATTEMPTS'), 3, 1, 10),
    'pdf_memory_mb' => $integer(getenv('PRX395_PDF_MEMORY_MB'), 384, 64, 4096),
];
