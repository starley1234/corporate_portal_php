<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Модуль метрик витрины (nj516_metrics). Не публичный сервис: дашборд только администратору.
 * Считаются только агрегированные просмотры публичных страниц сервисов; без cookie ID, IP,
 * user-agent/referrer и сырых адресов перехода.
 */
return [
    'id'      => 516,
    'slug'    => 'nj516_metrics',
    'title'   => 'Метрики витрины',
    'short'   => 'Обезличенные агрегаты просмотров публичных сервисов и переходов на них',
    'icon'    => 'fas fa-chart-line',
    'href'    => '/nj516/admin',
    'group'   => 'Платформа',
    // 06.10.2026: из левого меню убран в группу «Платформа» — ссылка на странице
    // «Администрирование» (/admin); в каталоге /services и палитре остаётся.
    'menu'       => FALSE,
    'sort'    => 516,
    'audience' => 'admin',
    'status'  => 'stable',
    'admin'   => ['title' => 'Метрики · управление', 'href' => '/nj516/admin'],
    'api'     => ['/nj516/api/hit'],

    // Владелец таблицы объявляет очистку в реестре платформы (nj100/cleanup).
    'cleanup' => [
        'nj516_views' => [
            'label'      => 'nj516 · агрегаты просмотров витрины',
            'note'       => 'Оставляем дневные агрегаты за 400 дней; в строках нет идентификаторов посетителей.',
            'days'       => 400,
            'count_sql'  => 'SELECT count(*) FROM nj_516_views WHERE day < current_date - :old',
            'delete_sql' => 'DELETE FROM nj_516_views WHERE ctid IN (
                                SELECT ctid FROM nj_516_views WHERE day < current_date - :old LIMIT :limit)',
        ],
    ],
];
