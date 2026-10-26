<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id' => 300,
    'slug' => 'prl300_employee',
    'title' => 'Мой профиль сотрудника',
    'short' => 'Рабочий профиль, структура организации и справочник коллег своей организации',
    'icon' => 'fas fa-id-card',
    'href' => '/employee',
    'group' => 'HR и развитие персонала',
    'sort' => 300,
    'status' => 'stable',
    'audience' => 'auth',
    'acl' => 'prl300.view',
    'keywords' => 'сотрудник профиль коллеги справочник подразделения площадки структура холдинга кадры',
    'api' => ['/employee/api/me', '/employee/api/directory'],
    'admin' => [
        'title' => 'Кадровые данные',
        'href' => '/prl300/admin',
        'icon' => 'fas fa-sitemap',
        'note' => 'Структура, сотрудники, сопоставления и импорт',
    ],
    // Глобальный поиск не знает оргконтекста текущего сотрудника, поэтому
    // prl300 сознательно не объявляет секцию search. Справочник модуля сам
    // проверяет организацию до выдачи результатов.
    'cleanup' => [
        'prl300_import_batches' => [
            'label' => 'prl300 · промежуточные пакеты кадрового импорта',
            'note' => 'Пакеты и их строки с кадровыми полями старше срока хранения. Сами сотрудники не удаляются.',
            'days' => max(1, (int) (getenv('PRL300_IMPORT_RETENTION_DAYS') ?: 180)),
            'count_sql' => 'SELECT count(*) FROM prl_300_import_batches WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM prl_300_import_batches WHERE id IN (
                                SELECT id FROM prl_300_import_batches
                                WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
    'health' => [
        'prl_300_import_rows' => [
            'label' => 'prl300 · строка кадрового импорта',
            'code' => 'batch_id::text',
            'href' => '/prl300/admin/import/',
        ],
    ],
];
