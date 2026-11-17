<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prx398_vlm_analyze', 'vlm-demo/analyze')
    ->defaults(['controller' => 'Prx398_Vlm', 'action' => 'analyze']);

Route::set('prx398_vlm', 'vlm-demo')
    ->defaults(['controller' => 'Prx398_Vlm', 'action' => 'index']);
