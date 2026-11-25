<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Настройки внешнего OCR-шлюза prx450.
 *
 * Один и тот же контракт `/parse` используют боевой шлюз (Docling/MinerU/
 * PaddleOCR) и локальная обёртка над Tesseract. Переключение — только
 * `PRX450_OCR_BASE_URL`, без правки кода.
 *
 * Секреты — только в .env: PRX450_OCR_API_KEY.
 *
 * Фолбэк 25.11.2026: если PRX450_OCR_BASE_URL не задан, берётся общий
 * OCR-эндпоинт воркера OCR_SERVICE_URL (контракт тот же /parse — отрезаем
 * хвост), поэтому «общий» больше не игнорируется этим модулем.
 */

$prx450_general = rtrim((string) getenv('OCR_SERVICE_URL'), '/');
$prx450_derived = ($prx450_general !== '' AND preg_match('~^(https?://.+)/parse/?$~', $prx450_general, $prx450_m))
    ? $prx450_m[1]
    : '';

return [
    'upstream' => [
        // Origin без /parse; путь задаётся отдельно.
        'base_url' => rtrim((string) (getenv('PRX450_OCR_BASE_URL')
            ?: ($prx450_derived ?: 'http://127.0.0.1:8090')), '/'),
        'parse_path' => (string) (getenv('PRX450_OCR_PARSE_PATH') ?: '/parse'),
        'timeout' => max(5, (int) (getenv('PRX450_OCR_TIMEOUT') ?: 120)),
        'connect_timeout' => max(1, (int) (getenv('PRX450_OCR_CONNECT_TIMEOUT') ?: 5)),
        'api_key' => (string) (getenv('PRX450_OCR_API_KEY') ?: getenv('OCR_API_KEY')),
        'auth_header' => (string) (getenv('PRX450_OCR_AUTH_HEADER') ?: 'Authorization'),
        'auth_scheme' => (string) (getenv('PRX450_OCR_AUTH_SCHEME') ?: 'Bearer'),
        'verify_tls' => getenv('PRX450_OCR_VERIFY_TLS') !== '0',
        'follow_redirects' => FALSE,
    ],

    // Параметры запроса по умолчанию; потребитель может переопределить только
    // то, что перечислено в client_overrides.
    'defaults' => [
        'engine'         => (string) (getenv('PRX450_OCR_ENGINE') ?: 'auto'),
        'output_format'  => (string) (getenv('PRX450_OCR_OUTPUT_FORMAT') ?: 'md'),
        'preprocess'     => getenv('PRX450_OCR_PREPROCESS') !== '0',
        'max_image_side' => (int) (getenv('PRX450_OCR_MAX_IMAGE_SIDE') ?: 2048),
        'ocr'            => getenv('PRX450_OCR_OCR') !== '0',
        'extract_images' => getenv('PRX450_OCR_EXTRACT_IMAGES') === '1',
        'vlm_pictures'   => getenv('PRX450_OCR_VLM_PICTURES') === '1',
        'extract_tables' => getenv('PRX450_OCR_EXTRACT_TABLES') !== '0',
        'llm_fix'        => getenv('PRX450_OCR_LLM_FIX') === '1',
        'llm_model'      => (string) (getenv('PRX450_OCR_LLM_MODEL') ?: 'default'),
        'llm_prompt'     => getenv('PRX450_OCR_LLM_PROMPT') ?: NULL,
        'extra_config'   => getenv('PRX450_OCR_EXTRA_CONFIG') ?: NULL,
        'force'          => getenv('PRX450_OCR_FORCE') === '1',
    ],

    'client_overrides' => [
        'engine', 'output_format', 'llm_fix', 'llm_model', 'llm_prompt',
        'preprocess', 'max_image_side', 'ocr', 'extract_images', 'vlm_pictures',
        'extract_tables', 'extra_config', 'force',
    ],

    'limits' => [
        'max_file_bytes' => max(1024, (int) (getenv('PRX450_MAX_FILE_BYTES') ?: 26214400)),
        'max_raw_result_bytes' => max(1024, (int) (getenv('PRX450_MAX_RAW_RESULT_BYTES') ?: 4194304)),
        'max_response_bytes' => max(1024, (int) (getenv('PRX450_MAX_RESPONSE_BYTES') ?: 16777216)),
        'max_content_bytes' => max(1024, (int) (getenv('PRX450_MAX_CONTENT_BYTES') ?: 4194304)),
    ],

    'allowed_mime' => [
        'application/pdf',
        'image/png', 'image/jpeg', 'image/tiff', 'image/webp', 'image/bmp',
    ],
];
