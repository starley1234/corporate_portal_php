<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Лаборатория LLM» (nj645_llmlab) для nj110_catalog.
 *
 * Группа «Разработчику» схлопывается до 4 пунктов, поэтому у сервиса
 * 'pin' => TRUE: он остаётся в меню всегда (иначе новый пункт уехал бы
 * в «Ещё N …» и владелец его не увидел бы).
 */

return [
    'id'        => 645,
    'slug'      => 'llmlab',
    'title'     => 'Лаборатория LLM',
    'short'     => 'Как языковая модель видит текст: токены и стоимость, следующий токен с температурой, эмбеддинги, разбор промпта и квиз. Знания копятся в базе',
    'icon'      => 'fas fa-flask',
    'href'      => '/llmlab',
    'card_view' => 'nj645/card',
    'group'     => 'Разработчику',
    'sort'      => 645,
    'pin'       => TRUE,
    'badge'     => 'новое',
    'keywords'  => 'llm языковая модель токены токенизация температура top-p сэмплирование эмбеддинги косинус промпт chain-of-thought rag галлюцинации квиз обучение',
    'api'       => [
        '/llmlab/api/tokenize', '/llmlab/api/sample', '/llmlab/api/guess',
        '/llmlab/api/similarity', '/llmlab/api/prompt_check', '/llmlab/api/quiz',
    ],
    'admin'     => ['title' => 'Лаборатория · модерация', 'href' => '/nj645/admin'],
    'auth'      => FALSE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    'worker' => [
        'channels' => [
            'nj645.review' => [
                'label'     => 'Лаборатория LLM: прогон промпта',
                'url'       => '/llmlab',
                'reconcile' => "SELECT 1 FROM nj_645_runs r
             WHERE r.id = (j.payload->>'run_id')::bigint
               AND r.status IN ('done', 'error', 'cancelled')",
            ],
        ],
    ],

    // --- Пятый контракт: чистки данных (/nj100/cleanup) ---------------------
    'cleanup' => [
        'nj_645_runs' => [
            'label'      => 'nj645 · прогоны промптов',
            'note'       => 'Ответы модели по демонстрациям старше 90 дней.',
            'days'       => 90,
            'count_sql'  => 'SELECT count(*) FROM nj_645_runs WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_645_runs WHERE id IN (
                                SELECT id FROM nj_645_runs WHERE created_at < now() - :old LIMIT :limit)',
        ],
        'nj_645_guesses' => [
            'label'      => 'nj645 · попытки угадать токен',
            'note'       => 'Статистика игры «человек против модели» старше 180 дней.',
            'days'       => 180,
            'count_sql'  => 'SELECT count(*) FROM nj_645_guesses WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_645_guesses WHERE id IN (
                                SELECT id FROM nj_645_guesses WHERE created_at < now() - :old LIMIT :limit)',
        ],
        'nj_645_corpus' => [
            'label'      => 'nj645 · отклонённые тексты корпуса',
            'note'       => 'Отклонённые модератором тексты старше 30 дней (в модель они не попадают).',
            'days'       => 30,
            'count_sql'  => 'SELECT count(*) FROM nj_645_corpus WHERE status = \'rejected\' AND created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_645_corpus WHERE id IN (
                                SELECT id FROM nj_645_corpus WHERE status = \'rejected\' AND created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
