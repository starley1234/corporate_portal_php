<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля миграций (prx111_migrations).
 *
 * ID: 111 (диапазон 100–199, Ядро)
 * Слой: ядро — схема базы данных. Модуль знает, какие миграции применены, а
 *       какие ждут, и умеет их применять.
 *
 * Роуты:
 *   /migrate_pg          — человеческая страница со списком миграций;
 *   /migrate_pg/api/*    — то же машинно, для развёртывания и мониторинга.
 */

/**
 * Роут для запуска миграций через контроллер Controller_Prx111_Migrate Postgreesql
 */
Route::set('prx111_migrations', 'migrate_pg')
    ->defaults(array(
        'controller' => 'Migrate',
        'action'     => 'index',
        'directory'  => 'Prx111',
    ));

// Канонический адрес главной модуля (префикс+слаг): /prx111_migrations.
// Короткий URL выше остаётся рабочим алиасом.
Route::set('prx111_migrations_home', 'prx111_migrations')

    ->defaults(array(
        'controller' => 'Migrate',
        'action'     => 'index',
        'directory'  => 'Prx111',
    ));

// JSON API: состояние схемы доступно машинам (доступ — право prx111.view)
Route::set('prx111_api', 'migrate_pg/api/<action>')
    ->defaults(array(
        'directory'  => 'Prx111',
        'controller' => 'Api',
        'action'     => 'status',
    ));
