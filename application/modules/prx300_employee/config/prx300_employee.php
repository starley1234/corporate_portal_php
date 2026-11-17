<?php defined('SYSPATH') or die('No direct script access.');

return [
    'directory' => [
        'page_size' => 50,
        // Только рабочие поля. Табельный номер, дата найма и внешние ID в
        // общий справочник не попадают.
        'statuses' => ['active', 'on_leave'],
    ],
    'import' => [
        'max_bytes' => max(1024, (int) (getenv('PRX300_IMPORT_MAX_BYTES') ?: 5242880)),
        'max_rows' => max(1, (int) (getenv('PRX300_IMPORT_MAX_ROWS') ?: 5000)),
        'retention_days' => max(1, (int) (getenv('PRX300_IMPORT_RETENTION_DAYS') ?: 180)),
    ],
];
