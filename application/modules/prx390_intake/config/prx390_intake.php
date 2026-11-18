<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Приём входящих документов. Рабочие каталоги находятся в корневом storage,
 * вне public_html. Старые значения public_html/storage из существующего .env
 * разрешаются в новый путь; физический перенос файлов выполняется отдельно
 * после остановки записи (см. docs/ENVIRONMENT.md).
 */

return [
    'inbox_dir'       => Storage_Path::relocate_legacy(getenv('PRX390_INBOX_DIR') ?: 'storage/docflow/inbox'),
    'processed_dir'   => Storage_Path::relocate_legacy(getenv('PRX390_PROCESSED_DIR') ?: 'storage/docflow/processed'),
    'duplicates_dir'  => Storage_Path::relocate_legacy(getenv('PRX390_DUPLICATES_DIR') ?: 'storage/docflow/duplicates'),
    'error_dir'       => Storage_Path::relocate_legacy(getenv('PRX390_ERROR_DIR') ?: 'storage/docflow/error'),
    'allowed_ext'     => ['pdf'],
    'allowed_mime'    => ['application/pdf', 'application/x-pdf'],
    // prx212 не принимает больше 10 МБ (upload_max_size). Держим тот же
    // потолок здесь, иначе файл прошёл бы скан и молча упал на сохранении.
    'max_bytes'       => 10 * 1024 * 1024,
    'scan_limit'      => 50,
    'min_text_chars'  => 40,
    'page_size'       => 50,
];
