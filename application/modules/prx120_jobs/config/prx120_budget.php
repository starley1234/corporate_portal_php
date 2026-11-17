<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Бюджеты токенов по ролям (prx120). Токены = prompt + completion из
 * prx_120_usage за календарный день / месяц (UTC сервера БД).
 *
 * 0 — без лимита. Роль берётся «самая щедрая» из ролей пользователя;
 * гость без аккаунта — `anon`. Индивидуальные значения — prx_120_budgets
 * (/prx120/budgets), они сильнее ролевых.
 */
return [
	'daily' => [
		'anon'  => (int) (getenv('PRX120_BUDGET_DAILY_ANON') ?: 30000),
		'login' => (int) (getenv('PRX120_BUDGET_DAILY_LOGIN') ?: 150000),
		'vip'   => (int) (getenv('PRX120_BUDGET_DAILY_VIP') ?: 600000),
		'admin' => (int) (getenv('PRX120_BUDGET_DAILY_ADMIN') ?: 0),
		// служебные ключи `s:<модуль>` (контент-конвейер и т. п.) — без лимита
		'system' => (int) (getenv('PRX120_BUDGET_DAILY_SYSTEM') ?: 0),
	],
	'monthly' => [
		'anon'  => (int) (getenv('PRX120_BUDGET_MONTHLY_ANON') ?: 300000),
		'login' => (int) (getenv('PRX120_BUDGET_MONTHLY_LOGIN') ?: 2000000),
		'vip'   => (int) (getenv('PRX120_BUDGET_MONTHLY_VIP') ?: 10000000),
		'admin' => (int) (getenv('PRX120_BUDGET_MONTHLY_ADMIN') ?: 0),
		'system' => (int) (getenv('PRX120_BUDGET_MONTHLY_SYSTEM') ?: 0),
	],
	// порядок щедрости: правее — больше прав
	'rank'     => ['anon', 'login', 'vip', 'admin', 'system'],
	// предупреждение владельцу (колокольчик prx597) при этой доле бюджета
	'warn_pct' => (int) (getenv('PRX120_BUDGET_WARN_PCT') ?: 90),
	// выключатель запрета постановки задач при исчерпании (учёт и
	// предупреждения работают всегда)
	'enforce'  => getenv('PRX120_BUDGET_ENFORCE') === FALSE ? TRUE : (getenv('PRX120_BUDGET_ENFORCE') !== '0'),
];
