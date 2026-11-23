<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты шины событий prx208.
 *
 *   /prx208_events(/<action>(/<id>))            — журнал и подписки (Controller_Prx208_Events)
 *   /prx208_events/api(/<action>(/<id>))        — JSON-API для виджетов (Controller_Prx208_Api)
 *   /prx208_events/widget(/<action>(/<id>))     — HMVC-виджеты (Controller_Prx208_Widget)
 *
 * Слушатели в коде регистрируют сами модули в своих init.php через Event::listen().
 */

Route::set('prx208_events_api', 'prx208_events/api(/<action>(/<id>))')
    ->defaults(array(
        'controller' => 'Prx208_Api',
        'action'     => 'index',
    ));

Route::set('prx208_events_widget', 'prx208_events/widget(/<action>(/<id>))')
    ->defaults(array(
        'controller' => 'Prx208_Widget',
        'action'     => 'latest_events',
    ));

Route::set('prx208_events', 'prx208_events(/<action>(/<id>))')
    ->defaults(array(
        'controller' => 'Prx208_Events',
        'action'     => 'index',
    ));

I18n::load('prx208_events', APPPATH . 'modules/prx208_events/i18n/ru.php');
