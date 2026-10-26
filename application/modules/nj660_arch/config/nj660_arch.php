<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфигурация NJ-660 «Конструктор архитектуры ИИ».
 *
 * Здесь: лимиты DSL, пресеты-заготовки, зоопарк известных моделей
 * (справочные числа из публикаций — только для сравнения) и параметры
 * оценки бюджета обучения (GPU, MFU, цена часа — можно поменять под
 * своё облако).
 */
return [

	// --- лимиты DSL ---------------------------------------------------------
	'limits' => [
		'max_blocks'   => 120,   // элементов стека (до разворачивания repeat)
		'max_repeat'   => 200,   // максимум «повторить ×N» у одного блока
		'max_experts'  => 256,   // экспертов в MoE
		'max_d'        => 65536, // d_model
		'max_seq'      => 2000000,
		'max_vocab'    => 300000,
		'max_title'    => 120,
	],

	// --- LLM-операции (экспертиза, симуляция) --------------------------------
	'llm' => [
		// вход обязателен (решение владельца 06.10.2026 — как с опросами);
		// поверх — мягкие лимиты по числу прогонов в час
		'review_per_hour'   => 6,
		'simulate_per_hour' => 20,
		'review_max_tokens'     => 1200,
		'simulate_max_tokens'   => 700,
		// сколько последних сообщений диалога уезжает в LLM (эмуляция
		// контекстного окна спроектированной модели — остаток «забывается»)
		'simulate_history_turns' => 8,
	],

	// --- оценка бюджета обучения ---------------------------------------------
	// H100 SXM, bf16: пик ~989 TFLOPS; MFU 40% — типично для хорошо
	// настроенного обучения трансформера. Цена часа — рыночная аренда.
	'budget' => [
		'gpu_name'     => 'H100 SXM',
		'gpu_flops'    => 9.9e14,  // пиковые FLOPS (bf16, без спарса)
		'gpu_mfu'      => 0.40,    // MFU: доля пика, которая реально выходит
		'gpu_price'    => 2.5,     // $/час аренды
		'chinchilla'   => 20,      // оптимальные токены на параметр
		'bytes_train'  => 16,      // байт/параметр: AdamW mixed precision
		'bytes_infer'  => 2,       // bf16 инференс
	],

	// --- зоопарк: справочные модели для сравнения ----------------------------
	// total/active — публичные числа из карточек моделей; в конструктор
	// не разворачиваются, показываются таблицей «а как у больших».
	'zoo' => [
		['key' => 'gpt2',      'title' => 'GPT-2 small',   'family' => 'decoder',   'total' => 1.24e8,  'active' => 1.24e8,  'ctx' => 1024,  'note' => 'Классика-2019: MHA + GELU-FFN, learned pos'],
		['key' => 'gpt3',      'title' => 'GPT-3',         'family' => 'decoder',   'total' => 1.75e11, 'active' => 1.75e11, 'ctx' => 2048,  'note' => '175B, масштабирование законов Кэплана'],
		['key' => 'llama3-8',  'title' => 'Llama-3-8B',    'family' => 'decoder',   'total' => 8.03e9,  'active' => 8.03e9,  'ctx' => 8192,  'note' => 'GQA, SwiGLU, RoPE, RMSNorm — современный базовый рецепт'],
		['key' => 'llama3-70', 'title' => 'Llama-3-70B',   'family' => 'decoder',   'total' => 7.06e10, 'active' => 7.06e10, 'ctx' => 8192,  'note' => 'Тот же рецепт, d=8192'],
		['key' => 'mixtral',   'title' => 'Mixtral-8x7B',  'family' => 'decoder',   'total' => 4.67e10, 'active' => 1.29e10, 'ctx' => 32768, 'note' => 'MoE: 8 экспертов, top-2 — 13B активных из 47B'],
		['key' => 'dsv3',      'title' => 'DeepSeek-V3',   'family' => 'decoder',   'total' => 6.71e11, 'active' => 3.70e10, 'ctx' => 131072, 'note' => 'MLA + тонкий MoE (256 экспертов, top-8): 671B тотал / 37B активных'],
		['key' => 'mamba',     'title' => 'Mamba-2.8B',    'family' => 'ssm',       'total' => 2.78e9,  'active' => 2.78e9,  'ctx' => 0,      'note' => 'SSM вместо внимания: линейно по длине'],
		['key' => 'vit-l',     'title' => 'ViT-L/14',      'family' => 'encoder',   'total' => 3.05e8,  'active' => 3.05e8,  'ctx' => 257,    'note' => 'Vision-башня CLIP: патчи 14×14'],
		['key' => 'clip-l',    'title' => 'CLIP ViT-L',    'family' => 'dual',      'total' => 4.35e8,  'active' => 4.35e8,  'ctx' => 77,     'note' => 'Две башни + contrastive loss'],
		['key' => 'dit-xl',    'title' => 'DiT-XL/2',      'family' => 'diffusion', 'total' => 6.75e8,  'active' => 6.75e8,  'ctx' => 256,    'note' => 'Трансформер-диффузия (основа SD3/Flux)'],
		['key' => 'whisper',   'title' => 'Whisper-large-v3', 'family' => 'encoder-decoder', 'total' => 1.55e9, 'active' => 1.55e9, 'ctx' => 4400, 'note' => 'Аудио→текст: mel-энкодер + декодер'],
		['key' => 'chameleon', 'title' => 'Chameleon-34B', 'family' => 'decoder',   'total' => 3.4e10,  'active' => 3.4e10,  'ctx' => 4096,   'note' => 'Early fusion: один токен-поток для текста и картинок'],
	],

	// --- пресеты: заготовки для конструктора (форкаются в редактор) ----------
	'presets' => [

		'llama8b' => [
			'title' => 'Llama-подобный 8B',
			'note'  => 'Современный базовый рецепт: GQA + SwiGLU + RoPE + pre-RMSNorm.',
			'dsl' => [
				'family' => 'decoder',
				'config' => ['d' => 4096, 'S' => 8192, 'V' => 128256],
				'stack' => [
					['t' => 'emb', 'V' => 128256, 'tied' => TRUE],
					['t' => 'pos', 'v' => 'rope'],
					['t' => 'norm', 'v' => 'rms', 'n' => 32],
					['t' => 'attn', 'v' => 'gqa', 'H' => 32, 'KV' => 8, 'n' => 32],
					['t' => 'norm', 'v' => 'rms', 'n' => 32],
					['t' => 'ffn', 'v' => 'swiglu', 'h' => 14336, 'n' => 32],
					['t' => 'head', 'v' => 'lm'],
				],
			],
		],

		'gpt2' => [
			'title' => 'GPT-2 small (124M)',
			'note'  => 'Историческая классика 2019 года — почувствуйте разницу с Llama.',
			'dsl' => [
				'family' => 'decoder',
				'config' => ['d' => 768, 'S' => 1024, 'V' => 50257],
				'stack' => [
					['t' => 'emb', 'V' => 50257, 'tied' => TRUE],
					['t' => 'pos', 'v' => 'learned'],
					['t' => 'norm', 'v' => 'layer', 'n' => 12],
					['t' => 'attn', 'v' => 'mha', 'H' => 12, 'KV' => 12, 'n' => 12],
					['t' => 'ffn', 'v' => 'gelu', 'h' => 3072, 'n' => 12],
					['t' => 'head', 'v' => 'lm'],
				],
			],
		],

		'mixtral' => [
			'title' => 'MoE как Mixtral (47B/13B)',
			'note'  => 'Mixture of Experts: 8 экспертов, активны 2 — качество 47B за скоростью 13B.',
			'dsl' => [
				'family' => 'decoder',
				'config' => ['d' => 4096, 'S' => 32768, 'V' => 32000],
				'stack' => [
					['t' => 'emb', 'V' => 32000, 'tied' => FALSE],
					['t' => 'pos', 'v' => 'rope'],
					['t' => 'norm', 'v' => 'rms', 'n' => 32],
					['t' => 'attn', 'v' => 'gqa', 'H' => 32, 'KV' => 8, 'n' => 32],
					['t' => 'norm', 'v' => 'rms', 'n' => 32],
					['t' => 'moe', 'E' => 8, 'k' => 2, 'shared' => FALSE, 'h' => 14336, 'n' => 32],
					['t' => 'head', 'v' => 'lm'],
				],
			],
		],

		'moe-small' => [
			'title' => 'Компактный MoE (1.5B/0.4B)',
			'note'  => 'Тонкий MoE на маленьком d: дёшево в обучении, широкий охват задач.',
			'dsl' => [
				'family' => 'decoder',
				'config' => ['d' => 1024, 'S' => 4096, 'V' => 32000],
				'stack' => [
					['t' => 'emb', 'V' => 32000, 'tied' => TRUE],
					['t' => 'pos', 'v' => 'rope'],
					['t' => 'norm', 'v' => 'rms', 'n' => 16],
					['t' => 'attn', 'v' => 'gqa', 'H' => 16, 'KV' => 4, 'n' => 16],
					['t' => 'norm', 'v' => 'rms', 'n' => 16],
					['t' => 'moe', 'E' => 8, 'k' => 2, 'shared' => TRUE, 'h' => 2816, 'n' => 16],
					['t' => 'hook', 'kind' => 'steer', 'at' => 8, 's' => 0.6, 'text' => 'аккуратность формулировок'],
					['t' => 'head', 'v' => 'lm'],
				],
			],
		],

		'hybrid' => [
			'title' => 'Гибрид внимание+SSM',
			'note'  => 'Чередование attention и Mamba-слоёв (идея Jamba): длинный контекст дешевле.',
			'dsl' => [
				'family' => 'decoder',
				'config' => ['d' => 2048, 'S' => 65536, 'V' => 32000],
				'stack' => [
					['t' => 'emb', 'V' => 32000, 'tied' => TRUE],
					['t' => 'pos', 'v' => 'rope'],
					['t' => 'norm', 'v' => 'rms', 'n' => 24],
					['t' => 'attn', 'v' => 'sliding', 'H' => 16, 'KV' => 4, 'W' => 4096, 'n' => 8],
					['t' => 'ssm', 'state' => 16, 'expand' => 2, 'n' => 16],
					['t' => 'head', 'v' => 'lm'],
				],
			],
		],

		'llava' => [
			'title' => 'Мультимодальный (LLaVA-стиль)',
			'note'  => 'Картинка → патчи → проекция в пространство текста: одна модель видит и говорит.',
			'dsl' => [
				'family' => 'decoder',
				'config' => ['d' => 4096, 'S' => 8192, 'V' => 128256],
				'stack' => [
					['t' => 'emb', 'V' => 128256, 'tied' => TRUE],
					['t' => 'patch', 'P' => 14, 'C' => 3],
					['t' => 'pos', 'v' => 'rope'],
					['t' => 'norm', 'v' => 'rms', 'n' => 32],
					['t' => 'attn', 'v' => 'gqa', 'H' => 32, 'KV' => 8, 'n' => 30],
					['t' => 'norm', 'v' => 'rms', 'n' => 32],
					['t' => 'ffn', 'v' => 'swiglu', 'h' => 14336, 'n' => 30],
					['t' => 'xattn', 'H' => 32, 'KV' => 8, 'n' => 2],
					['t' => 'head', 'v' => 'lm'],
				],
			],
		],

		'vit' => [
			'title' => 'ViT-энкодер',
			'note'  => 'Vision Transformer: картинка = последовательность патчей, BERT-стиль.',
			'dsl' => [
				'family' => 'encoder',
				'config' => ['d' => 1024, 'S' => 257, 'V' => 0],
				'stack' => [
					['t' => 'patch', 'P' => 14, 'C' => 3],
					['t' => 'pos', 'v' => 'learned'],
					['t' => 'norm', 'v' => 'layer', 'n' => 24],
					['t' => 'attn', 'v' => 'mha', 'H' => 16, 'KV' => 16, 'n' => 24],
					['t' => 'ffn', 'v' => 'gelu', 'h' => 4096, 'n' => 24],
				],
			],
		],

		'dit' => [
			'title' => 'Диффузия DiT',
			'note'  => 'Трансформер, который генерирует: время-эмбеддинг + adaLN, выход — шум патчей.',
			'dsl' => [
				'family' => 'diffusion',
				'config' => ['d' => 1152, 'S' => 256, 'V' => 0],
				'stack' => [
					['t' => 'patch', 'P' => 2, 'C' => 4],
					['t' => 'time'],
					['t' => 'pos', 'v' => 'learned'],
					['t' => 'norm', 'v' => 'layer', 'n' => 28],
					['t' => 'attn', 'v' => 'mha', 'H' => 16, 'KV' => 16, 'n' => 28],
					['t' => 'ffn', 'v' => 'gelu', 'h' => 4608, 'n' => 28],
					['t' => 'head', 'v' => 'diffusion'],
				],
			],
		],

		'tiny' => [
			'title' => 'Крошка для края (~40M)',
			'note'  => 'Модель для телефона/микроконтроллера: словарь поменьше, MQA, тонкий FFN.',
			'dsl' => [
				'family' => 'decoder',
				'config' => ['d' => 512, 'S' => 2048, 'V' => 16000],
				'stack' => [
					['t' => 'emb', 'V' => 16000, 'tied' => TRUE],
					['t' => 'pos', 'v' => 'rope'],
					['t' => 'norm', 'v' => 'rms', 'n' => 12],
					['t' => 'attn', 'v' => 'mqa', 'H' => 8, 'KV' => 1, 'n' => 12],
					['t' => 'norm', 'v' => 'rms', 'n' => 12],
					['t' => 'ffn', 'v' => 'swiglu', 'h' => 1376, 'n' => 12],
					['t' => 'hook', 'kind' => 'lora', 'at' => 6, 'r' => 16],
					['t' => 'head', 'v' => 'lm'],
				],
			],
		],
	],
];
