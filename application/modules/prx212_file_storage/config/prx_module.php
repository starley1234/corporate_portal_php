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
    'href' => '/prx212',
    'icon' => 'fas fa-folder-open',
    'group' => 'Платформа',
    'audience' => 'admin',
    'title' => 'Файловое хранилище (общий модуль)',
    'short' => 'локальный и S3 бэкенды',
];
