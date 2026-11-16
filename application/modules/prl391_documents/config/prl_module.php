<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест prl391_documents — реестр входящих и карточка документа.
 */
return [
    'id' => 391,
    'slug' => 'prl391_documents',
    'title' => 'Реестр входящих',
    'short' => 'Карточка документа, реквизиты, корреспонденты и провенанс полей',
    'icon' => 'fas fa-folder-open',
    'href' => '/documents',
    'group' => 'Документооборот',
    'sort' => 391,
    'status' => 'beta',
    'audience' => 'auth',
    'acl' => 'prl391.view',
    'keywords' => 'реестр документов карточка входящего корреспондент реквизиты номер дата',
    'api' => ['/prl391/api/health'],

    'notify' => [
        'events' => [
            'prl391.document.registered' => [
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
        'prl_391_field_events' => [
            'label' => 'prl391 · история изменения полей',
            'note' => 'Провенанс старше срока хранения; карточка и её текущие значения остаются.',
            'days' => max(1, (int) (getenv('PRL391_FIELD_EVENT_RETENTION_DAYS') ?: 1095)),
            'count_sql' => 'SELECT count(*) FROM prl_391_field_events WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM prl_391_field_events WHERE id IN (
                                SELECT id FROM prl_391_field_events WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
