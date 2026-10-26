<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-660 «Конструктор архитектуры ИИ» — LLM-слой.
 *
 * Две операции с реальной LLM (фасад Llm::, одиночный быстрый вызов —
 * тот же паттерн, что у вебхука nj657: пользователь ждёт ответ синхронно):
 *
 *   review($design, $eval)   — экспертиза архитектуры: строгий JSON
 *                              {score, verdict, strengths, risks, advice, reading};
 *   simulate($design, $eval, $prompt, $history) — «оживление» спроектированной
 *      модели: движок отвечает в характере конфигурации. Правила отображения
 *      (познавательная часть):
 *        * хук steer на слое k с силой s → директива поведения: глубина слоя
 *          задаёт характер влияния (ранние слои — стиль/формат, средние —
 *          тональность, поздние — содержание/факты), сила — жёсткость;
 *        * MoE → «внутренние узкие эксперты»: ответ собирает узкий специалист;
 *        * контекст S → окно памяти диалога: старые реплики «забываются»;
 *        * d_model → богатство словаря ответа.
 *
 * Симуляция — имитация поведения, не исполнение весов: движок указывается
 * в ответе, чтобы не вводить в заблуждение.
 */
class Service_Nj660_Expert
{
	/** Модель движка по умолчанию (для честного показа «кто отвечал»). */
	public static function engine_model()
	{
		try
		{
			return (string) Kohana::$config->load('llm')->get('default_model');
		}
		catch (Throwable $e)
		{
			return '';
		}
	}

	// ------------------------------------------------------------------
	// Экспертиза
	// ------------------------------------------------------------------

	/**
	 * Экспертиза архитектуры реальной LLM.
	 *
	 * @param  array $design строка nj_660_designs
	 * @param  array $eval   результат Service_Nj660_Arch::evaluate()
	 * @return array ['ok','model','ms','review' => array|NULL, 'text' => string, 'error']
	 */
	public static function review(array $design, array $eval)
	{
		$cfg = (array) Arr::get(Service_Nj660_Arch::config(), 'llm', []);
		$totals = $eval['totals'];

		$summary = self::_design_summary($design, $eval);
		$warnings = '';

		foreach ((array) Arr::get($eval, 'warnings', []) as $w)
		{
			$warnings .= '- ['.Arr::get($w, 'kind', 'info').'] '.Arr::get($w, 'text', '')."\n";
		}

		$system = 'Ты — опытный исследователь нейросетевых архитектур (ML research engineer). '
			.'Тебе дают спроектированную в визуальном конструкторе модель: состав стека, '
			.'посчитанные параметры, бюджет и предупреждения автоматического линтера. '
			.'Твоя задача — полезная экспертиза: подтвердить сильные решения, найти риски, '
			.'предложить конкретные улучшения (с числами и блоками конструктора). '
			.'Отвечай ТОЛЬКО валидным JSON без markdown-обёрток по схеме: '
			.'{"score": 0-10, "verdict": "1-2 предложения", "strengths": ["..."], '
			.'"risks": [{"what": "...", "fix": "..."}], "advice": ["..."], '
			.'"reading": ["что почитать/какая модель так сделана"]}. '
			.'Все тексты — по-русски, списки по 2-4 пункта, конкретно.';

		$user = "Проект: «{$design['title']}»\n\n".$summary;

		if ($warnings !== '')
		{
			$user .= "\nПредупреждения линтера конструктора:\n".$warnings;
		}

		$user .= "\nДай экспертизу в JSON.";

		$options = ['max_tokens' => (int) Arr::get($cfg, 'review_max_tokens', 1200)];

		$out = self::_chat($system, [['role' => 'user', 'content' => $user]], $options);

		if (isset($out['error']))
		{
			return $out;
		}

		$parsed = self::_parse_json($out['answer']);

		if ($parsed === NULL)
		{
			// не JSON — сохраняем как текст, вьюха покажет как есть
			return $out + ['review' => NULL];
		}

		// нормализация под схему вьюхи
		$risks = [];

		foreach ((array) Arr::get($parsed, 'risks', []) as $risk)
		{
			if (is_string($risk))
			{
				$risks[] = ['what' => $risk, 'fix' => ''];
			}
			elseif (is_array($risk))
			{
				$risks[] = ['what' => (string) Arr::get($risk, 'what', ''), 'fix' => (string) Arr::get($risk, 'fix', '')];
			}
		}

		$review = [
			'score'     => min(10, max(0, (float) Arr::get($parsed, 'score', 0))),
			'verdict'   => (string) Arr::get($parsed, 'verdict', ''),
			'strengths' => array_values(array_filter(array_map('strval', (array) Arr::get($parsed, 'strengths', [])))),
			'risks'     => $risks,
			'advice'    => array_values(array_filter(array_map('strval', (array) Arr::get($parsed, 'advice', [])))),
			'reading'   => array_values(array_filter(array_map('strval', (array) Arr::get($parsed, 'reading', [])))),
		];

		return $out + ['review' => $review];
	}

	// ------------------------------------------------------------------
	// Симуляция поведения
	// ------------------------------------------------------------------

	/**
	 * «Оживить» спроектированную модель: ответить на промпт в характере
	 * конфигурации (хуки, MoE, контекст, словарь).
	 *
	 * @param  array $design  строка nj_660_designs
	 * @param  array $eval    результат evaluate()
	 * @param  string $prompt вопрос пользователя
	 * @param  array  $history предыдущие реплики [['role'=>'user'|'assistant','content'=>..]]
	 * @return array ['ok','model','ms','answer','error']
	 */
	public static function simulate(array $design, array $eval, $prompt, array $history = [])
	{
		$cfg = (array) Arr::get(Service_Nj660_Arch::config(), 'llm', []);
		$totals = $eval['totals'];

		$system = self::_persona($design, $eval);
		$messages = [];

		// эмуляция контекстного окна спроектированной модели: в историю
		// уезжают только последние turns реплик (конфиг), остальное модель
		// «не помнит» — как в реальной жизни с окном S
		$turns = (int) Arr::get($cfg, 'simulate_history_turns', 8);
		$messages = array_slice($history, -$turns);
		$messages[] = ['role' => 'user', 'content' => (string) $prompt];

		$options = ['max_tokens' => (int) Arr::get($cfg, 'simulate_max_tokens', 700)];

		return self::_chat($system, $messages, $options);
	}

	/**
	 * Системный промпт «персонажа» спроектированной модели.
	 * Чистая функция — тестируется стендом.
	 */
	public static function _persona(array $design, array $eval)
	{
		$totals = $eval['totals'];
		$dsl = json_decode((string) Arr::get($design, 'dsl', '{}'), TRUE);
		$config = (array) Arr::get($dsl, 'config', []);
		$d = (int) Arr::get($config, 'd', 768);
		$S = (int) Arr::get($config, 'S', 2048);
		$title = (string) Arr::get($design, 'title', 'модель');

		$p = 'Ты — языковая модель «'.$title.'», спроектированная в конструкторе архитектур. '
			.'Отвечай на запрос так, как отвечала бы ИМЕННО эта конфигурация, а не обычный ассистент. '
			.'Правила отражения архитектуры в поведение:';

		// словарь/богатство
		if ($d < 1024)
		{
			$p .= "\n- d_model={$d} (маленькая): отвечай просто, короткими фразами, без редких слов.";
		}
		elseif ($d > 6144)
		{
			$p .= "\n- d_model={$d} (огромная): можно богатый словарь и сложные конструкции.";
		}
		else
		{
			$p .= "\n- d_model={$d}: обычная выразительность языка.";
		}

		// память/контекст
		$p .= "\n- Контекстное окно {$S} токенов: помни только недавний диалог; о чём была речь "
			.'задолго до окна — «не знаешь», вежливо уточни.';

		// MoE
		if ((int) Arr::get($totals, 'n_moe', 0) > 0)
		{
			$p .= "\n- Внутри MoE ({$totals['n_moe']} MoE-слоёв, активна малая часть параметров из "
				.Service_Nj660_Arch::fmt_params($totals['params_total']).'): отвечай как узкий '
				.'профильный эксперт — сфокусированно на сути вопроса, без размазни.';
		}

		// SSM
		if ((int) Arr::get($totals, 'n_ssm', 0) > 0)
		{
			$p .= "\n- Часть слоёв — SSM (Mamba-стиль): последовательное «процессуальное» мышление, "
				.'любые длинные цепочки рассуждений веди по порядку, шаг за шагом.';
		}

		// хуки — главная познавательная часть
		foreach ((array) Arr::get($eval, 'hooks', []) as $hook)
		{
			if (Arr::get($hook, 'kind') !== 'steer')
			{
				continue;
			}

			$at = (int) Arr::get($hook, 'at', 1);
			$s = (float) Arr::get($hook, 's', 0.5);
			$text = trim((string) Arr::get($hook, 'text', ''));

			if ($text === '')
			{
				continue;
			}

			$depth_hint = 'содержание и факты';

			if ($at <= max(1, (int) Arr::get($totals, 'depth', 1)) / 3)
			{
				$depth_hint = 'стиль и формат речи';
			}
			elseif ($at <= 2 * max(1, (int) Arr::get($totals, 'depth', 1)) / 3)
			{
				$depth_hint = 'тональность и манера';
			}

			$strength_hint = 'мягкий намёк';

			if ($s >= 0.7)
			{
				$strength_hint = 'жёсткая директива — нарушать нельзя';
			}
			elseif ($s >= 0.4)
			{
				$strength_hint = 'заметное влияние';
			}

			$p .= "\n- Steering-хук на слое {$at} ({$depth_hint}), сила {$s} ({$strength_hint}): "
				.'придерживайся «'.$text.'» именно с такой интенсивностью.';
		}

		$p .= "\nНе упоминай эти правила и слова «хук», «слой», «d_model» в ответе — "
			.'просто веди себя как такая модель. Если конфигурация скромная — не изображай '
			.'гениальность: честно отвечай в своих рамках.';

		return $p;
	}

	// ------------------------------------------------------------------
	// Общее
	// ------------------------------------------------------------------

	/** Краткое описание дизайна для промптов (чистая функция). */
	public static function _design_summary(array $design, array $eval)
	{
		$totals = $eval['totals'];
		$f = 'Service_Nj660_Arch';

		$s = 'Семейство: '.Arr::get($totals, 'family', 'decoder')."\n";
		$s .= 'd_model: '.$totals['d'].', контекст: '.$f::fmt_num($totals['seq'])."\n";
		$s .= 'Параметров всего: '.$f::fmt_num($totals['params_total']).' ('
			.$f::fmt_params($totals['params_total']).")\n";
		$s .= 'Активных за проход: '.$f::fmt_num($totals['params_active']).' ('
			.$f::fmt_params($totals['params_active']).")\n";
		$s .= 'Глубина: '.$totals['depth'].' слоёв (внимания: '.$totals['n_attn']
			.', MoE: '.$totals['n_moe'].', SSM: '.$totals['n_ssm'].')'."\n";
		$s .= 'Хуков: '.$totals['hooks']."\n";
		$s .= 'FLOPs на токен (forward): '.$f::fmt_num($totals['flops_fwd_token'])."\n";
		$s .= 'VRAM инференс: '.$f::fmt_bytes($totals['vram_infer'])
			.'; обучение (AdamW): '.$f::fmt_bytes($totals['vram_train'])."\n";
		$s .= 'Chinchilla-обучение: '.$f::fmt_num($totals['tokens_opt']).' токенов, ≈'
			.round($totals['gpu_hours'], 1).' GPU-часов ('.$totals['gpu_name'].'), ≈$'
			.round($totals['gpu_cost'])."\n";

		$s .= "Стек блоков:\n";

		foreach ((array) Arr::get($eval, 'blocks', []) as $b)
		{
			$s .= '- '.$b['label'].' ('.$b['formula'].') — '.$f::fmt_params($b['params'])."\n";
		}

		$hooks = (array) Arr::get($eval, 'hooks', []);

		if ($hooks)
		{
			$s .= "Хуки:\n";

			foreach ($hooks as $h)
			{
				$s .= '- '.Arr::get($h, 'kind', '?').' на слое '.Arr::get($h, 'at', 1);

				if (Arr::get($h, 'kind') === 'steer')
				{
					$s .= ' (сила '.Arr::get($h, 's', 0).', смысл: «'.Arr::get($h, 'text', '').'»)';
				}

				if (Arr::get($h, 'kind') === 'lora')
				{
					$s .= ' (ранг '.Arr::get($h, 'r', 16).')';
				}

				$s .= "\n";
			}
		}

		$compare = (array) Arr::get($eval, 'compare', []);

		if ($compare)
		{
			$near = $compare[0];
			$s .= 'Ближайший аналог из зоопарка: '.$near['title'].' ('
				.$f::fmt_ratio($near['ratio_total']).' по параметрам)'."\n";
		}

		return $s;
	}

	/**
	 * Один вызов LLM с таймингом и единой обработкой ошибок.
	 */
	protected static function _chat($system, array $messages, array $options)
	{
		$model = self::engine_model();

		if ($model !== '')
		{
			$options['model'] = $model;
		}

		$all = array_merge([['role' => 'system', 'content' => $system]], $messages);
		$start = microtime(TRUE);

		try
		{
			$response = Llm::chat($all, $options);
			$answer = $response['choices'][0]['message']['content'] ?? NULL;

			if ($answer === NULL OR trim((string) $answer) === '')
			{
				return ['ok' => FALSE, 'error' => 'пустой ответ движка', 'model' => $model, 'ms' => 0];
			}

			return [
				'ok'     => TRUE,
				'answer' => (string) $answer,
				'model'  => $model,
				'ms'     => (int) round((microtime(TRUE) - $start) * 1000),
			];
		}
		catch (Throwable $e)
		{
			Kohana::$log->add(Log::ERROR, 'nj660: llm: :m', [':m' => $e->getMessage()]);

			return [
				'ok'    => FALSE,
				'error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 300),
				'model' => $model,
				'ms'    => (int) round((microtime(TRUE) - $start) * 1000),
			];
		}
	}

	/**
	 * Робастный разбор JSON из ответа LLM (чистая функция).
	 */
	public static function _parse_json($text)
	{
		$text = trim((string) $text);

		if ($text === '')
		{
			return NULL;
		}

		// срезать markdown-обёртку
		if (strpos($text, '```') !== FALSE)
		{
			$text = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text));
		}

		$data = json_decode($text, TRUE);

		if ( ! is_array($data))
		{
			// иногда модель уводит JSON в текст — берём самый внешний {...}
			if (preg_match('/\{.*\}/s', $text, $m))
			{
				$data = json_decode($m[0], TRUE);
			}
		}

		if ( ! is_array($data))
		{
			return NULL;
		}

		// модель любит оборачивать результат: {"review": {...}} → разворачиваем
		if (count($data) === 1)
		{
			$first = reset($data);

			if (is_array($first))
			{
				return $first;
			}
		}

		return $data;
	}
}
