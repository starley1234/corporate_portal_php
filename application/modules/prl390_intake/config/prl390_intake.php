<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Приём входящих. Первый канал — папка на сервере: так конвейер можно
 * отладить и показать без почтового ящика. Пути по умолчанию лежат в
 * storage/ (каталог не попадает в git); демонстрационные PDF лежат в
 * examples/docflow/ и копируются в папку входящих командой `make docflow-demo`.
 */
return [
    'inbox_dir'       => getenv('PRL390_INBOX_DIR') ?: 'storage/docflow/inbox',
    'processed_dir'   => getenv('PRL390_PROCESSED_DIR') ?: 'storage/docflow/processed',
    'duplicates_dir'  => getenv('PRL390_DUPLICATES_DIR') ?: 'storage/docflow/duplicates',
    'error_dir'       => getenv('PRL390_ERROR_DIR') ?: 'storage/docflow/error',
    'allowed_ext'     => ['pdf'],
    'allowed_mime'    => ['application/pdf', 'application/x-pdf'],
    // prl212 не принимает больше 10 МБ (upload_max_size). Держим тот же
    // потолок здесь, иначе файл прошёл бы скан и молча упал на сохранении.
    'max_bytes'       => 10 * 1024 * 1024,
    'scan_limit'      => 50,
    'min_text_chars'  => 40,
    'page_size'       => 50,
];
