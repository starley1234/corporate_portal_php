<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id' => 300,
    'slug' => 'prx300_employee',
    'title' => 'Мой профиль сотрудника',
    'short' => 'Рабочий профиль, структура организации и справочник коллег своей организации',
    'icon' => 'fas fa-id-card',
    'href' => '/employee',
    'group' => 'HR и развитие персонала',
    'sort' => 300,
    'status' => 'stable',
    'catalog' => FALSE, // Реализован, но ещё не опубликован в рабочем каталоге.
    'audience' => 'auth',
    'acl' => 'prx300.view',
    'keywords' => 'сотрудник профиль коллеги справочник подразделения площадки структура холдинга кадры',
    'api' => ['/employee/api/me', '/employee/api/directory'],
    'admin' => [
        'title' => 'Кадровые данные',
        'href' => '/prx300/admin',
        'icon' => 'fas fa-sitemap',
        'note' => 'Структура, сотрудники, сопоставления и импорт',
    ],
    // Глобальный поиск не знает оргконтекста текущего сотрудника, поэтому
    // prx300 сознательно не объявляет секцию search. Справочник модуля сам
    // проверяет организацию до выдачи результатов.
    'cleanup' => [
        'prx300_import_batches' => [
            'label' => 'prx300 · промежуточные пакеты кадрового импорта',
            'note' => 'Пакеты и их строки с кадровыми полями старше срока хранения. Сами сотрудники не удаляются.',
            'days' => max(1, (int) (getenv('PRX300_IMPORT_RETENTION_DAYS') ?: 180)),
            'count_sql' => 'SELECT count(*) FROM prx_300_import_batches WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM prx_300_import_batches WHERE id IN (
                                SELECT id FROM prx_300_import_batches
                                WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
    'health' => [
        'prx_300_import_rows' => [
            'label' => 'prx300 · строка кадрового импорта',
            'code' => 'batch_id::text',
            'href' => '/prx300/admin/import/',
        ],
    ],
];
