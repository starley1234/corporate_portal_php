<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prx113_favorites_home', 'favorites')
    ->defaults(['controller' => 'Prx113_Favorites', 'action' => 'index']);
Route::set('prx113_favorites_save', 'favorites/save')
    ->defaults(['controller' => 'Prx113_Favorites', 'action' => 'save']);
Route::set('prx113_favorites', 'prx113_favorites(/<action>)')
    ->defaults(['controller' => 'Prx113_Favorites', 'action' => 'index']);
