<?php defined('SYSPATH') OR die('No direct script access.');

return array(
    /**
     * Модуль: Абстракция хранилища (Storage)
     * ID: 212
     * Slug: storage
     */
    
    // Разрешенные MIME-типы для загрузки
    'allowed_mime_types' => array(
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/x-rar-compressed',
        'application/octet-stream',
    ),
    
    // Максимальный размер файла (10 MB)
    'upload_max_size' => 10485760,
	
	'max_file_size' => 10485760,
	
    
    // Антивирус при записи файла (clamd, протокол INSTREAM). Режимы:
    //   off      — не проверять (по умолчанию, пока clamd не поставлен);
    //   soft     — заражённый файл отклонить, clamd недоступен — принять и записать WARNING;
    //   required — заражённый отклонить, clamd недоступен — тоже отклонить (fail closed).
    // Адрес: unix:///run/clamav/clamd.ctl или tcp://127.0.0.1:3310.
    'antivirus' => array(
        'mode'    => getenv('PRX_CLAMAV_MODE') ?: 'off',
        'address' => getenv('PRX_CLAMAV_ADDRESS') ?: 'unix:///run/clamav/clamd.ctl',
        'timeout' => 30,
        'chunk'   => 65536,
    ),

    // Настройки путей для локального хранилища
    'local_storage_paths' => array(
        'default' => dirname(APPPATH) . '/storage/prx212_file_storage/files',
    ),
    
    // Настройки S3 драйвера по умолчанию
    's3_defaults' => array(
        'region' => 'us-east-1',
        'version' => 'latest',
        'acl' => 'private',
    ),
);