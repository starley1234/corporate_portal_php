<?php defined('SYSPATH') or die('No direct script access.');

// Публичная витрина дизайна. Вариант регистрируем до индекса, чтобы slug не
// трактовался как действие. Контроллер не наследует Controller_Base и не
// использует общий шаблон/сессию.
Route::set('prl140_design_lab_variant', 'design-lab/<variant>', ['variant' => '[a-z][a-z0-9-]*'])
    ->defaults(['controller' => 'Prl140_DesignLab', 'action' => 'variant']);
Route::set('prl140_design_lab', 'design-lab')
    ->defaults(['controller' => 'Prl140_DesignLab', 'action' => 'index']);
