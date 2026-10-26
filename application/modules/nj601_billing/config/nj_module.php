<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест «Счета и платежи» (nj601_billing) для nj110_catalog.
 * hidden: пользовательских страниц нет — оплата инициируется будущими
 * монетизируемыми модулями; администрирование — admin-чип.
 */

return [
    'id'        => 601,
    'slug'      => 'nj601_billing',
    'title'     => 'Счета и платежи',
    'short'     => 'Приём платежей через Robokassa — фундамент монетизации модулей',
    'icon'      => 'fas fa-credit-card',
    'href'      => '/nj601/admin',
    'group'     => 'Платформа',
    // 06.10.2026: из левого меню убран в группу «Платформа» — ссылка на странице
    // «Администрирование» (/admin); в каталоге /services и палитре остаётся.
    'menu'       => FALSE,
    'sort'      => 601,
    'hidden'    => TRUE,
    'admin'     => ['title' => 'Счета · управление', 'href' => '/nj601/admin'],
    'keywords'  => 'оплата платежи robokassa счет монетизация',
    'api'       => ['/billing/pay', '/billing/result', '/nj601/admin'],
    'auth'      => FALSE,
];
