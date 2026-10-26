<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Бюджеты токенов по ролям (nj120). Токены = prompt + completion из
 * nj_120_usage за календарный день / месяц (UTC сервера БД).
 *
 * 0 — без лимита. Роль берётся «самая щедрая» из ролей пользователя;
 * гость без аккаунта — `anon`. Индивидуальные значения — nj_120_budgets
 * (/nj120/budgets), они сильнее ролевых.
 */
return [
	'daily' => [
		'anon'  => (int) (getenv('NJ120_BUDGET_DAILY_ANON') ?: 30000),
		'login' => (int) (getenv('NJ120_BUDGET_DAILY_LOGIN') ?: 150000),
		'vip'   => (int) (getenv('NJ120_BUDGET_DAILY_VIP') ?: 600000),
		'admin' => (int) (getenv('NJ120_BUDGET_DAILY_ADMIN') ?: 0),
		// служебные ключи `s:<модуль>` (контент-конвейер и т. п.) — без лимита
		'system' => (int) (getenv('NJ120_BUDGET_DAILY_SYSTEM') ?: 0),
	],
	'monthly' => [
		'anon'  => (int) (getenv('NJ120_BUDGET_MONTHLY_ANON') ?: 300000),
		'login' => (int) (getenv('NJ120_BUDGET_MONTHLY_LOGIN') ?: 2000000),
		'vip'   => (int) (getenv('NJ120_BUDGET_MONTHLY_VIP') ?: 10000000),
		'admin' => (int) (getenv('NJ120_BUDGET_MONTHLY_ADMIN') ?: 0),
		'system' => (int) (getenv('NJ120_BUDGET_MONTHLY_SYSTEM') ?: 0),
	],
	// порядок щедрости: правее — больше прав
	'rank'     => ['anon', 'login', 'vip', 'admin', 'system'],
	// предупреждение владельцу (колокольчик nj597) при этой доле бюджета
	'warn_pct' => (int) (getenv('NJ120_BUDGET_WARN_PCT') ?: 90),
	// выключатель запрета постановки задач при исчерпании (учёт и
	// предупреждения работают всегда)
	'enforce'  => getenv('NJ120_BUDGET_ENFORCE') === FALSE ? TRUE : (getenv('NJ120_BUDGET_ENFORCE') !== '0'),
];
