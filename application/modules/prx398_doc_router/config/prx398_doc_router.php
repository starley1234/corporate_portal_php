<?php defined('SYSPATH') or die('No direct script access.');

$integer = static function ($raw, int $default, int $min, int $max): int {
    $raw = trim((string) $raw);
    if ( ! preg_match('/\A[1-9][0-9]{0,8}\z/', $raw)) return $default;
    $value = (int) $raw;
    return $value >= $min && $value <= $max ? $value : $default;
};
$float = static function ($raw, float $default, float $min, float $max): float {
    $raw = trim((string) $raw);
    if ($raw === '' || ! is_numeric($raw)) return $default;
    $v = (float) $raw;
    return $v >= $min && $v <= $max ? $v : $default;
};
$mib = 1024 ** 2;
$checkpoint = strtolower(trim((string) getenv('PRX398_CHECKPOINT')));
if ( ! in_array($checkpoint, ['postgres', 'memory'], TRUE)) $checkpoint = 'postgres';

return [
    'max_pdf_bytes' => $integer(getenv('PRX398_MAX_PDF_BYTES'), 10 * $mib, $mib, 100 * $mib),
    'max_pages' => $integer(getenv('PRX398_MAX_PAGES'), 20, 1, 200),
    'result_ttl_hours' => $integer(getenv('PRX398_TTL_HOURS'), 24, 1, 168),
    'task_timeout_sec' => $integer(getenv('PRX398_TASK_TIMEOUT_SEC'), 900, 1, 3600),
    'max_attempts' => $integer(getenv('PRX398_MAX_ATTEMPTS'), 3, 1, 10),
    'confidence_min' => $float(getenv('PRX398_CONFIDENCE_MIN'), 0.8, 0.0, 1.0),
    'max_retries' => $integer(getenv('PRX398_MAX_RETRIES'), 2, 0, 10),
    'checkpoint' => $checkpoint,
];
