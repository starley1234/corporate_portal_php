<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj600_prefs («Аккумулятор предпочтений»).
 *
 * /nj600/admin                 — управление: KPI, топ разделов/фраз, профили, очистка сырья;
 * /nj600/admin/owner/<key>     — карточка владельца: предпочтения, последние действия, рекомендации;
 * /nj600/admin/reco            — рекомендации под надзором: правила, CTR, журнал показов;
 * /prefs/api/profile           — свой компактный профиль (JSON);
 * /prefs/api/recommend         — свои рекомендации (JSON), /prefs/api/go — переход с учётом клика;
 * сбор — пассивный: хуки в Erp100_Base::before() и nj100_search (см. Service).
 */

Route::set('nj600_admin', 'nj600/admin(/<action>(/<id>))', ['id' => '[^/]+'])
    ->defaults([
        'controller' => 'Nj600_Admin',
        'action'     => 'index',
    ]);

Route::set('nj600_api', 'prefs/api(/<action>)')
    ->defaults([
        'controller' => 'Nj600_Api',
        'action'     => 'profile',
    ]);
