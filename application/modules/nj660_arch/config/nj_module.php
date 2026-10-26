<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест NJ-660 «Конструктор архитектуры ИИ» для каталога nj110.
 *
 * Витрина: /arch — список проектов + зоопарк известных моделей;
 * конструктор: /arch/new (или /arch/edit/<code>) — стек блоков
 * (эмбеддинги, модальности, RoPE/ALiBi, GQA/MLA, SwiGLU, MoE, SSM,
 * кросс-аттн, хуки в середину модели) с живым пересчётом параметров,
 * VRAM, FLOPs и бюджета обучения.
 *
 * LLM-операции (экспертиза и симуляция поведения) — после входа;
 * сам конструктор и калькулятор публичны (локальный счёт, без токенов).
 */
return [
	'id'        => 660,
	'slug'      => 'arch',
	'title'     => 'Конструктор архитектуры ИИ',
	'short'     => 'Спроектируйте нейросеть из современных блоков: MoE, GQA/MLA, RoPE, SSM, мультимодальные входы, хуки в середину модели. Живой расчёт параметров, VRAM и бюджета обучения, экспертиза и симуляция поведения реальной LLM, экспорт PyTorch-скелета',
	'icon'      => 'fas fa-diagram-project',
	'href'      => '/arch',
	'card_view' => 'nj660/card',
	'group'     => 'Разработчику',
	'sort'      => 660,
	'pin'       => TRUE,
	'badge'     => 'новое',
	'keywords'  => 'архитектура нейросети конструктор transformer moe mixture of experts llama gpt mixtral mamba параметры vram flops обучение проект diffusion vit мультимодальность lora хук steering',
	'api'       => ['/arch/api/evaluate', '/arch/api/simulate'],

	// чистка: старые прогоны LLM (экспертизы/симуляции) — 180 дней
	'cleanup'   => [
		'nj_660_runs' => [
			'label'      => 'nj660 · прогоны LLM-экспертиз и симуляций',
			'note'       => 'Ответы движка по архитектурам старше 180 дней.',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM nj_660_runs WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_660_runs WHERE id IN (
								SELECT id FROM nj_660_runs WHERE created_at < now() - :old LIMIT :limit)',
		],
	],
];
