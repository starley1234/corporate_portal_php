<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Справочник организаций» (nj594_orgregistry) для nj110_catalog.
 */

return [
    'id'        => 594,
    'slug'      => 'nj594_orgregistry',
    'title'     => 'Справочник организаций',
    'short'     => 'Единый справочник организаций и ИП (ЕГРЮЛ/ЕГРИП ФНС): реквизиты, статусы, привязка материалов модулей к организации',
    'icon'      => 'fas fa-building-columns',
    'href'      => '/orgs',
    'group'     => 'Данные',
    'sort'      => 594,
    'keywords'  => 'справочник организация предприятие ИП ЕГРЮЛ ЕГРИП ОГРН ИНН КПП ОКВЭД реквизиты реестр ФНС контрагент компания карточка',
    'api'       => ['/orgs/api/search', '/orgs/api/card/<ogrn>'],
    // раздел управления модуля: чип в шапке (только администратору)
    'admin'     => ['title' => 'Справочник · управление', 'href' => '/orgs/admin'],
    'auth'      => FALSE, // открытые данные — страницы доступны гостям

    // --- Пятый контракт: источник глобального поиска ----------------------
    // Перенесено 28.09.2026 из Service_Nj100_Search: свою таблицу и свой SQL
    // знает владелец. Плейсхолдеры подставляет агрегатор:
    //   :l — образец для ILIKE, :per — лимит на источник,
    //   :scope — «AND user_id = N» для личных источников (иначе пусто),
    //   :qenc — ссылка с url-кодированным запросом (нужна только базе знаний).
    'search' => [
        'org' => [
            'label' => 'Организации',
            'icon'  => 'fas fa-landmark',
            'sort'  => 40,
            'scope' => 'all',
            'sql'   => "SELECT 'org' AS kind, '' AS code, short_name AS t1, coalesce(full_name, '') AS t2,
                        trim(both from coalesce(ogrn, '') || ' · ' || coalesce(form, '')) AS sub,
                        '/orgs/' || ogrn AS url, updated_at AS created_at
                 FROM nj_594_orgs
                 WHERE short_name ILIKE :l OR full_name ILIKE :l
                 ORDER BY updated_at DESC LIMIT :per",
        ],
    ],
];
