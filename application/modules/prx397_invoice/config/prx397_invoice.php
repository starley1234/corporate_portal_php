<?php defined('SYSPATH') or die('No direct script access.');

$integer = static function ($raw, int $default, int $min, int $max): int {
    $raw = trim((string) $raw);
    if ( ! preg_match('/\A[1-9][0-9]{0,8}\z/', $raw)) return $default;
    $value = (int) $raw;
    return $value >= $min && $value <= $max ? $value : $default;
};
$mib = 1024 ** 2;

return [
    'max_pdf_bytes' => $integer(getenv('PRX397_MAX_PDF_BYTES'), 10 * $mib, $mib, 100 * $mib),
    'max_pages' => $integer(getenv('PRX397_MAX_PAGES'), 20, 1, 200),
    'max_llm_chars' => $integer(getenv('PRX397_MAX_LLM_CHARS'), 60000, 1000, 500000),
    'result_ttl_hours' => $integer(getenv('PRX397_TTL_HOURS'), 24, 1, 168),
    'task_timeout_sec' => $integer(getenv('PRX397_TASK_TIMEOUT_SEC'), 600, 1, 3600),
    'max_attempts' => $integer(getenv('PRX397_MAX_ATTEMPTS'), 3, 1, 10),
];
