<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-660 «Конструктор архитектуры ИИ» — ядро.
 *
 * Три слоя ответственности:
 *   1. DSL: описание архитектуры списком блоков (эмбеддинги, модальности,
 *      позиционирование, внимание GQA/MQA/MLA/sliding/linear, SwiGLU-FFN,
 *      MoE, SSM, кросс-аттн, хуки в середину модели, головы) + семейство
 *      (decoder/encoder/diffusion). validate() проверяет, flatten() разворачивает
 *      repeat-блоки в плоский список слоёв с позициями для хуков.
 *   2. Калькулятор evaluate(): параметры каждого блока (с формулой —
 *      познавательно), тотал/активные (MoE top-k), обучаемые при LoRA,
 *      VRAM (инференс/обучение), KV-cache, FLOPs, бюджет обучения
 *      (Chinchilla, GPU-часы, деньги), сравнение со зоопарком и предупреждения.
 *   3. Хранение: проекты (nj_660_designs) и прогоны LLM (nj_660_runs);
 *      экспорт PyTorch-скелета pytorch() — детерминированный генератор,
 *      хуки превращаются в настоящие register_forward_hook.
 *
 * Чистые функции (без БД): validate, flatten, evaluate, palette, pytorch,
 * preset, zoo — их проверяет стенд .tools/nj660_arch_stand.php.
 */
class Service_Nj660_Arch
{
	// ------------------------------------------------------------------
	// Словарь блоков (палитра конструктора и легенда вьюх)
	// ------------------------------------------------------------------

	/**
	 * Метаданные блоков палитры: группа, подпись, роль, поля с умолчаниями.
	 * layer=TRUE участвует в глубине модели (repeat и позиция хуков).
	 */
	public static function palette()
	{
		return [
			'emb'   => ['group' => 'Входы',   'label' => 'Токен-эмбеддинг', 'layer' => FALSE, 'note' => 'Таблица V×d: индекс токена → вектор. Выход привязан к head (tied) или отдельный.',
				'fields' => ['V' => 32000, 'tied' => TRUE]],
			'patch' => ['group' => 'Входы',   'label' => 'Картинка → патчи', 'layer' => FALSE, 'note' => 'ViT-вход: изображение нарезается на патчи P×P, линейная проекция в d.',
				'fields' => ['P' => 14, 'C' => 3]],
			'audio' => ['group' => 'Входы',   'label' => 'Аудио-адаптер', 'layer' => FALSE, 'note' => 'Mel-спектрограмма → свёртки → последовательность векторов (стиль Whisper).',
				'fields' => ['mel' => 128]],
			'pos'   => ['group' => 'Входы',   'label' => 'Позиционирование', 'layer' => FALSE, 'note' => 'RoPE — повороты q/k (0 параметров); ALiBi — линейные смещения; learned — таблица S×d.',
				'fields' => ['v' => 'rope']],
			'time'  => ['group' => 'Входы',   'label' => 'Время-эмбеддинг', 'layer' => FALSE, 'note' => 'Для диффузии: шаг шума → вектор, модулирует слои (adaLN).',
				'fields' => []],
			'attn'  => ['group' => 'Слои',    'label' => 'Внимание', 'layer' => TRUE, 'note' => 'MHA — классика; GQA/MQA — экономия KV-cache; MLA — латентное сжатие (DeepSeek); sliding — окно; linear — линейно по длине.',
				'fields' => ['v' => 'gqa', 'H' => 32, 'KV' => 8, 'W' => 4096]],
			'xattn' => ['group' => 'Слои',    'label' => 'Кросс-внимание', 'layer' => TRUE, 'note' => 'Q из текста, K/V из другой модальности — слияние потоков (fusion).',
				'fields' => ['H' => 32, 'KV' => 8]],
			'ffn'   => ['group' => 'Слои',    'label' => 'FFN', 'layer' => TRUE, 'note' => 'SwiGLU — гейт-вариант (3 матрицы, современный стандарт); GELU — классика (2 матрицы).',
				'fields' => ['v' => 'swiglu', 'h' => 11008]],
			'moe'   => ['group' => 'Слои',    'label' => 'MoE — эксперты', 'layer' => TRUE, 'note' => 'E экспертов, активны k: роутер выбирает по токену. Качество большого банка за скоростью малой активной части.',
				'fields' => ['E' => 8, 'k' => 2, 'shared' => FALSE, 'h' => 11008]],
			'ssm'   => ['group' => 'Слои',    'label' => 'SSM (Mamba-стиль)', 'layer' => TRUE, 'note' => 'Выборочный state-space слой: линейная сложность по длине, состояние вместо KV-cache.',
				'fields' => ['state' => 16, 'expand' => 2]],
			'norm'  => ['group' => 'Слои',    'label' => 'Нормализация', 'layer' => TRUE, 'note' => 'RMSNorm — проще и быстрее LayerNorm (без смещения mean). Ставится pre-norm перед attn/ffn.',
				'fields' => ['v' => 'rms']],
			'hook'  => ['group' => 'Хуки',    'label' => 'Хук в середину', 'layer' => FALSE, 'note' => 'steer — направляющий вектор на слое at (стиль/тон/содержание); lora — адаптер ранга r для файнтюна; tap — отвод активаций (зонд); exit — ранний выход.',
				'fields' => ['kind' => 'steer', 'at' => 1, 's' => 0.5, 'r' => 16, 'text' => '', 'note' => '']],
			'head'  => ['group' => 'Выходы',  'label' => 'Голова', 'layer' => FALSE, 'note' => 'lm — словарные логиты (tied = делит таблицу с эмбеддингом); diffusion — предсказание шума патчей.',
				'fields' => ['v' => 'lm']],
		];
	}

	/** Семейства архитектур. */
	public static function families()
	{
		return [
			'decoder'   => 'Декодер (GPT/Llama): генерирует последовательность',
			'encoder'   => 'Энкодер (BERT/ViT): понимает вход',
			'diffusion' => 'Диффузия (DiT): генерирует итеративно, по шагам шума',
		];
	}

	/** Конфиг модуля как массив. */
	public static function config()
	{
		$config = Kohana::$config->load('nj660_arch');

		return $config instanceof Kohana_Config_Group ? $config->as_array() : (array) $config;
	}

	/** Зоопарк (справочник известных моделей). */
	public static function zoo()
	{
		return (array) Arr::get(self::config(), 'zoo', []);
	}

	/** Пресеты-заготовки. */
	public static function presets()
	{
		return (array) Arr::get(self::config(), 'presets', []);
	}

	public static function preset($key)
	{
		$presets = self::presets();

		return isset($presets[$key]) ? $presets[$key] : NULL;
	}

	// ------------------------------------------------------------------
	// DSL: проверка и разворачивание
	// ------------------------------------------------------------------

	/**
	 * Проверить DSL. Возвращает список ошибок (пустой = валиден).
	 *
	 * @param  array $dsl
	 * @return array
	 */
	public static function validate(array $dsl)
	{
		$errors = [];
		$limits = (array) Arr::get(self::config(), 'limits', []);
		$palette = self::palette();

		$family = (string) Arr::get($dsl, 'family', '');
		$known = ['decoder', 'encoder', 'diffusion'];

		if ( ! in_array($family, $known, TRUE))
		{
			$errors[] = 'Неизвестное семейство: '.$family;
		}

		$config = (array) Arr::get($dsl, 'config', []);
		$d = (int) Arr::get($config, 'd', 0);
		$S = (int) Arr::get($config, 'S', 0);

		if ($d < 64 OR $d > (int) Arr::get($limits, 'max_d', 65536))
		{
			$errors[] = 'd_model должен быть от 64 до '.Arr::get($limits, 'max_d', 65536);
		}

		if ($S < 64 OR $S > (int) Arr::get($limits, 'max_seq', 2000000))
		{
			$errors[] = 'Длина контекста S должна быть от 64 до '.Arr::get($limits, 'max_seq', 2000000);
		}

		$stack = (array) Arr::get($dsl, 'stack', []);

		if ( ! $stack)
		{
			$errors[] = 'Стек пуст — добавьте хотя бы один слой.';
		}

		if (count($stack) > (int) Arr::get($limits, 'max_blocks', 120))
		{
			$errors[] = 'Слишком много блоков в стеке (максимум '.Arr::get($limits, 'max_blocks', 120).').';
		}

		$depth = 0;

		foreach ($stack as $i => $block)
		{
			$t = (string) Arr::get($block, 't', '');
			$pos = $i + 1;

			if ( ! isset($palette[$t]))
			{
				$errors[] = "Блок #$pos: неизвестный тип «{$t}».";
				continue;
			}

			$n = (int) Arr::get($block, 'n', 1);

			if ($n < 1 OR $n > (int) Arr::get($limits, 'max_repeat', 200))
			{
				$errors[] = "Блок #$pos («{$t}»): повтор ×{$n} вне диапазона 1..".Arr::get($limits, 'max_repeat', 200).'.';
			}

			if ($palette[$t]['layer'])
			{
				$depth += $n;
			}

			// специфика блоков
			if ($t === 'attn' OR $t === 'xattn')
			{
				$H = (int) Arr::get($block, 'H', 0);
				$KV = (int) Arr::get($block, 'KV', 0);

				if ($H < 1 OR $H > 256)
				{
					$errors[] = "Блок #$pos: число голов должно быть 1..256.";
				}
				elseif ($d > 0 AND $d % $H !== 0)
				{
					$errors[] = "Блок #$pos: d={$d} не делится на число голов H={$H} нацело.";
				}

				if ($KV < 1 OR $KV > $H)
				{
					$errors[] = "Блок #$pos: KV-голов должно быть 1..H (сейчас KV={$KV}, H={$H}).";
				}

				$v = (string) Arr::get($block, 'v', '');

				if ($t === 'attn' AND ! in_array($v, ['mha', 'gqa', 'mqa', 'mla', 'sliding', 'linear'], TRUE))
				{
					$errors[] = "Блок #$pos: неизвестный вариант внимания «{$v}».";
				}

				if ($v === 'sliding' AND (int) Arr::get($block, 'W', 0) < 64)
				{
					$errors[] = "Блок #$pos: окно sliding-внимания слишком маленькое.";
				}
			}

			if ($t === 'ffn')
			{
				$v = (string) Arr::get($block, 'v', '');

				if ( ! in_array($v, ['swiglu', 'gelu', 'glu'], TRUE))
				{
					$errors[] = "Блок #$pos: неизвестный вариант FFN «{$v}».";
				}

				$h = (int) Arr::get($block, 'h', 0);

				if ($h < 64 OR $h > 131072)
				{
					$errors[] = "Блок #$pos: скрытая размерность FFN должна быть 64..131072.";
				}
			}

			if ($t === 'moe')
			{
				$E = (int) Arr::get($block, 'E', 0);
				$k = (int) Arr::get($block, 'k', 0);

				if ($E < 2 OR $E > (int) Arr::get($limits, 'max_experts', 256))
				{
					$errors[] = "Блок #$pos: число экспертов должно быть 2..".Arr::get($limits, 'max_experts', 256).'.';
				}

				if ($k < 1 OR $k > $E)
				{
					$errors[] = "Блок #$pos: top-k должен быть 1..E (сейчас k={$k}, E={$E}).";
				}

				$h = (int) Arr::get($block, 'h', 0);

				if ($h < 64 OR $h > 131072)
				{
					$errors[] = "Блок #$pos: скрытая размерность эксперта должна быть 64..131072.";
				}
			}

			if ($t === 'emb')
			{
				$V = (int) Arr::get($block, 'V', 0);

				if ($V < 256 OR $V > (int) Arr::get($limits, 'max_vocab', 300000))
				{
					$errors[] = "Блок #$pos: словарь должен быть 256..".Arr::get($limits, 'max_vocab', 300000).'.';
				}
			}

			if ($t === 'hook')
			{
				$kind = (string) Arr::get($block, 'kind', '');

				if ( ! in_array($kind, ['steer', 'lora', 'tap', 'exit'], TRUE))
				{
					$errors[] = "Блок #$pos: неизвестный вид хука «{$kind}».";
				}

				$at = (int) Arr::get($block, 'at', 0);

				if ($at < 1)
				{
					$errors[] = "Блок #$pos: хук должен стоять на слое ≥ 1.";
				}

				if ($kind === 'lora' AND ((int) Arr::get($block, 'r', 0) < 1 OR (int) Arr::get($block, 'r', 0) > 1024))
				{
					$errors[] = "Блок #$pos: ранг LoRA должен быть 1..1024.";
				}

				if ($kind === 'steer')
				{
					$s = (float) Arr::get($block, 's', 0.5);

					if ($s < 0 OR $s > 2)
					{
						$errors[] = "Блок #$pos: сила steering-хука должна быть 0..2.";
					}
				}
			}

			if ($t === 'pos' AND ! in_array((string) Arr::get($block, 'v', ''), ['rope', 'alibi', 'learned', 'none'], TRUE))
			{
				$errors[] = "Блок #$pos: неизвестное позиционирование.";
			}

			if ($t === 'norm' AND ! in_array((string) Arr::get($block, 'v', ''), ['rms', 'layer'], TRUE))
			{
				$errors[] = "Блок #$pos: неизвестная нормализация.";
			}
		}

		// позиция хука не должна превышать глубину стека
		foreach ($stack as $i => $block)
		{
			if ((string) Arr::get($block, 't', '') === 'hook')
			{
				$at = (int) Arr::get($block, 'at', 0);

				if ($depth > 0 AND $at > $depth)
				{
					$errors[] = 'Хук на слое '.$at.' — но в модели всего слоёв '.$depth.'.';
				}
			}
		}

		return $errors;
	}

	/**
	 * Разворот стека: repeat-блоки → плоский список слоёв; хуки
	 * привязываются к позициям. Возвращает ['layers' => [...], 'hooks' => [...]].
	 */
	public static function flatten(array $dsl)
	{
		$layers = [];
		$hooks = [];

		foreach ((array) Arr::get($dsl, 'stack', []) as $block)
		{
			$t = (string) Arr::get($block, 't', '');
			$palette = self::palette();

			if ($t === 'hook')
			{
				$hooks[] = [
					'kind' => (string) Arr::get($block, 'kind', 'steer'),
					'at'   => max(1, (int) Arr::get($block, 'at', 1)),
					's'    => (float) Arr::get($block, 's', 0.5),
					'r'    => (int) Arr::get($block, 'r', 16),
					'text' => (string) Arr::get($block, 'text', ''),
					'note' => (string) Arr::get($block, 'note', ''),
				];
				continue;
			}

			if (empty($palette[$t]['layer']))
			{
				$layers[] = $block + ['t' => $t, 'n' => 1];
				continue;
			}

			$n = max(1, (int) Arr::get($block, 'n', 1));

			for ($i = 0; $i < $n; $i++)
			{
				$layers[] = $block + ['t' => $t, 'n' => 1];
			}
		}

		return ['layers' => $layers, 'hooks' => $hooks];
	}

	// ------------------------------------------------------------------
	// Калькулятор
	// ------------------------------------------------------------------

	/**
	 * Главная функция: посчитать всё о модели.
	 *
	 * @param  array $dsl
	 * @return array  blocks/totals/compare/warnings или ['errors' => [...]]
	 */
	public static function evaluate(array $dsl)
	{
		$errors = self::validate($dsl);

		if ($errors)
		{
			return ['errors' => $errors];
		}

		$config = (array) Arr::get($dsl, 'config', []);
		$d = (int) Arr::get($config, 'd', 768);
		$S = (int) Arr::get($config, 'S', 2048);
		$family = (string) Arr::get($dsl, 'family', 'decoder');
		$flat = self::flatten($dsl);

		$blocks = [];       // строки таблицы: блок, штук, формула, параметры
		$total = 0;         // все параметры
		$active = 0;        // активные за один проход (MoE top-k)
		$lora = 0;          // обучаемые при LoRA-файнтюне
		$attn_flops_d = 0;  // суммарные «внимательные» FLOPs на токен
		$kv_per_token = 0;  // байт KV-cache на токен (bf16)
		$depth = 0;
		$n_attn = 0;
		$n_moe = 0;
		$n_ssm = 0;
		$has_pos = FALSE;
		$pos_kind = '';
		$patch = NULL;      // последний patch-блок (нужен diffusion-голове)
		$has_emb = FALSE;
		$V = 0;
		$tied = TRUE;

		foreach ($flat['layers'] as $block)
		{
			$t = (string) $block['t'];
			$params = 0;
			$act = 0;           // активные параметры этого блока
			$formula = '';
			$count_label = 1;

			switch ($t)
			{
				case 'emb':
					$V = (int) Arr::get($block, 'V', 32000);
					$tied = (bool) Arr::get($block, 'tied', TRUE);
					$params = $V * $d;
					$has_emb = TRUE;
					$formula = "V·d = {$V}·{$d}";
					break;

				case 'patch':
					$P = (int) Arr::get($block, 'P', 14);
					$C = (int) Arr::get($block, 'C', 3);
					$params = $P * $P * $C * $d + $d;
					$patch = $block;
					$formula = "P²·C·d + d = {$P}²·{$C}·{$d}";
					break;

				case 'audio':
					$mel = (int) Arr::get($block, 'mel', 128);
					$params = $mel * $d * 2; // две свёртки (оценка)
					$formula = "mel·d·2 ≈ {$mel}·{$d}·2";
					break;

				case 'pos':
					$pos_kind = (string) Arr::get($block, 'v', 'rope');
					$has_pos = ($pos_kind !== 'none');

					if ($pos_kind === 'learned')
					{
						$params = $S * $d;
						$formula = "S·d = {$S}·{$d}";
					}
					else
					{
						$formula = $pos_kind === 'rope' ? '0 (повороты q/k)' : '0 (смещения вниманию)';
					}
					break;

				case 'time':
					$params = $d * $d + 256 * $d; // freq MLP (оценка)
					$formula = "d² + 256·d ≈ (adaLN-модуляция)";
					break;

				case 'norm':
					$v = (string) Arr::get($block, 'v', 'rms');
					$params = $v === 'rms' ? $d : 2 * $d;
					$formula = $v === 'rms' ? "d = {$d}" : "2d = 2·{$d}";
					$depth ++;
					break;

				case 'attn':
				case 'xattn':
					$v = (string) Arr::get($block, 'v', $t === 'xattn' ? 'mha' : 'gqa');
					$H = (int) Arr::get($block, 'H', 32);
					$KV = (int) Arr::get($block, 'KV', $H);
					$hd = $H > 0 ? intdiv($d, $H) : $d;

					if ($v === 'mha' OR $t === 'xattn' OR $v === 'linear')
					{
						$params = 4 * $d * $d;
						$formula = "4d² = 4·{$d}²";
					}
					elseif ($v === 'mla')
					{
						$c = 512; // латент сжатия KV (оценка DeepSeek-стиля)
						$params = 2 * $d * $d + 2 * $d * $c;
						$formula = "2d² + 2·d·512 ≈ (латентное KV)";
					}
					else // gqa, mqa, sliding
					{
						$params = 2 * $d * $d + 2 * $d * $KV * $hd;
						$formula = "2d² + 2·d·KV·hd = 2·{$d}² + 2·{$d}·{$KV}·{$hd}";
					}

					// вклад в FLOPs внимания и KV-cache
					if ($v === 'sliding')
					{
						$W = (int) Arr::get($block, 'W', 4096);
						$attn_flops_d += 4 * min($W, $S) * $d;
					}
					elseif ($v === 'linear')
					{
						$attn_flops_d += 2 * $d * 64; // внешнее произведение (оценка)
					}
					else
					{
						$attn_flops_d += 4 * $S * $d;
					}

					if ($v === 'mla')
					{
						$kv_per_token += 2 * 576 * 2; // латент + rope (байты bf16)
					}
					else
					{
						$kv_per_token += 2 * $KV * $hd * 2;
					}

					$n_attn ++;
					$depth ++;
					break;

				case 'ffn':
					$v = (string) Arr::get($block, 'v', 'swiglu');
					$h = (int) Arr::get($block, 'h', 11008);
					$mult = ($v === 'gelu') ? 2 : 3;
					$params = $mult * $d * $h;
					$formula = $mult.'·d·h = '.$mult."·{$d}·{$h}";
					$depth ++;
					break;

				case 'moe':
					$E = (int) Arr::get($block, 'E', 8);
					$k = (int) Arr::get($block, 'k', 2);
					$shared = (bool) Arr::get($block, 'shared', FALSE);
					$h = (int) Arr::get($block, 'h', 11008);
					$expert = 3 * $d * $h; // SwiGLU-эксперт
					$params = $E * $expert + $d * $E + ($shared ? $expert : 0);
					$act = $k * $expert + $d * $E + ($shared ? $expert : 0);
					$formula = "E·3dh + d·E = {$E}·3·{$d}·{$h} + {$d}·{$E}";
					$n_moe ++;
					$depth ++;
					break;

				case 'ssm':
					$state = (int) Arr::get($block, 'state', 16);
					$expand = (int) Arr::get($block, 'expand', 2);
					$dt = (int) ceil($d / 16);
					$params = 3 * $expand * $d * $d
						+ $expand * $d * (2 * $dt + 3 * $state + 5);
					$formula = "≈ 3·expand·d² + прочее = 3·{$expand}·{$d}²";
					$attn_flops_d += 2 * $d * $state; // линейно, без S²
					$n_ssm ++;
					$depth ++;
					break;

				case 'head':
					$v = (string) Arr::get($block, 'v', 'lm');

					if ($v === 'lm')
					{
						$params = $tied ? 0 : $V * $d;
						$formula = $tied ? '0 (tied с эмбеддингом)' : "V·d = {$V}·{$d}";
					}
					else // diffusion: финальный слой DiT
					{
						$P = $patch ? (int) Arr::get($patch, 'P', 2) : 2;
						$C = $patch ? (int) Arr::get($patch, 'C', 4) : 4;
						$params = $d * $d + $d * $P * $P * $C;
						$formula = "d² + d·P²·C (adaLN + выход патчей)";
					}
					break;
			}

			$total += $params;
			$active += ($t === 'moe') ? $act : $params;

			$blocks[] = [
				't'       => $t,
				'label'   => Arr::get(self::palette()[$t] ?? [], 'label', $t),
				'group'   => Arr::get(self::palette()[$t] ?? [], 'group', ''),
				'params'  => $params,
				'formula' => $formula,
				'count'   => $count_label,
			];
		}

		// хукам — параметры LoRA (steer/tap/exit — 0: векторы и зонды фиксированы)
		foreach ($flat['hooks'] as $hook)
		{
			if ($hook['kind'] === 'lora')
			{
				$lora += 8 * $hook['r'] * $d; // q,k,v,o: 4 матрицы × (r·d + d·r)
			}
		}

		if ($total <= 0)
		{
			return ['errors' => ['В модели не нашлось ни одного параметра — добавьте слои.']];
		}

		// --- производные метрики -----------------------------------------
		$cfg = (array) Arr::get(self::config(), 'budget', []);
		$bytes_infer = (int) Arr::get($cfg, 'bytes_infer', 2);
		$bytes_train = (int) Arr::get($cfg, 'bytes_train', 16);
		$chinchilla = (int) Arr::get($cfg, 'chinchilla', 20);

		$flops_fwd_token = 2 * $active + $attn_flops_d;
		$tokens_opt = $chinchilla * max($active, 1);
		$train_flops = 6 * $active * $tokens_opt;

		$gpu_flops = (float) Arr::get($cfg, 'gpu_flops', 9.9e14) * (float) Arr::get($cfg, 'gpu_mfu', 0.4);
		$gpu_hours = $gpu_flops > 0 ? $train_flops / $gpu_flops / 3600 : 0;
		$gpu_cost = $gpu_hours * (float) Arr::get($cfg, 'gpu_price', 2.5);

		$kv_cache = $kv_per_token * $S;         // байт на последовательность
		$act_mem = $depth * $S * $d * 2 * 4;    // грубо: 4 тензора/слой, bf16

		$totals = [
			'params_total'   => $total,
			'params_active'  => $active,
			'params_lora'    => $lora,
			'depth'          => $depth,
			'n_attn'         => $n_attn,
			'n_moe'          => $n_moe,
			'n_ssm'          => $n_ssm,
			'flops_fwd_token'=> $flops_fwd_token,
			'tokens_opt'     => $tokens_opt,
			'train_flops'    => $train_flops,
			'gpu_hours'      => $gpu_hours,
			'gpu_cost'       => $gpu_cost,
			'gpu_name'       => (string) Arr::get($cfg, 'gpu_name', 'H100'),
			'vram_infer'     => $total * $bytes_infer + $kv_cache + $act_mem,
			'vram_weights'   => $total * $bytes_infer,
			'vram_train'     => $total * $bytes_train,
			'kv_cache_seq'   => $kv_cache,
			'kv_per_token'   => $kv_per_token,
			'seq'            => $S,
			'd'              => $d,
			'family'         => $family,
			'hooks'          => count($flat['hooks']),
			'lora_train_pct' => ($total + $lora) > 0 ? 100 * $lora / ($total + $lora) : 0,
		];

		return [
			'blocks'    => $blocks,
			'totals'    => $totals,
			'hooks'     => $flat['hooks'],
			'compare'   => self::compare($total, $active),
			'warnings'  => self::warnings($dsl, $flat, $totals, ['has_pos' => $has_pos, 'pos_kind' => $pos_kind, 'has_emb' => $has_emb, 'V' => $V, 'patch' => $patch]),
			'flat'      => $flat,
		];
	}

	/**
	 * Сравнение со зоопарком: ближайшие по порядку величины.
	 */
	public static function compare($total, $active)
	{
		$out = [];

		foreach (self::zoo() as $row)
		{
			$rt = $total > 0 ? $total / (float) $row['total'] : 0;
			$ra = $active > 0 ? $active / (float) $row['active'] : 0;
			$distance = abs(log10(max($rt, 1e-9)));

			$out[] = [
				'title'   => $row['title'],
				'note'    => $row['note'],
				'total'   => (float) $row['total'],
				'active'  => (float) $row['active'],
				'ratio_total'  => $rt,
				'ratio_active' => $ra,
				'distance' => $distance,
			];
		}

		usort($out, function ($a, $b) {
			return $a['distance'] <=> $b['distance'];
		});

		return array_slice($out, 0, 6);
	}

	/**
	 * Предупреждения и подсказки — «умная» часть конструктора.
	 */
	public static function warnings(array $dsl, array $flat, array $totals, array $ctx)
	{
		$out = [];
		$config = (array) Arr::get($dsl, 'config', []);
		$d = (int) Arr::get($config, 'd', 768);
		$S = (int) Arr::get($config, 'S', 2048);

		$add = function ($kind, $text) use (& $out) {
			$out[] = ['kind' => $kind, 'text' => $text];
		};

		// позиционирование
		if ( ! empty($flat['layers']) AND empty($ctx['has_pos']))
		{
			$has_attn = FALSE;

			foreach ($flat['layers'] as $l)
			{
				if ($l['t'] === 'attn')
				{
					$has_attn = TRUE;
				}
			}

			if ($has_attn)
			{
				$add('warn', 'Внимание без позиционирования: модель станет «мешком слов» — добавьте RoPE или ALiBi.');
			}
		}

		// GQA-подсказка
		foreach ($flat['layers'] as $l)
		{
			if ($l['t'] === 'attn' AND (string) Arr::get($l, 'v', '') === 'mha' AND $S >= 8192)
			{
				$add('info', 'MHA на длинном контексте: GQA (KV < H) сократит KV-cache в разы — так делают Llama/Qwen.');
				break;
			}
		}

		// sliding окно меньше контекста
		foreach ($flat['layers'] as $l)
		{
			if ($l['t'] === 'attn' AND (string) Arr::get($l, 'v', '') === 'sliding')
			{
				$W = (int) Arr::get($l, 'W', 4096);

				if ($W < $S / 2)
				{
					$add('warn', "Окно внимания {$W} заметно меньше контекста {$S}: дальние связи модель не увидит (это осознанный трюк экономии — но помните о нём).");
				}
				break;
			}
		}

		// MoE
		foreach ($flat['layers'] as $l)
		{
			if ($l['t'] !== 'moe')
			{
				continue;
			}

			$E = (int) Arr::get($l, 'E', 8);
			$k = (int) Arr::get($l, 'k', 2);

			if ($k === 1)
			{
				$add('warn', 'top-1 роутинг в MoE нестабилен при обучении (дрожание выбора эксперта); обычно берут k=2+.');
			}

			if ($k >= $E)
			{
				$add('warn', 'k ≥ E — все эксперты активны всегда: это уже не MoE, а дорогая dense-модель.');
			}

			if ($totals['params_active'] > 0 AND $totals['params_active'] / max($totals['params_total'], 1) < 0.15)
			{
				$add('info', 'Активных параметров < 15%: словарь общения «эксперты↔устройства» станет узким местом на кластере (коммуникация between GPU).');
			}

			if ($d < 512)
			{
				$add('warn', 'MoE при d < 512: эксперты выходят слишком тонкими — падение выгоды от разреженности.');
			}
			break;
		}

		// SwiGLU кратность
		foreach ($flat['layers'] as $l)
		{
			if ($l['t'] === 'ffn' AND (string) Arr::get($l, 'v', '') === 'swiglu')
			{
				$h = (int) Arr::get($l, 'h', 0);

				if ($h % 256 !== 0)
				{
					$add('info', "Скрытая размерность {$h} не кратна 256 — тензорные ядра GPU работают эффективнее на кратных (14336, 11008, 2816…).");
				}
				break;
			}
		}

		// словарь
		if ( ! empty($ctx['V']) AND $ctx['V'] < 32000 AND (string) Arr::get($dsl, 'family', '') === 'decoder')
		{
			$add('info', 'Словарь < 32k: длинные редкие слова разобьются на много токенов — для мультиязычности берите 100k+.');
		}

		// глубокая-узкая
		if ($totals['depth'] >= 48 AND $d < 1024)
		{
			$add('warn', 'Глубокая и узкая модель (много слоёв при d < 1024): хуже обучается — градиенты затухают, выгоды масштабирования не получить.');
		}

		// нормализация
		$has_norm = FALSE;

		foreach ($flat['layers'] as $l)
		{
			if ($l['t'] === 'norm')
			{
				$has_norm = TRUE;
			}
		}

		if ( ! $has_norm AND ! empty($flat['layers']))
		{
			$add('error', 'Нет нормализации: глубокая сеть без norm практически не обучается (добавьте RMSNorm перед attn/ffn).');
		}

		// семейство и состав стека
		$family = (string) Arr::get($dsl, 'family', 'decoder');

		if ($family === 'decoder' AND empty($ctx['has_emb']))
		{
			$add('error', 'Декодер без токен-эмбеддинга: тексту нечего превращать в векторы.');
		}

		if ($family === 'diffusion' AND $ctx['patch'] === NULL)
		{
			$add('error', 'Диффузия без patch-входа: DiT работает с latent-патчами изображения.');
		}

		// кросс-аттн без второй модальности
		$has_other_modality = ($ctx['patch'] !== NULL);
		$has_xattn = FALSE;

		foreach ($flat['layers'] as $l)
		{
			if ($l['t'] === 'xattn')
			{
				$has_xattn = TRUE;
			}
		}

		if ($has_xattn AND ! $has_other_modality)
		{
			$add('warn', 'Кросс-внимание есть, а второй модальности (patch-вход) нет — сливать будет нечего.');
		}

		// гибрид — похвала
		if ($totals['n_attn'] > 0 AND $totals['n_ssm'] > 0)
		{
			$add('ok', 'Гибрид внимание+SSM (идея Jamba/Mamba-2): дешёвый длинный контекст с точным «островком» внимания.');
		}

		// хуки
		foreach ($flat['hooks'] as $hook)
		{
			if ($hook['kind'] === 'steer' AND $hook['s'] > 1)
			{
				$add('warn', 'Сила steering > 1: вектор начнёт ломать смысл активаций — обычно хватает 0.3..0.8.');
			}

			if ($hook['kind'] === 'lora' AND $hook['r'] > 256)
			{
				$add('info', 'LoRA ранга > 256 — редкость: обычно 8..64 достаточно, выше уже ближе к полному файнтюну.');
			}

			if ($hook['kind'] === 'steer' AND trim($hook['text']) === '')
			{
				$add('warn', 'Steering-хук без текста-смысла: в симуляции и коде он ничего не сделает.');
			}
		}

		// KV-cache и контекст
		if ($totals['kv_cache_seq'] > 4 * 1024 * 1024 * 1024)
		{
			$add('info', 'KV-cache на полный контекст > 4 ГБ на одну последовательность: для длинных контекстов смотрите в сторону GQA/MLA/SSM.');
		}

		return $out;
	}

	// ------------------------------------------------------------------
	// Форматирование (вьюхи)
	// ------------------------------------------------------------------

	/** Человекочитаемые размеры: байты → ГБ/МБ; числа → 12.3B. */
	public static function fmt_params($n)
	{
		$n = (float) $n;

		if ($n >= 1e12) return round($n / 1e12, 2).'T';
		if ($n >= 1e9)  return round($n / 1e9, 2).'B';
		if ($n >= 1e6)  return round($n / 1e6, 1).'M';
		if ($n >= 1e3)  return round($n / 1e3, 1).'K';

		return (string) (int) $n;
	}

	public static function fmt_bytes($n)
	{
		$n = (float) $n;
		$u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
		$i = 0;

		while ($n >= 1024 AND $i < 4)
		{
			$n /= 1024;
			$i++;
		}

		return ($i > 1 ? round($n, 1) : round($n)).' '.$u[$i];
	}

	public static function fmt_num($n)
	{
		return number_format((float) $n, 0, ',', ' ');
	}

	public static function fmt_ratio($r)
	{
		if ($r >= 1) return '×'.round($r, $r >= 10 ? 0 : 1);

		return '×1/'.round(1 / max($r, 1e-9), $r < 0.1 ? 0 : 1);
	}

	// ------------------------------------------------------------------
	// Экспорт: PyTorch-скелет
	// ------------------------------------------------------------------

	/**
	 * Детерминированная генерация PyTorch-скелета из DSL.
	 * Хуки steer становятся настоящими register_forward_hook.
	 */
	public static function pytorch(array $dsl, array $totals = NULL, $title = '')
	{
		$errors = self::validate($dsl);

		if ($errors)
		{
			return "# DSL невалиден: ".implode(' ', $errors)."\n";
		}

		$eval = $totals === NULL ? self::evaluate($dsl) : NULL;

		if ($totals === NULL)
		{
			$totals = $eval['totals'];
		}

		$config = (array) Arr::get($dsl, 'config', []);
		$d = (int) Arr::get($config, 'd', 768);
		$S = (int) Arr::get($config, 'S', 2048);
		$flat = self::flatten($dsl);

		// первый attn-блок — источник варианта внимания и голов
		$H = 32; $KV = 8; $attn_v = 'gqa';

		foreach ($flat['layers'] as $l)
		{
			if ($l['t'] === 'attn')
			{
				$H = (int) Arr::get($l, 'H', 32);
				$KV = (int) Arr::get($l, 'KV', 8);
				$attn_v = (string) Arr::get($l, 'v', 'gqa');
				break;
			}
		}

		$ffn_h = 11008;
		$ffn_v = 'swiglu';

		foreach ($flat['layers'] as $l)
		{
			if ($l['t'] === 'ffn')
			{
				$ffn_h = (int) Arr::get($l, 'h', 11008);
				$ffn_v = (string) Arr::get($l, 'v', 'swiglu');
				break;
			}
		}

		$moe = NULL;

		foreach ($flat['layers'] as $l)
		{
			if ($l['t'] === 'moe')
			{
				$moe = $l;
				break;
			}
		}

		$has_ssm = FALSE;

		foreach ($flat['layers'] as $l)
		{
			if ($l['t'] === 'ssm')
			{
				$has_ssm = TRUE;
				break;
			}
		}

		$V = 32000;

		foreach ($flat['layers'] as $l)
		{
			if ($l['t'] === 'emb')
			{
				$V = (int) Arr::get($l, 'V', 32000);
				break;
			}
		}

		$n_layers = 0;

		foreach ($flat['layers'] as $l)
		{
			if (in_array($l['t'], ['attn', 'ffn', 'moe', 'ssm', 'xattn'], TRUE))
			{
				$n_layers++;
			}
		}

		$n_blocks = max(1, $n_layers);
		$title = trim($title) !== '' ? trim($title) : 'ArchModel';
		$safe_title = preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $title));

		if ($safe_title === '')
		{
			$safe_title = 'ArchModel';
		}

		$hooks_py = [];
		$steer_lines = [];

		foreach ($flat['hooks'] as $hook)
		{
			if ($hook['kind'] === 'steer' AND $hook['text'] !== '')
			{
				$steer_lines[] = [
					'at' => (int) $hook['at'],
					's' => (float) $hook['s'],
					'text' => $hook['text'],
				];
			}
		}

		$out = [];
		$out[] = '# -*- coding: utf-8 -*-';
		$out[] = '# Сгенерировано конструктором архитектур N2J (nj660_arch).';
		$out[] = '# Модель: '.$title.' — ~'.self::fmt_params($totals['params_total']).' параметров';
		$out[] = '# (активных за проход: '.self::fmt_params($totals['params_active']).'), глубина '.$totals['depth'].' слоёв.';
		$out[] = '# Это скелет-прототип: структура и хуки рабочие, детали (инициализация,';
		$out[] = '# оптимизатор, данные) — за вами. Экспериментируйте смело.';
		$out[] = '';
		$out[] = 'import math';
		$out[] = 'import torch';
		$out[] = 'import torch.nn as nn';
		$out[] = 'import torch.nn.functional as F';
		$out[] = '';
		$out[] = '';
		$out[] = 'class Config:';
		$out[] = '    d_model = '.$d;
		$out[] = '    n_heads = '.$H;
		$out[] = '    n_kv_heads = '.$KV;
		$out[] = '    head_dim = '.$d.' // '.$H;
		$out[] = '    n_layers = '.$n_blocks;
		$out[] = '    vocab_size = '.$V;
		$out[] = '    max_seq = '.$S;
		$out[] = '    ffn_hidden = '.$ffn_h;
		$out[] = '    attn_variant = "'.$attn_v.'"   # '.$attn_v;
		$out[] = '    rope_base = 10000.0';
		$out[] = '';

		if ($moe !== NULL)
		{
			$out[] = '    n_experts = '.(int) Arr::get($moe, 'E', 8);
			$out[] = '    top_k = '.(int) Arr::get($moe, 'k', 2);
			$out[] = '    expert_hidden = '.(int) Arr::get($moe, 'h', 11008);
			$out[] = '    shared_expert = '.((bool) Arr::get($moe, 'shared', FALSE) ? 'True' : 'False');
			$out[] = '';
		}

		if ($has_ssm)
		{
			$out[] = '    ssm_state = 16';
			$out[] = '    ssm_expand = 2';
			$out[] = '';
		}

		$out[] = '';
		$out[] = 'def rope_freqs(dim: int, seq: int, base: float = 10000.0):';
		$out[] = '    """RoPE: частоты поворотов для q/k (0 параметров)."""';
		$out[] = '    inv = 1.0 / (base ** (torch.arange(0, dim, 2).float() / dim))';
		$out[] = '    t = torch.arange(seq).float()';
		$out[] = '    freqs = torch.outer(t, inv)';
		$out[] = '    return torch.polar(torch.ones_like(freqs), freqs)  # e^{iθ}';
		$out[] = '';
		$out[] = '';
		$out[] = 'class RMSNorm(nn.Module):';
		$out[] = '    """RMSNorm: без вычитания среднего — быстрее LayerNorm (рецепт Llama)."""';
		$out[] = '';
		$out[] = '    def __init__(self, dim: int, eps: float = 1e-6):';
		$out[] = '        super().__init__()';
		$out[] = '        self.eps = eps';
		$out[] = '        self.weight = nn.Parameter(torch.ones(dim))';
		$out[] = '';
		$out[] = '    def forward(self, x):';
		$out[] = '        norm = x * torch.rsqrt(x.pow(2).mean(-1, keepdim=True) + self.eps)';
		$out[] = '        return norm * self.weight';
		$out[] = '';
		$out[] = '';
		$out[] = 'class Attention(nn.Module):';
		$out[] = '    """Self-attention: вариант из конфига (mha/gqa/mqa/sliding)."""';
		$out[] = '';
		$out[] = '    def __init__(self, cfg: Config):';
		$out[] = '        super().__init__()';
		$out[] = '        self.cfg = cfg';
		$out[] = '        hd = cfg.head_dim';
		$out[] = '        self.q = nn.Linear(cfg.d_model, cfg.n_heads * hd, bias=False)';
		$out[] = '        self.k = nn.Linear(cfg.d_model, cfg.n_kv_heads * hd, bias=False)';
		$out[] = '        self.v = nn.Linear(cfg.d_model, cfg.n_kv_heads * hd, bias=False)';
		$out[] = '        self.o = nn.Linear(cfg.n_heads * hd, cfg.d_model, bias=False)';
		$out[] = '';
		$out[] = '    def forward(self, x, freqs=None):';
		$out[] = '        B, T, C = x.shape';
		$out[] = '        hd, H, KV = self.cfg.head_dim, self.cfg.n_heads, self.cfg.n_kv_heads';
		$out[] = '        q = self.q(x).view(B, T, H, hd)';
		$out[] = '        k = self.k(x).view(B, T, KV, hd)';
		$out[] = '        v = self.v(x).view(B, T, KV, hd)';
		$out[] = '';
		$out[] = '        if freqs is not None:  # RoPE на q/k';
		$out[] = '            q = torch.view_as_real(q.float().view(B, T, H, hd // 2, 2) * freqs[None, :, None]).view(B, T, H, hd)';
		$out[] = '';
		$out[] = '        k = k.repeat_interleave(H // KV, dim=2)  # GQA: раздуть KV до H';
		$out[] = '        v = v.repeat_interleave(H // KV, dim=2)';
		$out[] = '        y = F.scaled_dot_product_attention(';
		$out[] = '            q.transpose(1, 2), k.transpose(1, 2), v.transpose(1, 2), is_causal=True)';
		$out[] = '        return self.o(y.transpose(1, 2).reshape(B, T, H * hd))';
		$out[] = '';
		$out[] = '';
		$out[] = 'class FeedForward(nn.Module):';
		$out[] = '    """FFN: SwiGLU (3 матрицы) или GELU (2 матрицы)."""';
		$out[] = '';
		$out[] = '    def __init__(self, cfg: Config):';
		$out[] = '        super().__init__()';
		$out[] = '        if "'.$ffn_v.'" == "swiglu":';
		$out[] = '            self.gate = nn.Linear(cfg.d_model, cfg.ffn_hidden, bias=False)';
		$out[] = '            self.up = nn.Linear(cfg.d_model, cfg.ffn_hidden, bias=False)';
		$out[] = '            self.down = nn.Linear(cfg.ffn_hidden, cfg.d_model, bias=False)';
		$out[] = '            self.mode = "swiglu"';
		$out[] = '        else:';
		$out[] = '            self.up = nn.Linear(cfg.d_model, cfg.ffn_hidden)';
		$out[] = '            self.down = nn.Linear(cfg.ffn_hidden, cfg.d_model)';
		$out[] = '            self.mode = "gelu"';
		$out[] = '';
		$out[] = '    def forward(self, x):';
		$out[] = '        if self.mode == "swiglu":';
		$out[] = '            return self.down(F.silu(self.gate(x)) * self.up(x))';
		$out[] = '        return self.down(F.gelu(self.up(x)))';

		if ($moe !== NULL)
		{
			$E = (int) Arr::get($moe, 'E', 8);
			$k = (int) Arr::get($moe, 'k', 2);
			$out[] = '';
			$out[] = '';
			$out[] = 'class MoE(nn.Module):';
			$out[] = '    """Mixture of Experts: роутер выбирает '.$k.' из '.$E.' экспертов на каждый токен."""';
			$out[] = '';
			$out[] = '    def __init__(self, cfg: Config):';
			$out[] = '        super().__init__()';
			$out[] = '        self.k = cfg.top_k';
			$out[] = '        self.router = nn.Linear(cfg.d_model, cfg.n_experts, bias=False)';
			$out[] = '        self.experts = nn.ModuleList([FeedForward(cfg) for _ in range(cfg.n_experts)])';
			$out[] = '        self.shared = FeedForward(cfg) if cfg.shared_expert else None';
			$out[] = '';
			$out[] = '    def forward(self, x):';
			$out[] = '        B, T, C = x.shape';
			$out[] = '        logits = self.router(x)                      # (B, T, E)';
			$out[] = '        weights, idx = torch.topk(logits, self.k)    # топ-k экспертов';
			$out[] = '        weights = torch.softmax(weights, dim=-1)';
			$out[] = '        y = torch.zeros_like(x)';
			$out[] = '        for e, expert in enumerate(self.experts):';
			$out[] = '            mask = (idx == e)                        # где этот эксперт активен';
			$out[] = '            if mask.any():';
			$out[] = '                w = weights.masked_fill(~mask, 0.0).sum(-1, keepdim=True)';
			$out[] = '                y = y + w * expert(x)';
			$out[] = '        if self.shared is not None:';
			$out[] = '            y = y + self.shared(x)';
			$out[] = '        return y';
		}

		$out[] = '';
		$out[] = '';
		$out[] = 'class Block(nn.Module):';
		$out[] = '    """Один слой: pre-RMSNorm → внимание → остаток → pre-RMSNorm → FFN/MoE → остаток."""';
		$out[] = '';
		$out[] = '    def __init__(self, cfg: Config):';
		$out[] = '        super().__init__()';
		$out[] = '        self.n1 = RMSNorm(cfg.d_model)';
		$out[] = '        self.attn = Attention(cfg)';

		if ($moe !== NULL)
		{
			$out[] = '        self.n2 = RMSNorm(cfg.d_model)';
			$out[] = '        self.moe = MoE(cfg)';
		}
		elseif ($has_ssm)
		{
			$out[] = '        self.n2 = RMSNorm(cfg.d_model)';
			$out[] = '        self.ffn = FeedForward(cfg)  # SSM-вариант: см. Mamba-блок';
		}
		else
		{
			$out[] = '        self.n2 = RMSNorm(cfg.d_model)';
			$out[] = '        self.ffn = FeedForward(cfg)';
		}

		$out[] = '';
		$out[] = '    def forward(self, x, freqs=None):';
		$out[] = '        x = x + self.attn(self.n1(x), freqs)';
		$out[] = '        x = x + self.ffn(self.n2(x))';
		$out[] = '        return x';
		$out[] = '';
		$out[] = '';
		$out[] = 'class '.$safe_title.'(nn.Module):';
		$out[] = '    """'.$title.' — '.self::fmt_params($totals['params_total']).' параметров, глубина '.$totals['depth'].'."""';
		$out[] = '';
		$out[] = '    def __init__(self, cfg: Config = Config()):';
		$out[] = '        super().__init__()';
		$out[] = '        self.cfg = cfg';
		$out[] = '        self.embed = nn.Embedding(cfg.vocab_size, cfg.d_model)';
		$out[] = '        self.blocks = nn.ModuleList([Block(cfg) for _ in range(cfg.n_layers)])';
		$out[] = '        self.norm = RMSNorm(cfg.d_model)';
		$out[] = '        self.head = nn.Linear(cfg.d_model, cfg.vocab_size, bias=False)';

		if ( ! empty($steer_lines))
		{
			$out[] = '        # Хуки из конструктора: steering-векторы на заданных слоях.';
			$out[] = '        # В скелете вектор нулевой (смысл задаётся обучением),';
			$out[] = '        # register_forward_hook — тот самый механизм «вмешательства в середину».';
			$i = 0;

			foreach ($steer_lines as $s)
			{
				$out[] = '        v'.$i.' = nn.Parameter(torch.zeros(cfg.d_model), requires_grad=True)';
				$out[] = '        self.blocks['.max(0, $s['at'] - 1).'].register_forward_hook(';
				$out[] = '            self._steer_hook(v'.$i.', '.$s['s'].'))  # «'.$s['text'].'»';
				$i++;
			}
		}

		$out[] = '';
		$out[] = '    @staticmethod';
		$out[] = '    def _steer_hook(vec, strength):';
		$out[] = '        def hook(module, inp, out):';
		$out[] = '            return out + strength * vec  # прибавить направление к активациям';
		$out[] = '        return hook';
		$out[] = '';
		$out[] = '    def forward(self, tokens):';
		$out[] = '        B, T = tokens.shape';
		$out[] = '        x = self.embed(tokens)';
		$out[] = '        freqs = rope_freqs(self.cfg.head_dim, T, self.cfg.rope_base)';
		$out[] = '        for block in self.blocks:';
		$out[] = '            x = block(x, freqs)';
		$out[] = '        return self.head(self.norm(x))';
		$out[] = '';
		$out[] = '';
		$out[] = 'if __name__ == "__main__":';
		$out[] = '    cfg = Config()';
		$out[] = '    model = '.$safe_title.'(cfg)';
		$out[] = '    n = sum(p.numel() for p in model.parameters())';
		$out[] = '    print(f"параметров: {n:,}")  # в конструкторе: '.self::fmt_params($totals['params_total']);
		$out[] = '    tokens = torch.randint(0, cfg.vocab_size, (2, 64))';
		$out[] = '    print("logits:", model(tokens).shape)';
		$out[] = '';

		return implode("\n", $out)."\n";
	}

	// ------------------------------------------------------------------
	// Хранение: проекты и прогоны
	// ------------------------------------------------------------------

	protected static function _db()
	{
		static $db = NULL;

		return $db !== NULL ? $db : $db = Database::instance();
	}

	/**
	 * Сохранить проект: новый код или обновление своего.
	 *
	 * @param  array  $dsl
	 * @param  string $title
	 * @param  string $owner Visitor::uid()
	 * @param  int    $user_id
	 * @param  string $code  код существующего (обновление) или ''
	 * @return array  ['ok'=>TRUE,'code'=>..] | ['error'=>..]
	 */
	public static function save(array $dsl, $title, $owner, $user_id, $code = '')
	{
		$errors = self::validate($dsl);

		if ($errors)
		{
			return ['error' => implode(' ', $errors)];
		}

		$eval = self::evaluate($dsl);
		$db = self::_db();
		$title = mb_substr(trim((string) $title), 0, (int) Arr::get((array) Arr::get(self::config(), 'limits', []), 'max_title', 120));
		$dsl_json = json_encode($dsl, JSON_UNESCAPED_UNICODE);
		$metrics_json = json_encode(['totals' => $eval['totals']], JSON_UNESCAPED_UNICODE);

		if ($code !== '')
		{
			$existing = self::find($code);

			if ($existing === NULL)
			{
				return ['error' => 'Проект не найден'];
			}

			if ( ! self::_owns($existing, $owner, $user_id))
			{
				return ['error' => 'Это не ваш проект'];
			}

			$sql = 'UPDATE nj_660_designs SET title = '.$db->quote($title).',
				family = '.$db->quote((string) Arr::get($dsl, 'family', 'decoder')).',
				dsl = '.$db->quote($dsl_json).', metrics = '.$db->quote($metrics_json).',
				updated_at = now()
				WHERE id = '.(int) $existing['id'];
			$db->query(Database::UPDATE, $sql, FALSE);

			return ['ok' => TRUE, 'code' => $code];
		}

		$new_code = self::_new_code();

		$sql = 'INSERT INTO nj_660_designs (code, user_id, visitor, title, family, dsl, metrics)
			VALUES ('.$db->quote($new_code).', '
			.($user_id !== NULL ? (int) $user_id : 'NULL').', '
			.$db->quote((string) $owner).', '
			.$db->quote($title !== '' ? $title : 'Без названия').', '
			.$db->quote((string) Arr::get($dsl, 'family', 'decoder')).', '
			.$db->quote($dsl_json).', '.$db->quote($metrics_json).')';
		$db->query(Database::INSERT, $sql, FALSE);

		return ['ok' => TRUE, 'code' => $new_code];
	}

	/** Проект по коду (или NULL). */
	public static function find($code)
	{
		$db = self::_db();
		$row = $db->query(Database::SELECT,
			'SELECT * FROM nj_660_designs WHERE code = '.$db->quote((string) $code), FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	/** Мои проекты (владелец = пользователь или гость-браузер). */
	public static function mine($owner, $user_id, $limit = 50)
	{
		$db = self::_db();
		$sql = 'SELECT * FROM nj_660_designs WHERE ';

		if ($user_id !== NULL)
		{
			$sql .= 'user_id = '.(int) $user_id;
		}
		else
		{
			$sql .= 'visitor = '.$db->quote((string) $owner).' AND user_id IS NULL';
		}

		$sql .= ' ORDER BY id DESC LIMIT '.(int) $limit;

		return $db->query(Database::SELECT, $sql, FALSE)->as_array();
	}

	/** Удалить свой проект. */
	public static function delete($code, $owner, $user_id)
	{
		$row = self::find($code);

		if ($row === NULL OR ! self::_owns($row, $owner, $user_id))
		{
			return FALSE;
		}

		self::_db()->query(Database::DELETE,
			'DELETE FROM nj_660_designs WHERE id = '.(int) $row['id'], FALSE);

		return TRUE;
	}

	protected static function _owns(array $row, $owner, $user_id)
	{
		if ($user_id !== NULL AND (int) Arr::get($row, 'user_id', 0) === (int) $user_id)
		{
			return TRUE;
		}

		return Arr::get($row, 'visitor', '') === (string) $owner AND empty($row['user_id']);
	}

	protected static function _new_code()
	{
		$alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';

		do
		{
			$code = '';

			for ($i = 0; $i < 10; $i++)
			{
				$code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
			}
		}
		while (self::find($code) !== NULL);

		return $code;
	}

	// ------------------------------------------------------------------
	// Прогоны LLM
	// ------------------------------------------------------------------

	public static function log_run($design_id, $kind, $owner, $user_id, $prompt, $model, $ms, $output, $error = '')
	{
		$db = self::_db();

		$sql = 'INSERT INTO nj_660_runs (design_id, kind, user_id, visitor, prompt, model, ms, output, error)
			VALUES ('.(int) $design_id.', '.$db->quote((string) $kind).', '
			.($user_id !== NULL ? (int) $user_id : 'NULL').', '.$db->quote((string) $owner).', '
			.$db->quote((string) $prompt).', '.$db->quote((string) $model).', '.(int) $ms.', '
			.$db->quote((string) $output).', '.$db->quote((string) $error).')';
		$db->query(Database::INSERT, $sql, FALSE);
	}

	public static function runs_for($design_id, $limit = 12)
	{
		return self::_db()->query(Database::SELECT,
			'SELECT * FROM nj_660_runs WHERE design_id = '.(int) $design_id.'
			 ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();
	}

	/** Прогонов этого посетителя за последний час (лимит LLM-операций). */
	public static function runs_recent($owner, $kind, $seconds = 3600)
	{
		$db = self::_db();
		$row = $db->query(Database::SELECT,
			'SELECT count(*) AS n FROM nj_660_runs
			 WHERE visitor = '.$db->quote((string) $owner)."
			   AND kind = ".$db->quote((string) $kind).'
			   AND created_at > now() - interval \''.(int) $seconds.' seconds\'', FALSE)->current();

		return $row ? (int) $row['n'] : 0;
	}
}
