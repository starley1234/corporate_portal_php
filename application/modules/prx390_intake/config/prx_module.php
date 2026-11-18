<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест prx390_intake — приём входящих документов.
 *
 * Первый канал — папка на сервере. Почтовый канал появится позже и принесёт
 * тот же конверт, поэтому контракт `doc.normalize` уже общий.
 */
return [
    'id' => 390,
    'slug' => 'prx390_intake',
    'title' => 'Входящие документы',
    'short' => 'Приём PDF из папки, файлы в prx212 и распознанный текст',
    'icon' => 'fas fa-inbox',
    'href' => '/inbox',
    'group' => 'Документооборот',
    'sort' => 390,
    'status' => 'beta',
    'audience' => 'admin',
    'acl' => 'prx390.view',
    'keywords' => 'входящие документы приём папка pdf сканы распознавание текст конверт',
    'api' => ['/prx390/api/health'],

    'worker' => [
        'channels' => [
            'doc.normalize' => [
                'label' => 'Распознавание входящего документа',
                'url' => '/inbox',
                'reconcile' => "SELECT 1 FROM prx_390_texts t
                                WHERE t.message_id = (j.payload->>'message_id')::bigint",
            ],
        ],
    ],

    'notify' => [
        'events' => [
            'prx390.text.failed' => [
                'title' => 'Не удалось распознать документ',
                'description' => 'PDF принят, но текст не извлечён: нужен OCR или другой файл.',
                'category' => 'documents',
                'icon' => 'fas fa-file-circle-exclamation',
                'channels' => 'in_app,email',
                'roles' => 'docflow_clerk,docflow_manager',
                'digest' => FALSE,
                'placeholders' => 'title,reg_no,error,href',
            ],
        ],
    ],

    'cleanup' => [
        'prx_390_texts' => [
            'label' => 'prx390 · распознанный текст старых входящих',
            'note' => 'Текст старше срока хранения; сами конверты и карточки не удаляются.',
            'days' => max(1, (int) (getenv('PRX390_TEXT_RETENTION_DAYS') ?: 365)),
            'count_sql' => 'SELECT count(*) FROM prx_390_texts WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM prx_390_texts WHERE id IN (
                                SELECT id FROM prx_390_texts WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
