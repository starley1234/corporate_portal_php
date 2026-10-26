<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля миграций (nj111_migrations).
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
 * Роут для запуска миграций через контроллер Controller_Nj111_Migrate Postgreesql
 */
Route::set('erp111_migrations', 'migrate_pg')
    ->defaults(array(
        'controller' => 'Migrate',
        'action'     => 'index',
        'directory'  => 'Nj111',
    ));

// JSON API: состояние схемы доступно машинам (доступ — право erp111.view)
Route::set('erp111_api', 'migrate_pg/api/<action>')
    ->defaults(array(
        'directory'  => 'Nj111',
        'controller' => 'Api',
        'action'     => 'status',
    ));
