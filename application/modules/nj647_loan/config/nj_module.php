<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id'        => 647,
    'slug'      => 'loan',
    'title'     => 'Кредит и ипотека',
    'short'     => 'Платёж, график и переплата: аннуитет и дифференцированные платежи, досрочное погашение',
    'icon'      => 'fas fa-building-columns',
    'href'      => '/loan',
    'group'     => 'Финансы',
    'sort'      => 647,
    'pin'       => TRUE,
    'keywords'  => 'кредитный калькулятор ипотека график платежей аннуитетный дифференцированный досрочное погашение переплата ежемесячный платёж',
    'api'       => ['/nj647/api/calc', '/nj647/api/capacity'],
    'card_view' => 'nj647/card',
];
