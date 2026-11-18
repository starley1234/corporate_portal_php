<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест prx392_analysis — ИИ-разбор входящих.
 *
 * Модуль только предлагает значения. Запись в карточку — отдельное действие
 * человека в prx391; поэтому у канала нет права писать в чужие таблицы.
 */
return [
    'id' => 392,
    'slug' => 'prx392_analysis',
    'title' => 'ИИ-анализ входящих',
    'short' => 'Тип, реквизиты, краткое содержание и риски — предложениями, не приказом',
    'icon' => 'fas fa-wand-magic-sparkles',
    'href' => '/analysis',
    'group' => 'Документооборот',
    'sort' => 392,
    'status' => 'beta',
    'audience' => 'auth',
    'acl' => 'prx392.view',
    'keywords' => 'ии анализ документа тип реквизиты краткое содержание риск извлечение llm',
    'api' => ['/prx392/api/health'],

    'worker' => [
        'channels' => [
            'doc.analyze' => [
                'label' => 'ИИ-разбор входящего документа',
                'url' => '/analysis',
                'reconcile' => "SELECT 1 FROM prx_392_runs r
                                WHERE r.id = (j.payload->>'run_id')::bigint
                                  AND r.status IN ('ready', 'failed')",
            ],
        ],
    ],

    'notify' => [
        'events' => [
            'prx392.analysis.ready' => [
                'title' => 'ИИ-разбор готов',
                'description' => 'Предложения по карточке готовы к проверке человеком.',
                'category' => 'documents',
                'icon' => 'fas fa-wand-magic-sparkles',
                'channels' => 'in_app',
                'roles' => 'docflow_clerk,docflow_lawyer,docflow_manager',
                'digest' => TRUE,
                'placeholders' => 'title,reg_no,confidence,href',
            ],
            'prx392.analysis.failed' => [
                'title' => 'ИИ-разбор не удался',
                'description' => 'Модель не вернула корректный результат; документ остался без изменений.',
                'category' => 'documents',
                'icon' => 'fas fa-triangle-exclamation',
                'channels' => 'in_app',
                'roles' => 'docflow_clerk,docflow_manager',
                'digest' => FALSE,
                'placeholders' => 'title,reg_no,error,href',
            ],
        ],
    ],

    'cleanup' => [
        'prx_392_runs' => [
            'label' => 'prx392 · прогоны ИИ-разбора без предложений',
            'note' => 'Технические прогоны старше срока; принятые предложения и карточки не трогаются.',
            'days' => max(1, (int) (getenv('PRX392_RUN_RETENTION_DAYS') ?: 180)),
            'count_sql' => 'SELECT count(*) FROM prx_392_runs r
                            WHERE r.created_at < now() - :old
                              AND NOT EXISTS (SELECT 1 FROM prx_392_suggestions s WHERE s.run_id = r.id)',
            'delete_sql' => 'DELETE FROM prx_392_runs WHERE id IN (
                                SELECT r.id FROM prx_392_runs r
                                WHERE r.created_at < now() - :old
                                  AND NOT EXISTS (SELECT 1 FROM prx_392_suggestions s WHERE s.run_id = r.id)
                                LIMIT :limit)',
        ],
    ],
];
