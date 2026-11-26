<?php defined('SYSPATH') or die('No direct script access.');

// Страница администрирования ключами и использования. Машинные API модулей
// живут в /api/v1/<domain>/... и наследуют Controller_Prx150_Machine.
Route::set('prx150_api_hub', 'prx150_api_hub(/<action>(/<id>))', [
    'action' => 'index|create|revoke|usage|docs|services|edit|save|revoked_list',
    'id' => '[1-9][0-9]{0,17}',
])->defaults(['controller' => 'Prx150_Admin', 'action' => 'index']);
