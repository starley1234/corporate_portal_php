<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prl398_vlm_analyze', 'vlm-demo/analyze')
    ->defaults(['controller' => 'Prl398_Vlm', 'action' => 'analyze']);

Route::set('prl398_vlm', 'vlm-demo')
    ->defaults(['controller' => 'Prl398_Vlm', 'action' => 'index']);
