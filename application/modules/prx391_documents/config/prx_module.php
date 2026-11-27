<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест prx391_documents — реестр входящих и карточка документа.
 */
return [
    'id' => 391,
    'slug' => 'prx391_documents',
    'title' => 'Реестр входящих',
    'short' => 'Карточка документа, реквизиты, корреспонденты и провенанс полей',
    'icon' => 'fas fa-folder-open',
    'href' => '/prx391_documents',
    'group' => 'Документооборот',
    'sort' => 391,
    'status' => 'beta',
    'audience' => 'admin',
    'acl' => 'prx391.view',
    'keywords' => 'реестр документов карточка входящего корреспондент реквизиты номер дата',
    'api' => ['/prx391_documents/api/health'],

    'notify' => [
        'events' => [
            'prx391.document.registered' => [
                'title' => 'Входящий документ зарегистрирован',
                'description' => 'Документ получил регистрационный номер и карточку.',
                'category' => 'documents',
                'icon' => 'fas fa-file-circle-plus',
                'channels' => 'in_app',
                'roles' => 'docflow_clerk,docflow_manager',
                'digest' => TRUE,
                'placeholders' => 'title,reg_no,href',
            ],
        ],
    ],

    'cleanup' => [
        'prx_391_field_events' => [
            'label' => 'prx391 · история изменения полей',
            'note' => 'Провенанс старше срока хранения; карточка и её текущие значения остаются.',
            'days' => max(1, (int) (getenv('PRX391_FIELD_EVENT_RETENTION_DAYS') ?: 1095)),
            'count_sql' => 'SELECT count(*) FROM prx_391_field_events WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM prx_391_field_events WHERE id IN (
                                SELECT id FROM prx_391_field_events WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],

    // Глобальный поиск (шапка): карточки входящих документов по теме и рег. номеру.
    'search' => ['document' => [
        'label'  => 'Входящие документы',
        'icon'   => 'fas fa-inbox',
        'sort'   => 21,
        'module' => 'prx391_documents',
        'scope'  => 'all',
        'sql'    => "SELECT 'document' AS kind, d.subject AS t1, d.reg_no AS t2,"
            ." COALESCE(d.status, '') AS sub, '/prx391_documents/' || d.id AS url, d.created_at"
            ." FROM prx_391_documents d"
            ." WHERE d.subject ILIKE :l OR d.reg_no ILIKE :l"
            ." ORDER BY d.created_at DESC LIMIT :per",
    ]],

    // Автоматизация документооборота: шаги включаются флагами PRX390_AUTO_SCAN
    // и PRX391_AUTO_AI (по умолчанию выключены — проход ничего не делает).
    'schedule' => ['documents_auto' => [
        'label'   => 'Автоскан входящих и автозапуск разбора ИИ (по флагам .env)',
        'every'   => '1 minute',
        'command' => 'bin/console documents:auto',
    ]],
];
