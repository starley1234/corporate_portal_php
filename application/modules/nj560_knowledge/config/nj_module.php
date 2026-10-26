<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля nj560_knowledge.
 *
 * Создан 28.09.2026 вместе с пятым контрактом (application/classes/Manifest.php):
 * модуль объявляет свои реестровые записи сам, а не строкой в чужом конфиге.
 * Полей title/href здесь нет намеренно — это не карточка витрины.
 */

return [
    // Назначение модуля: отсюда его берёт реестр в docs/ARCHITECTURE.md §2
    // (bin/console module:list --markdown). Без `href` в меню и на /services
    // модуль не показывается.
    'title' => 'База знаний',
    'short' => 'комбинированный RAG: наработки воркеров, дедуп, векторный поиск через nj115',

    // --- Пятый контракт: источник глобального поиска ----------------------
    // Перенесено 28.09.2026 из Service_Nj100_Search: свою таблицу и свой SQL
    // знает владелец. Плейсхолдеры подставляет агрегатор:
    //   :l — образец для ILIKE, :per — лимит на источник,
    //   :scope — «AND user_id = N» для личных источников (иначе пусто),
    //   :qenc — ссылка с url-кодированным запросом (нужна только базе знаний).
    'search' => [
        'kb' => [
            'label' => 'База знаний',
            'icon'  => 'fas fa-lightbulb',
            'sort'  => 30,
            'scope' => 'all',
            'sql'   => "SELECT 'kb' AS kind, '' AS code, title AS t1, '' AS t2, coalesce(left(summary, 110), left(regexp_replace(content, E'[\\n\\r\\t ]+', ' ', 'g'), 110)) AS sub,
                        :qenc AS url, last_seen_at AS created_at
                 FROM nj_560_docs
                 WHERE title ILIKE :l OR summary ILIKE :l OR content ILIKE :l
                 ORDER BY hits DESC, last_seen_at DESC LIMIT :per",
        ],
    ],
];
