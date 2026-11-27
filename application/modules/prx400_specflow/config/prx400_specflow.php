<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Настройки prx400_specflow. Числовые пределы — env с границами (как prx397):
 * мусор или выход за границы → значение по умолчанию, а не «тихий» ноль.
 * Читать только через Service_Prx400_Limits.
 */
$integer = static function ($raw, int $default, int $min, int $max): int {
    $raw = trim((string) $raw);
    if ( ! preg_match('/\A[0-9]{1,9}\z/', $raw)) return $default;
    $value = (int) $raw;
    return $value >= $min && $value <= $max ? $value : $default;
};
$mib = 1024 ** 2;

return [
    // Пороги доверия матчинга с НСИ (Confidence Scoring):
    //  >= auto_accept  — автоматическая привязка кода номенклатуры;
    //  >= manual_review — на подтверждение оператору (подсказки top-K);
    //  ниже            — «новая позиция» (заведение в НСИ).
    'match' => [
        'auto_accept'   => 0.92,
        'manual_review' => 0.65,
        'top_k'         => 3,
        // Справочник больше порога — кандидатов на строку предотбирает
        // PostgreSQL (pg_trgm), а не PHP-перебор всего каталога.
        'full_scan_max' => $integer(getenv('PRX400_MATCH_FULL_SCAN_MAX'), 2000, 0, 100000),
        'candidates'    => 50,
    ],

    // Режимы разбора (payload parse_mode): ocr — детерминированный конвейер
    // без LLM; ocr_vision — OCR с vision-фолбэком при пустом результате;
    // vision — мультимодальный разбор страниц (сканы, где OCR даёт 0).
    'parse_modes' => ['ocr', 'ocr_vision', 'vision'],
    'parse_mode_default' => 'ocr',

    // Пределы приёмки и протокола воркера. PRX400_MAX_PDF_BYTES/PRX400_MAX_ROWS
    // читает и воркер (handlers/prx400_specflow/limits.py) — одно значение.
    'max_pdf_bytes'    => $integer(getenv('PRX400_MAX_PDF_BYTES'), 20 * $mib, $mib, 100 * $mib),
    // Многофайловая спецификация (лист + приложения, BOM по частям): файлов в
    // одной спецификации и их общий объём. Воркер разбирает их по порядку.
    'max_files'        => $integer(getenv('PRX400_MAX_FILES'), 10, 1, 50),
    'max_total_bytes'  => $integer(getenv('PRX400_MAX_TOTAL_BYTES'), 60 * $mib, $mib, 500 * $mib),
    'max_rows'         => $integer(getenv('PRX400_MAX_ROWS'), 2000, 10, 100000),
    'max_result_bytes' => $integer(getenv('PRX400_MAX_RESULT_BYTES'), 8 * $mib, $mib, 64 * $mib),
    'task_timeout_sec' => $integer(getenv('PRX400_TASK_TIMEOUT_SEC'), 1800, 60, 7200),
    'max_attempts'     => $integer(getenv('PRX400_MAX_ATTEMPTS'), 2, 1, 10),
    // Воркер замолчал посреди разбора (упал, перезапущен, завис) — prx400:purge
    // сам ставит разбор заново до N раз; 0 — сразу «ошибка worker_lost».
    // Падение воркера на одном и том же файле (worker_crash_loop) не повторяется.
    'crash_retries'    => $integer(getenv('PRX400_CRASH_RETRIES'), 1, 0, 3),

    // Импорт справочника НСИ из CSV/XLSX (Service_Prx400_CatalogImport):
    // строк данных в файле и размер файла. Файл больше — делится на части
    // (импорт не удаляет позиции, которых нет в файле).
    'catalog_import_max_rows'  => $integer(getenv('PRX400_CATALOG_IMPORT_MAX_ROWS'), 20000, 10, 200000),
    'catalog_import_max_bytes' => $integer(getenv('PRX400_CATALOG_IMPORT_MAX_BYTES'), 10 * $mib, $mib, 64 * $mib),

    // Хранение: 0 — спецификации живут бессрочно (это рабочие документы
    // для ERP); N > 0 — prx400:purge удаляет завершённые (done/failed)
    // старше N дней вместе с файлом.
    'retention_days'   => $integer(getenv('PRX400_RETENTION_DAYS'), 0, 0, 3650),

    // Справочник НСИ — подключаемый источник (Prx400_Catalog_Provider):
    //  local — таблица prx_400_catalog (импорт CSV/XLSX, правка в UI);
    //  http  — внешний справочник по REST-контракту (README, «Внешний НСИ»):
    //          только чтение; память сопоставлений и матчинг — те же.
    'catalog' => [
        // Неизвестное значение не подменяется на local: фабрика ответит catalog_config.
        'provider'   => trim((string) getenv('PRX400_CATALOG_PROVIDER')) ?: 'local',
        'url'        => trim((string) getenv('PRX400_CATALOG_URL')),
        'token'      => trim((string) getenv('PRX400_CATALOG_TOKEN')),
        'timeout_ms' => $integer(getenv('PRX400_CATALOG_TIMEOUT_MS'), 3000, 200, 30000),
        // Кеш ответов http-источника в БД: свежесть и «устаревшее при сбое».
        'cache_ttl'  => $integer(getenv('PRX400_CATALOG_CACHE_TTL'), 3600, 0, 604800),
        'stale_ttl'  => $integer(getenv('PRX400_CATALOG_STALE_TTL'), 604800, 0, 7776000),
    ],

    // Webhook API-ключа (prx_400_hooks): доставка — bin/console prx400:hooks
    // каждую минуту, подпись HMAC-SHA256. Адрес — только https и только
    // публичный IP (SSRF); http/частные сети — явным флагом для стендов.
    'hooks' => [
        'timeout_ms'    => $integer(getenv('PRX400_HOOK_TIMEOUT_MS'), 5000, 500, 30000),
        'max_attempts'  => $integer(getenv('PRX400_HOOK_MAX_ATTEMPTS'), 8, 1, 20),
        'allow_http'    => getenv('PRX400_HOOK_ALLOW_HTTP') === '1',
        'allow_private' => getenv('PRX400_HOOK_ALLOW_PRIVATE') === '1',
    ],

    // Массовое подтверждение: строки с кодом НСИ и скором не ниже порога.
    'bulk_verify_min' => 0.80,
];
