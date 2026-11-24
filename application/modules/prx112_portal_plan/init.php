<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prx112_portal_plan_approve', 'portal-plan/approve')
    ->defaults(['directory' => 'Prx112', 'controller' => 'Plan', 'action' => 'approve']);

Route::set('prx112_portal_plan', 'prx112_portal_plan')
    ->defaults(['directory' => 'Prx112', 'controller' => 'Plan', 'action' => 'index']);

Route::set('portal_plan', 'portal-plan')
    ->defaults(['directory' => 'Prx112', 'controller' => 'Plan', 'action' => 'index']);
