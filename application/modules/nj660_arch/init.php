<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-660 «Конструктор архитектуры ИИ» — роуты.
 *
 * Порядок важен: api и export ДО общего `arch(/<action>(/<code>))`,
 * иначе «arch/api» матчится как action.
 *
 *   GET  /arch                      — витрина: мои проекты, пресеты, зоопарк
 *   GET  /arch/new(?preset=<key>)   — конструктор (с заготовкой или с нуля)
 *   GET  /arch/edit/<code>          — конструктор со своим проектом
 *   GET  /arch/view/<code>          — страница модели: метрики, хуки,
 *                                     экспертиза, симулятор, экспорт
 *   POST /arch/save                 — сохранить (csrf)
 *   POST /arch/delete               — удалить свой (csrf)
 *   POST /arch/review               — LLM-экспертиза (csrf, вход)
 *   GET  /arch/export/pytorch|json/<code>
 *   POST /arch/api/evaluate         — DSL → метрики (публичный, локальный счёт)
 *   POST /arch/api/simulate         — диалог с «ожившей» моделью (вход)
 */
Route::set('nj660_api', 'arch/api(/<action>)', ['action' => 'evaluate|simulate'])
	->defaults([
		'controller' => 'Nj660_Api',
		'action'     => 'evaluate',
	]);

Route::set('nj660_export', 'arch/export(/<format>(/<code>))', ['format' => 'pytorch|json'])
	->defaults([
		'controller' => 'Nj660_Arch',
		'action'     => 'export',
	]);

Route::set('nj660_main', 'arch(/<action>(/<code>))', ['action' => 'new|edit|view|save|delete|review'])
	->defaults([
		'controller' => 'Nj660_Arch',
		'action'     => 'index',
	]);
