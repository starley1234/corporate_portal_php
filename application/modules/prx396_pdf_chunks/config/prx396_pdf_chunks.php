<?php defined('SYSPATH') or die('No direct script access.');

// Keep defaults and ranges in sync with the worker limits and .env.example.
$integer = static function ($raw, int $default, int $min, int $max): int {
    $raw = trim((string) $raw);
    if ( ! preg_match('/\A[1-9][0-9]{0,8}\z/', $raw)) return $default;
    $value = (int) $raw;
    return $value >= $min && $value <= $max ? $value : $default;
};
$mib = 1024 ** 2;

return [
    'max_pdf_bytes' => $integer(getenv('PRX396_MAX_PDF_BYTES'), 100 * $mib, $mib, 500 * $mib),
    'max_pages' => $integer(getenv('PRX396_MAX_PAGES'), 500, 1, 2000),
    'chunk_pages' => $integer(getenv('PRX396_CHUNK_PAGES'), 10, 1, 100),
    'max_text_bytes' => $integer(getenv('PRX396_MAX_TEXT_BYTES'), 50 * $mib, 1024, 200 * $mib),
    'result_ttl_hours' => $integer(getenv('PRX396_TTL_HOURS') ?: getenv('PRX396_RESULT_TTL_HOURS'), 72, 1, 720),
    'task_timeout_sec' => $integer(getenv('PRX396_TASK_TIMEOUT_SEC'), 1800, 1, 7200),
    'max_attempts' => $integer(getenv('PRX396_MAX_ATTEMPTS'), 3, 1, 10),
    'pdf_memory_mb' => $integer(getenv('PRX396_PDF_MEMORY_MB'), 512, 64, 4096),
    // Таймаут изолированного pypdf (проба страниц, нарезка части), секунды.
    'probe_timeout_sec' => $integer(getenv('PRX396_PROBE_TIMEOUT_SEC'), 60, 5, 600),
];
