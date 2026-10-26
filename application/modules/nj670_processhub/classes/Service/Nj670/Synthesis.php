<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Синтез схемы: модель → граф, а при её отсутствии — правила.
 *
 * Основную работу делает LLM в воркере (канал nj670.synthesize), но платформа
 * не должна зависеть от наличия ключа: если модель недоступна или вернула
 * мусор, текст регламента всё равно превращается в черновик шагов. Черновик
 * честно помечается source=rules — человек видит, что это не «магия ИИ».
 */
class Service_Nj670_Synthesis extends Service_Nj670_Repo
{
	/**
	 * Приведение ответа модели к графу: алиасы полей, лимиты, дорожки,
	 * раскладка. Всё остальное — забота Graph::normalize.
	 */
	public static function from_model($raw, array $ontology, $mode = 'plan')
	{
		if (is_string($raw))
		{
			$raw = self::json_decode($raw);
		}

		if ( ! is_array($raw))
		{
			return ['ok' => FALSE, 'error' => 'Модель вернула не JSON-объект.'];
		}

		$graph = Arr::get($raw, 'graph', $raw);

		if ( ! is_array($graph) OR empty($graph['nodes']))
		{
			return ['ok' => FALSE, 'error' => 'В ответе модели нет шагов.'];
		}

		$normalized = Service_Nj670_Graph::normalize($graph, $ontology, [
			'reflow'    => TRUE,
			'mode'      => $mode,
			'max_nodes' => (int) self::limits('max_nodes', 80),
			'max_edges' => (int) self::limits('max_edges', 160),
		]);

		$normalized['meta']['source'] = 'llm';
		$normalized['meta']['note'] = UTF8::substr(trim((string) Arr::get($raw, 'note', Arr::get($raw, 'summary', ''))), 0, 500);

		return [
			'ok'    => TRUE,
			'graph' => $normalized,
			'model_note' => (string) Arr::path($normalized, 'meta.note', ''),
		];
	}

	/**
	 * Правиловый разбор текста: строки/пункты регламента → шаги.
	 */
	public static function from_text($text, array $ontology, array $opts = [])
	{
		$text = trim((string) $text);
		$max = (int) self::limits('max_text', 20000);
		$text = UTF8::substr($text, 0, $max);

		if (UTF8::strlen($text) < 20)
		{
			return ['ok' => FALSE, 'error' => 'Текст слишком короткий: опишите хотя бы 3–4 шага.'];
		}

		$lines = preg_split('~\r\n|\r|\n~u', $text);
		$steps = [];

		foreach ($lines as $line)
		{
			$line = trim(preg_replace('~^[\s\-\*\•\d\.\)]+~u', '', (string) $line));

			if ($line === '' OR UTF8::strlen($line) < 8)
			{
				continue;
			}

			// Служебные строки документа не превращаем в шаги.
			if (preg_match('~^(утверждаю|согласовано|регламент|приложение|версия|дата|подпись|стр\.)~ui', $line))
			{
				continue;
			}

			$steps[] = $line;

			if (count($steps) >= 40)
			{
				break;
			}
		}

		if (count($steps) < 2)
		{
			// Сплошной абзац: режем по точкам, но только на длинных фразах.
			$steps = [];

			foreach (preg_split('~(?<=[.!?])\s+~u', $text) as $sentence)
			{
				$sentence = trim($sentence);

				if (UTF8::strlen($sentence) >= 25)
				{
					$steps[] = $sentence;
				}

				if (count($steps) >= 30)
				{
					break;
				}
			}
		}

		if (count($steps) < 2)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось выделить шаги. Опишите процесс списком: одно действие — одна строка.'];
		}

		$graph = [
			'lanes' => [],
			'nodes' => [
				['id' => 'start', 'step_id' => 'start', 'type' => 'start', 'title' => 'Начало процесса'],
			],
			'edges' => [],
			'meta'  => [
				'title'  => UTF8::substr(trim((string) Arr::get($opts, 'title', 'Схема из текста')), 0, 255),
				'source' => 'rules',
				'mode'   => (string) Arr::get($opts, 'mode', 'plan') === 'fact' ? 'fact' : 'plan',
			],
		];

		$prev = 'start';
		$branch = NULL;
		$index = 0;

		foreach ($steps as $line)
		{
			$index++;
			$id = 's'.$index;

			$conditional = preg_match('~^(если|в случае|при условии|когда|иначе)~ui', $line);
			$condition = '';

			if ($conditional)
			{
				$condition = UTF8::substr($line, 0, 200);
			}

			$role = self::_detect($line, $ontology, 'role');
			$system = self::_detect($line, $ontology, 'system');
			$artifacts = self::_artifacts($line, $ontology);

			$type = 'user_task';

			if (preg_match('~автоматическ|система |api|робот|скрипт~ui', $line))
			{
				$type = 'service_task';
			}
			elseif (preg_match('~вручную|бумажн|на телефоне|в мессенджер~ui', $line))
			{
				$type = 'manual_task';
			}

			list($duration, $wait) = self::_durations($line);

			$title = self::_title($line);
			$lane = '';
			$role_code = $role ? (string) $role['code'] : '';

			if ($conditional)
			{
				$gid = $id.'_xor';
				$graph['nodes'][] = [
					'id' => $gid,
					'step_id' => $gid,
					'type' => 'gateway_xor',
					'title' => 'Условие: '.UTF8::substr($title, 0, 60),
					'lane' => $lane,
				];
				$graph['edges'][] = ['from' => $prev, 'to' => $gid, 'type' => 'sequence'];
				$branch = $gid;
				$prev = $gid;
			}

			$graph['nodes'][] = [
				'id'    => $id,
				'step_id' => $id,
				'type'  => $type,
				'title' => $title,
				'lane'  => $lane,
				'role_code' => $role_code,
				'system_code' => $system ? (string) $system['code'] : '',
				'input_code' => isset($artifacts[0]) ? $artifacts[0] : '',
				'output_code' => count($artifacts) > 1 ? $artifacts[count($artifacts) - 1] : '',
				'duration_minutes' => $duration,
				'wait_time_minutes' => $wait,
				'properties' => $conditional ? ['condition' => $condition] : [],
			];

			$graph['edges'][] = [
				'from' => $prev,
				'to'   => $id,
				'type' => $conditional ? 'conditional' : 'sequence',
				'condition' => $conditional ? UTF8::substr($line, 0, 200) : '',
			];

			$prev = $id;
		}

		$graph['nodes'][] = ['id' => 'end', 'step_id' => 'end', 'type' => 'end', 'title' => 'Конец процесса'];
		$graph['edges'][] = ['from' => $prev, 'to' => 'end', 'type' => 'sequence'];

		$normalized = Service_Nj670_Graph::normalize($graph, $ontology, [
			'reflow' => TRUE,
			'mode'   => (string) Arr::get($opts, 'mode', 'plan'),
		]);

		$normalized['meta']['source'] = 'rules';
		$normalized['meta']['note'] = 'Черновик по правилам: шаги выделены из текста, роли и системы сопоставлены со справочником. Проверьте перед публикацией.';

		return [
			'ok'    => TRUE,
			'graph' => $normalized,
			'steps' => count($steps),
		];
	}

	/**
	 * ИИ-дополнение выбранного узла: новый/изменённый фрагмент накладывается
	 * на текущую схему, а координаты вручную расставленных узлов остаются.
	 */
	public static function merge_copilot(array $current, array $patch, $node_id = '', array $ontology = [])
	{
		$before = [];

		foreach ((array) Arr::get($current, 'nodes', []) as $node)
		{
			$before[$node['id']] = $node;
		}

		$out = [
			'lanes' => array_values(array_merge(
				(array) Arr::get($current, 'lanes', []),
				(array) Arr::get($patch, 'lanes', [])
			)),
			'nodes' => [],
			'edges' => (array) Arr::get($current, 'edges', []),
			'meta'  => array_merge((array) Arr::get($current, 'meta', []), (array) Arr::get($patch, 'meta', [])),
		];

		// Опорная точка для новых узлов: выбранный или самый правый существующий.
		$anchor = NULL;

		if ($node_id !== '' AND isset($before[$node_id]))
		{
			$anchor = $before[$node_id];
		}
		else
		{
			foreach ($before as $node)
			{
				if ($anchor === NULL OR (int) $node['x'] > (int) $anchor['x'])
				{
					$anchor = $node;
				}
			}
		}

		$seen = [];
		$added = 0;

		foreach ((array) Arr::get($patch, 'nodes', []) as $node)
		{
			$id = (string) Arr::get($node, 'id', Arr::get($node, 'step_id', ''));

			if ($id === '' OR isset($seen[$id]))
			{
				continue;
			}

			$seen[$id] = TRUE;

			// Если узел уже был — правим только содержательные поля, координаты
			// и размер остаются человеческими.
			if (isset($before[$id]))
			{
				$merged = array_merge($before[$id], array_filter([
					'title'   => Arr::get($node, 'title'),
					'type'    => Arr::get($node, 'type'),
					'role_code' => Arr::get($node, 'role_code'),
					'system_code' => Arr::get($node, 'system_code'),
					'input_code' => Arr::get($node, 'input_code'),
					'output_code' => Arr::get($node, 'output_code'),
					'duration_minutes' => Arr::get($node, 'duration_minutes'),
					'wait_time_minutes' => Arr::get($node, 'wait_time_minutes'),
					'properties' => Arr::get($node, 'properties'),
				], function ($v) { return $v !== NULL; }));

				$out['nodes'][] = $merged;
			}
			else
			{
				// Новому узлу сразу даём координаты: иначе normalize увидит
				// «не у всех есть позиция» и перерисует весь холст, потеряв
				// ручную раскладку человека.
				if ($anchor)
				{
					$node['x'] = (int) $anchor['x'] + 268 * (1 + $added);
					$node['y'] = (int) $anchor['y'] + (($added % 2) === 1 ? 30 : 0);
					$node['lane'] = (string) Arr::get($anchor, 'lane', Arr::get($node, 'lane', ''));
				}

				$added++;
				$out['nodes'][] = $node;
			}
		}

		// Узлы, которых не было в патче, сохраняем как есть.
		foreach ($before as $id => $node)
		{
			if ( ! isset($seen[$id]))
			{
				$out['nodes'][] = $node;
			}
		}

		$edge_keys = [];

		foreach ((array) Arr::get($current, 'edges', []) as $edge)
		{
			$edge_keys[$edge['from'].'>'.$edge['to']] = TRUE;
		}

		foreach ((array) Arr::get($patch, 'edges', []) as $edge)
		{
			$key = (string) Arr::get($edge, 'from', '').'>'.(string) Arr::get($edge, 'to', '');

			if ($key === '>' OR isset($edge_keys[$key]))
			{
				continue;
			}

			$edge_keys[$key] = TRUE;
			$out['edges'][] = $edge;
		}

		$ontology = array_merge(
			$ontology,
			(array) Arr::get($patch, 'ontology', [])
		);

		return Service_Nj670_Graph::normalize($out, $ontology, [
			'reflow' => FALSE,
			'mode'   => (string) Arr::get($out['meta'], 'mode', 'plan'),
		]);
	}

	// ------------------------------------------------------------- эвристики

	protected static function _title($line)
	{
		$line = preg_replace('~\([^)]*\)~u', '', (string) $line);
		$line = trim(preg_replace('~\s+~u', ' ', (string) $line));

		// «Инициатор: создаёт заявку» → «Создаёт заявку».
		if (preg_match('~^[А-ЯЁа-яё ]{3,40}:\s*(.+)$~u', $line, $m))
		{
			$line = trim($m[1]);
		}

		$line = rtrim($line, '.;');

		return UTF8::substr($line !== '' ? $line : 'Шаг процесса', 0, 140);
	}

	protected static function _detect($line, array $ontology, $type)
	{
		$list = (array) Arr::path($ontology, 'list.'.$type, []);
		$lower = mb_strtolower($line);
		$best = NULL;
		$best_len = 0;

		foreach ($list as $row)
		{
			$name = mb_strtolower((string) $row['name']);

			// Два символа — законное имя системы («1С», «ЧЗ»); порог в 3
			// оставлял такие системы без сопоставления со справочником.
			if ($name === '' OR UTF8::strlen($name) < 2)
			{
				continue;
			}

			if (strpos($lower, $name) !== FALSE AND UTF8::strlen($name) > $best_len)
			{
				$best = $row;
				$best_len = UTF8::strlen($name);
			}
		}

		return $best;
	}

	protected static function _artifacts($line, array $ontology)
	{
		$found = [];
		$lower = mb_strtolower($line);

		foreach ((array) Arr::path($ontology, 'list.artifact', []) as $row)
		{
			$name = trim(mb_strtolower((string) $row['name']));

			if ($name === '')
			{
				continue;
			}

			$pos = mb_strpos($lower, $name);

			// В регламенте артефакт почти всегда стоит в косвенном падеже
			// («создаёт служебную записку»), а в справочнике — в именительном.
			// Сравниваем основы слов: служебн + записк найдутся в обоих.
			if ($pos === FALSE)
			{
				$pos = self::_stem_position($lower, $name);
			}

			if ($pos !== FALSE)
			{
				$found[(int) $pos] = (string) $row['code'];
			}
		}

		ksort($found);

		return array_values($found);
	}

	/**
	 * Позиция первого слова артефакта по основе (без последних 2–3 букв).
	 * Возвращает FALSE, если основа хотя бы одного слова не встретилась.
	 */
	protected static function _stem_position($haystack, $name)
	{
		$first = NULL;

		foreach (preg_split('~\s+~u', $name, -1, PREG_SPLIT_NO_EMPTY) as $word)
		{
			$len = UTF8::strlen($word);

			if ($len < 4)
			{
				$stem = $word;
			}
			else
			{
				$stem = UTF8::substr($word, 0, $len - ($len >= 7 ? 3 : 2));
			}

			$pos = mb_strpos($haystack, $stem);

			if ($pos === FALSE)
			{
				return FALSE;
			}

			$first = $first === NULL ? $pos : min($first, $pos);
		}

		return $first === NULL ? FALSE : $first;
	}

	protected static function _durations($line)
	{
		$duration = 0;
		$wait = 0;
		$lower = mb_strtolower($line);

		if (preg_match('~(\d+(?:[.,]\d+)?)\s*(раб\.?\s*)?(мин|час|ч\.?|дн|день|дня|дней|недел)~ui', $line, $m))
		{
			$value = (float) str_replace(',', '.', $m[1]);
			$unit = mb_strtolower($m[3]);
			$mult = 1;

			if (in_array($unit, ['час', 'ч', 'ч.'], TRUE))
			{
				$mult = 60;
			}
			elseif (in_array($unit, ['дн', 'день', 'дня', 'дней'], TRUE))
			{
				$mult = 480;
			}
			elseif ($unit === 'недел')
			{
				$mult = 2400;
			}

			$duration = (int) round($value * $mult);

			if (strpos($lower, 'ожидан') !== FALSE OR strpos($lower, 'согласован') !== FALSE)
			{
				$wait = $duration;
				$duration = (int) round($duration * 0.15);
			}
		}
		elseif (preg_match('~(\d+)\s*(мин|час|ч\.?)~ui', $line, $m))
		{
			$unit = mb_strtolower($m[2]);
			$duration = (int) $m[1] * (in_array($unit, ['час', 'ч', 'ч.'], TRUE) ? 60 : 1);
		}

		return [max(0, min(100000, $duration)), max(0, min(100000, $wait))];
	}
}
