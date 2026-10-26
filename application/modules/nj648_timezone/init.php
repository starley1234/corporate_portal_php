<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj648_timezone («Созвон по часовым поясам»).
 *
 * Зависимостей нет: модуль stateless (без таблиц, очередей и воркеров).
 */

Route::set('nj648_page', 'timezones')
    ->defaults([
        'controller' => 'Nj648_Timezone',
        'action'     => 'index',
    ]);

Route::set('nj648_api', 'nj648/api(/<action>)')
    ->defaults([
        'controller' => 'Nj648_Api',
        'action'     => 'now',
    ]);
