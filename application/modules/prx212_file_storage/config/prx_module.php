<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx212_file_storage.
 *
 * Создан 28.09.2026: реестр модулей в docs/ARCHITECTURE.md §2 теперь
 * генерируется из манифестов. Страница модуля доступна также в /services.
 */

return [
    'id' => 212,
    'slug' => 'prx212_file_storage',
    'kind' => 'module',
    'status' => 'stable',
    'acl' => 'prx212_storage.view_files',
    'href' => '/prx212_file_storage',
    'nav_paths' => ['/prx212', '/prx212_storage'],
    'icon' => 'fas fa-folder-open',
    'group' => 'Платформа',
    'audience' => 'admin',
    'title' => 'Файловое хранилище',
    'short' => 'локальный и S3 бэкенды',

    // Глобальный поиск (шапка): файлы хранилища по имени (карточка файла).
    'search' => ['file' => [
        'label'  => 'Файлы',
        'icon'   => 'fas fa-folder-open',
        'sort'   => 23,
        'module' => 'prx212_file_storage',
        'scope'  => 'all',
        'sql'    => "SELECT 'file' AS kind, f.filename AS t1, f.mime_type AS t2,"
            ." '' AS sub, '/prx212_file_storage/file/' || f.id AS url, f.created_at"
            ." FROM prx_212_files f"
            ." WHERE f.filename ILIKE :l"
            ." ORDER BY f.created_at DESC LIMIT :per",
    ]],

];
