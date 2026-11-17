<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Приём входящих. Первый канал — папка на сервере: так конвейер можно
 * отладить и показать без почтового ящика. Пути по умолчанию лежат в
 * `public_html/storage/docflow` (каталог не попадает в git): на хостинге
 * корень проекта часто недоступен PHP на запись, а docroot — доступен.
 * Прямая отдача этих файлов запрещена правилом в public_html/.htaccess;
 * для nginx тот же запрет нужно повторить в конфигурации сайта.
 * Демонстрационные PDF лежат в demo/ и копируются в папку
 * входящих командой `make docflow-demo`.
 */

$inbox = getenv('PRX390_INBOX_DIR') ?: 'public_html/storage/docflow/inbox';
$processed = getenv('PRX390_PROCESSED_DIR') ?: 'public_html/storage/docflow/processed';
$duplicates = getenv('PRX390_DUPLICATES_DIR') ?: 'public_html/storage/docflow/duplicates';
$error = getenv('PRX390_ERROR_DIR') ?: 'public_html/storage/docflow/error';

// Совместимость с .env, созданным до переноса пути: старые значения по
// умолчанию указывали в корень проекта, куда PHP на хостинге не пишет.
// Учитываем и относительный, и абсолютный вид старого пути.
$legacy = [
    'storage/docflow/inbox'      => 'public_html/storage/docflow/inbox',
    'storage/docflow/processed'  => 'public_html/storage/docflow/processed',
    'storage/docflow/duplicates' => 'public_html/storage/docflow/duplicates',
    'storage/docflow/error'      => 'public_html/storage/docflow/error',
];

if (defined('APPPATH'))
{
    $root = rtrim(dirname(APPPATH), '/');

    foreach (['inbox', 'processed', 'duplicates', 'error'] as $name)
    {
        $legacy[$root.'/storage/docflow/'.$name] = $root.'/public_html/storage/docflow/'.$name;
    }
}

$paths = [
    'inbox_dir'      => $inbox,
    'processed_dir'  => $processed,
    'duplicates_dir' => $duplicates,
    'error_dir'      => $error,
];

foreach ($paths as &$path)
{
    $normalized = rtrim($path, '/');

    if (isset($legacy[$normalized]))
    {
        $path = $legacy[$normalized];
    }
}
unset($path);

return [
    'inbox_dir'       => $paths['inbox_dir'],
    'processed_dir'   => $paths['processed_dir'],
    'duplicates_dir'  => $paths['duplicates_dir'],
    'error_dir'       => $paths['error_dir'],
    'allowed_ext'     => ['pdf'],
    'allowed_mime'    => ['application/pdf', 'application/x-pdf'],
    // prx212 не принимает больше 10 МБ (upload_max_size). Держим тот же
    // потолок здесь, иначе файл прошёл бы скан и молча упал на сохранении.
    'max_bytes'       => 10 * 1024 * 1024,
    'scan_limit'      => 50,
    'min_text_chars'  => 40,
    'page_size'       => 50,
];
