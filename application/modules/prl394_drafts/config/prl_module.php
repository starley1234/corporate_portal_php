<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест prl394_drafts — черновики ответов.
 *
 * Автоматической отправки нет. Черновик становится письмом только после
 * явного «Утвердить» и «Отправить» с правом `prl394.send`.
 */
return [
    'id' => 394,
    'slug' => 'prl394_drafts',
    'title' => 'Ответы на письма',
    'short' => 'Шаблоны, черновики ответов и ручная отправка после утверждения',
    'icon' => 'fas fa-reply',
    'href' => '/replies',
    'group' => 'Документооборот',
    'sort' => 394,
    'status' => 'beta',
    'audience' => 'auth',
    'acl' => 'prl394.view',
    'keywords' => 'ответ письмо черновик шаблон претензия отправка утверждение',
    'api' => ['/prl394/api/health'],

    'worker' => [
        'channels' => [
            'doc.draft' => [
                'label' => 'Черновик ответа на входящий',
                'url' => '/replies',
                'reconcile' => "SELECT 1 FROM prl_394_drafts d
                                WHERE d.id = (j.payload->>'draft_id')::bigint
                                  AND d.status <> 'generating'",
            ],
        ],
    ],

    'notify' => [
        'events' => [
            'prl394.draft.ready' => [
                'title' => 'Черновик ответа готов',
                'description' => 'Проект ответа ждёт проверки и утверждения человеком.',
                'category' => 'documents',
                'icon' => 'fas fa-reply',
                'channels' => 'in_app',
                'roles' => 'docflow_lawyer,docflow_manager',
                'digest' => TRUE,
                'placeholders' => 'title,reg_no,href',
            ],
        ],
    ],
];
