<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP Hub — публичный каталог MCP-серверов и один шлюз для ИИ-агентов.
 *
 * Манифест объявляет себя сам (пятый контракт): канал автосборки, расписание
 * обновления и чистку журнала прогонов. Номера ключей не меняются — они часть
 * состояния в БД.
 */
return [
    'id' => 676,
    'slug' => 'nj676_mcphub',
    'title' => 'MCP Hub — каталог серверов и инструментов',
    'short' => 'Живой каталог MCP-серверов: автосборка из GitHub и поиска, живые проверки, рейтинг надёжности, готовые конфиги для Claude/Cursor и один MCP-эндпоинт для агентов.',
    'icon' => 'fas fa-layer-group',
    'href' => '/mcphub',
    'group' => 'Разработчику',
    'sort' => 676,
    'created_at' => '2026-10-23',
    'updated_at' => '2026-10-23',
    'audience' => 'public',
    'status' => 'beta',
    'keywords' => 'MCP каталог серверы инструменты tools list stdio SSE Streamable HTTP Claude Cursor Windsurf поиск registry шлюз gateway автосборка',
    'api' => ['/mcphub/mcp', '/mcphub/api/search', '/mcphub/api/status'],

    'worker' => [
        'channels' => [
            'nj676.discover' => [
                'label' => 'MCP Hub · автосборка каталога',
                'url' => '/mcphub/admin',
            ],
            'nj676.registry' => [
                'label' => 'MCP Hub · импорт официального реестра',
                'url' => '/mcphub/admin',
            ],
            'nj676.probe' => [
                'label' => 'MCP Hub · проверка живости серверов',
                'url' => '/mcphub',
            ],
            'nj676.translate' => [
                'label' => 'MCP Hub · русские названия и описания',
                'url' => '/mcphub/admin?tab=quality#translate',
            ],
            'nj676.analysis' => [
                'label' => 'MCP Hub · ИИ-анализ каталога',
                'url' => '/mcphub/admin?tab=build#analysis',
            ],
            'nj676.audit' => [
                'label' => 'MCP Hub · полевое исследование серверов (поиск алмазов)',
                'url' => '/mcphub/admin?tab=cards',
            ],
        ],
    ],

    // Расписания в манифесте нет намеренно: триггеры расписаний живут в
    // планировщике nj603 (свой список), а чужой модуль мы не правим. Добор по
    // внешнему таймеру — POST /mcphub/api/tick с X-Worker-Token (см. README);
    // сама кнопка в админке работает всегда.

    'cleanup' => [
        'nj_676_runs' => [
            'label' => 'nj676 · журнал автосборки каталога MCP',
            'note' => 'Прогоны старше 180 дней (карточки каталога остаются).',
            'days' => 180,
            'count_sql' => "SELECT count(*) FROM nj_676_runs
                            WHERE status <> 'running' AND started_at < now() - :old",
            'delete_sql' => "DELETE FROM nj_676_runs WHERE id IN (
                                SELECT id FROM nj_676_runs
                                WHERE status <> 'running' AND started_at < now() - :old
                                LIMIT :limit)",
        ],
        'nj_676_run_items' => [
            'label' => 'nj676 · строки отчётов прогонов автосборки',
            'note' => 'Построчные записи прогонов старше 180 дней.',
            'days' => 180,
            'count_sql' => 'SELECT count(*) FROM nj_676_run_items WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_676_run_items WHERE id IN (
                                SELECT id FROM nj_676_run_items WHERE created_at < now() - :old LIMIT :limit)',
        ],
        'nj_676_search_log' => [
            'label' => 'nj676 · журнал поискового спроса агентов',
            'note' => 'Поисковые запросы старше 180 дней.',
            'days' => 180,
            'count_sql' => 'SELECT count(*) FROM nj_676_search_log WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_676_search_log WHERE id IN (
                                SELECT id FROM nj_676_search_log WHERE created_at < now() - :old LIMIT :limit)',
        ],
        'nj_676_calls' => [
            'label' => 'nj676 · телеметрия вызовов MCP-шлюза',
            'note' => 'Журнал вызовов инструментов старше 90 дней.',
            'days' => 90,
            'count_sql' => 'SELECT count(*) FROM nj_676_calls WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_676_calls WHERE id IN (
                                SELECT id FROM nj_676_calls WHERE created_at < now() - :old LIMIT :limit)',
        ],
        'nj_676_feedback' => [
            'label' => 'nj676 · обратная связь агентов по вызовам',
            'note' => 'Отчёты hub_report_outcome старше 180 дней.',
            'days' => 180,
            'count_sql' => 'SELECT count(*) FROM nj_676_feedback WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_676_feedback WHERE id IN (
                                SELECT id FROM nj_676_feedback WHERE created_at < now() - :old LIMIT :limit)',
        ],
        'nj_676_analysis' => [
            'label' => 'nj676 · история ИИ-анализов каталога',
            'note' => 'Завершённые снимки и отчёты анализа старше 90 дней.',
            'days' => 90,
            'count_sql' => "SELECT count(*) FROM nj_676_analysis
                            WHERE status IN ('done', 'fail') AND created_at < now() - :old",
            'delete_sql' => "DELETE FROM nj_676_analysis WHERE id IN (
                                SELECT id FROM nj_676_analysis
                                WHERE status IN ('done', 'fail') AND created_at < now() - :old
                                LIMIT :limit)",
        ],
        'nj_676_audits' => [
            'label' => 'nj676 · журнал полевых исследований MCP-серверов агентом',
            'note' => 'Исторические записи исследований старше 180 дней (актуальный отчёт остаётся в карточке сервера).',
            'days' => 180,
            'count_sql' => 'SELECT count(*) FROM nj_676_audits WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_676_audits WHERE id IN (
                                SELECT id FROM nj_676_audits WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
