<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест prx399_docmerge — слияние договоров и допсоглашений (DocMergeAI).
 *
 * Пайплайн «Zero-Config»: сканы договора + цепочки ДС → AST → дельты ДС
 * (LLM, изолированно по каждому ДС) → детерминированная сборка (PHP, без LLM)
 * → риск-аудит (Map-Reduce). Модель предлагает только инструкции; текст
 * договора собирает код — «галлюцинации» в итоговый документ попасть не могут.
 *
 * Два канала очереди (prx120):
 *   doc.merge.delta — один файл: OCR + AST (base) или дельты ДС (llm);
 *   doc.merge.audit — собранный договор: тематический Map-Reduce рисков.
 */
return [
    'id' => 399,
    'slug' => 'prx399_docmerge',
    'title' => 'Слияние договоров (DocMergeAI)',
    'short' => 'Сканы договора и ДС → актуальная редакция + карта рисков; сборку делает код, а не модель',
    'icon' => 'fas fa-file-contract',
    'href' => '/prx399_docmerge',
    'group' => 'Документооборот',
    'sort' => 399,
    'status' => 'beta',
    'audience' => 'admin',
    'acl' => 'prx399.view',
    'keywords' => 'договор допсоглашение слияние консолидация дельты редакция риски аудит llm docmerge',
    'api' => ['/prx399/api/health'],

    'worker' => [
        'channels' => [
            'doc.merge.delta' => [
                'label' => 'Файл пакета: OCR + AST или дельты ДС',
                'url' => '/prx399_docmerge',
                'reconcile' => "SELECT 1 FROM prx_399_files f
                                WHERE f.id = (j.payload->>'file_id')::bigint
                                  AND f.status IN ('parsed', 'failed')",
            ],
            'doc.merge.audit' => [
                'label' => 'Риск-аудит собранного договора',
                'url' => '/prx399_docmerge',
                'reconcile' => "SELECT 1 FROM prx_399_contracts c
                                WHERE c.id = (j.payload->>'contract_id')::bigint
                                  AND c.status IN ('audited', 'failed')",
            ],
        ],
    ],
];
