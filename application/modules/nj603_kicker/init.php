<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация «Пиналки» (nj603_kicker).
 *
 * /nj603/admin        — админка: расписания из конфига + состояние пинков;
 * /nj603/kick/<token> — HTTP-пинок (для cron без CLI), токен NJ603_KICK_TOKEN;
 * CLI:                --task=Nj603:Kick (Minion, cron каждые 10 минут).
 * Созревшее расписание выполняется СРАЗУ прямым вызовом сервиса модуля
 * (_fire): в очередь nj120 триггер кладёт только реальную работу
 * (nj602.article / nj606.leads / …) — канала nj603.kick больше нет.
 */

Route::set('nj603_kick', 'nj603/kick(/<token>)')
    ->defaults([
        'controller' => 'Nj603_Kick',
        'action'     => 'index',
    ]);

Route::set('nj603_admin', 'nj603/admin')
    ->defaults([
        'controller' => 'Nj603_Admin',
        'action'     => 'index',
    ]);
