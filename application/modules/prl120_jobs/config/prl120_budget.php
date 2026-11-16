<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Бюджеты токенов по ролям (prl120). Токены = prompt + completion из
 * prl_120_usage за календарный день / месяц (UTC сервера БД).
 *
 * 0 — без лимита. Роль берётся «самая щедрая» из ролей пользователя;
 * гость без аккаунта — `anon`. Индивидуальные значения — prl_120_budgets
 * (/prl120/budgets), они сильнее ролевых.
 */
return [
	'daily' => [
		'anon'  => (int) (getenv('PRL120_BUDGET_DAILY_ANON') ?: 30000),
		'login' => (int) (getenv('PRL120_BUDGET_DAILY_LOGIN') ?: 150000),
		'vip'   => (int) (getenv('PRL120_BUDGET_DAILY_VIP') ?: 600000),
		'admin' => (int) (getenv('PRL120_BUDGET_DAILY_ADMIN') ?: 0),
		// служебные ключи `s:<модуль>` (контент-конвейер и т. п.) — без лимита
		'system' => (int) (getenv('PRL120_BUDGET_DAILY_SYSTEM') ?: 0),
	],
	'monthly' => [
		'anon'  => (int) (getenv('PRL120_BUDGET_MONTHLY_ANON') ?: 300000),
		'login' => (int) (getenv('PRL120_BUDGET_MONTHLY_LOGIN') ?: 2000000),
		'vip'   => (int) (getenv('PRL120_BUDGET_MONTHLY_VIP') ?: 10000000),
		'admin' => (int) (getenv('PRL120_BUDGET_MONTHLY_ADMIN') ?: 0),
		'system' => (int) (getenv('PRL120_BUDGET_MONTHLY_SYSTEM') ?: 0),
	],
	// порядок щедрости: правее — больше прав
	'rank'     => ['anon', 'login', 'vip', 'admin', 'system'],
	// предупреждение владельцу (колокольчик prl597) при этой доле бюджета
	'warn_pct' => (int) (getenv('PRL120_BUDGET_WARN_PCT') ?: 90),
	// выключатель запрета постановки задач при исчерпании (учёт и
	// предупреждения работают всегда)
	'enforce'  => getenv('PRL120_BUDGET_ENFORCE') === FALSE ? TRUE : (getenv('PRL120_BUDGET_ENFORCE') !== '0'),
];
