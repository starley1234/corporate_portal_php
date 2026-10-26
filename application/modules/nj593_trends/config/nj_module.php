<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Тренды GitHub» (nj593_trends) для nj110_catalog.
 */

return [
    'id'        => 593,
    'slug'      => 'nj593_trends',
    'title'     => 'Тренды GitHub',
    'short'     => 'Ежедневный обзор трендов и новинок GitHub: самое интересное из свежих репозиториев — с объяснением, что это и зачем вам',
    'icon'      => 'fab fa-github',
    'href'      => '/trends',
    'group'     => 'Разработчику',
    'sort'      => 593,
    'keywords'  => 'github тренды новинки репозитории open source дайджест обзор звёзды разработчик код библиотека проект свежее',
    'api'       => ['/trends/api/status/<code>'],
    // Открытый каталог обзоров живёт на главной модуля (владелец, 16.10.2026:
    // «вместо /trends показывай содержимое /trends/catalog, /trends/catalog —
    // убери») — в карту сайта идёт /trends, отдельного адреса больше нет.
    'sitemap'   => ['/trends'],
    // раздел управления модуля: чип в шапке (только администратору)
    'admin'     => ['title' => 'Тренды · управление', 'href' => '/nj593/admin'],
    'auth'      => TRUE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj593.trends' => [
                'label'     => 'Тренды GitHub',
                'url'       => '/trends/<code>',

                // Обзор трендов GitHub принят сайтом: /trends/api/complete перевёл
                // nj_593_reviews в done (finished_at проставлен) — задача должна быть
                // done.
                'reconcile' => "SELECT 1 FROM nj_593_reviews r
             WHERE r.id = CAST(j.payload->>'review_id' AS bigint)
               AND r.status = 'done'
               AND r.finished_at IS NOT NULL",
            ],
        ],
    ],

    // --- Пятый контракт: расписание пинков --------------------------------
    // Перенесено 28.09.2026 из config/nj603_kicker.php: как часто дёргать
    // собственный эндпоинт, решает владелец, а не планировщик.
    'schedule' => [
        'trends' => [
                'label'   => 'Обзор трендов GitHub (nj593)',
                'every'   => '12 hours',
                'enabled' => TRUE,
                'url'     => '/trends/api/create',
                'payload' => ['period' => '1', 'count' => '8'],
        ],
    ],

    // --- Пятый контракт: своё в ленте «последнее» (/nj100) -----------------------
    'recent' => [
        'nj_593_reviews' => [
            'title'  => "'Тренды и отзывы'",   // SQL-выражение заголовка
            'status' => 'status',
            'base'   => '/trends/',
            'icon'   => 'fas fa-arrow-trend-up',
        ],
    ],

    // --- Шестой контракт: уведомления из модуля ---------------------------
    // Владелец (15.10.2026): «подумай, что добавить в уведомления из модулей:
    // /trends и /graph3d добавь, настрой шаблоны». События читает реестр
    // центра уведомлений (nj597, sync_manifests), шаблоны заводятся один раз —
    // дальше их правят в /nj597/admin?tab=templates, и правки не затираются.
    //
    // Что уведомляем: человек ставит сборку обзора и уходит. Готовый обзор и
    // сорванная сборка — ровно те два исхода, о которых он иначе узнаёт,
    // только заглянув на /trends сам.
    'notify' => [
        'events' => [
            'nj593.review.done' => [
                'title'        => 'Обзор трендов готов',
                'description'  => 'Сборка обзора завершилась: позиции собраны, обзор опубликован на /trends.',
                'category'     => 'content',
                'icon'         => 'fas fa-arrow-trend-up',
                'channels'     => 'in_app,email,telegram,max',
                'roles'        => 'login',
                'digest'       => TRUE,
                'placeholders' => 'title,items,href',
            ],
            'nj593.review.error' => [
                'title'        => 'Обзор трендов не собрался',
                'description'  => 'Сборка обзора прервалась ошибкой. Собранные позиции остаются, сборку можно повторить.',
                'category'     => 'content',
                'icon'         => 'fas fa-triangle-exclamation',
                'channels'     => 'in_app,email',
                'roles'        => 'login',
                'digest'       => FALSE,
                'placeholders' => 'title,error,href',
            ],
        ],

        // Тексты шаблонов «из коробки». Ключ — код события, дальше канал.
        // Подстановки: {{title}}, {{items}}, {{error}}, {{href}}, {{site_url}},
        // {{site_name}}, {{user_name}}, {{settings_url}} (см. Render::with_globals).
        'templates' => [
            'nj593.review.done' => [
                'email' => [
                    'subject' => 'Обзор готов: {{title}}',
                    'format'  => 'text',
                    'body'    => "Здравствуйте, {{user_name}}!\n\nОбзор трендов GitHub готов: {{title}}.\nПозиций в обзоре: {{items}}.\n\nОткрыть обзор: {{site_url}}{{href}}\n\nЕсли уведомления больше не нужны — отключите их здесь: {{settings_url}}",
                ],
                'telegram' => [
                    'subject' => '',
                    'format'  => 'text',
                    'body'    => "✅ Обзор готов: {{title}}\nПозиций: {{items}}\n{{site_url}}{{href}}",
                ],
                'max' => [
                    'subject' => '',
                    'format'  => 'text',
                    'body'    => "Обзор готов: {{title}} — позиций {{items}}. {{site_url}}{{href}}",
                ],
            ],
            'nj593.review.error' => [
                'email' => [
                    'subject' => 'Обзор не собрался: {{title}}',
                    'format'  => 'text',
                    'body'    => "Здравствуйте, {{user_name}}!\n\nСборка обзора «{{title}}» прервалась.\nПричина: {{error}}\n\nСобранные позиции остались — обзор можно открыть и запустить сборку заново: {{site_url}}{{href}}\n\nНастройки уведомлений: {{settings_url}}",
                ],
            ],
        ],
    ],
];
