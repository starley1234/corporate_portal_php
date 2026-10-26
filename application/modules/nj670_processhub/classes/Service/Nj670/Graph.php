<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ProcessHub: граф процесса как данные.
 *
 * Здесь нет HTTP и SQL — только топология. Один и тот же jsonb читают холст
 * (AntV X6), серверные SVG-превью, отчёт и python-воркер. Поэтому:
 *   · нормализация строгая (белый список типов, лимиты, ссылки рёбер);
 *   · координаты можно пересчитать (layout) или сохранить как на холсте;
 *   · метрики считаются по онтологии, а не по цифрам, вбитым в шаблон.
 *
 * Формат графа:
 *   {lanes:[{id,title,role_code}], nodes:[{id,step_id,type,title,lane,...}],
 *    edges:[{id,from,to,type,condition,weight}], meta:{...}}
 */
class Service_Nj670_Graph extends Service_Nj670_Repo
{
	const LANE_HEIGHT = 178;
	const LANE_TOP    = 56;
	const X0          = 54;
	const X_GAP       = 268;

	/** Соответствия «как мог назвать модель» → внутренний тип. */
	protected static $_type_aliases = [
		'startevent'      => 'start',
		'start_event'     => 'start',
		'beginevent'      => 'start',
		'endevent'        => 'end',
		'end_event'       => 'end',
		'task'            => 'user_task',
		'usertask'        => 'user_task',
		'user'            => 'user_task',
		'business_rule'   => 'service_task',
		'servicetask'     => 'service_task',
		'service'         => 'service_task',
		'automatic_task'  => 'service_task',
		'script_task'     => 'service_task',
		'manualtask'      => 'manual_task',
		'manual'          => 'manual_task',
		'xor'             => 'gateway_xor',
		'exclusive'       => 'gateway_xor',
		'exclusive_gateway' => 'gateway_xor',
		'and'             => 'gateway_and',
		'parallel'        => 'gateway_and',
		'parallel_gateway'=> 'gateway_and',
		'inclusive'       => 'gateway_xor',
		'timer_event'     => 'timer',
		'intermediate_timer' => 'timer',
		'wait'            => 'timer',
		'sub_process'     => 'subprocess',
		'call_activity'   => 'subprocess',
	];

	public static function empty_graph($title = '')
	{
		return [
			'lanes' => [],
			'nodes' => [],
			'edges' => [],
			'meta'  => [
				'title'  => (string) $title,
				'width'  => 1180,
				'height' => 520,
				'mode'   => 'plan',
			],
		];
	}

	public static function blank_node($type = 'user_task')
	{
		$type = self::type($type);

		return [
			'id'                => 'n'.mt_rand(100000, 999999),
			'step_id'           => 'step_'.mt_rand(100000, 999999),
			'type'              => $type,
			'title'             => 'Новый шаг',
			'lane'              => '',
			'role_code'         => '',
			'system_code'       => '',
			'input_code'        => '',
			'output_code'       => '',
			'duration_minutes'  => 0,
			'wait_time_minutes' => 0,
			'x'                 => 120,
			'y'                 => 160,
			'properties'        => [],
		];
	}

	public static function type($type)
	{
		$type = strtolower(trim((string) $type));
		$type = str_replace([' ', '-'], '_', $type);

		if ( ! isset(self::$_type_aliases[$type]) AND ! isset(self::node_types()[$type]))
		{
			return 'user_task';
		}

		return isset(self::$_type_aliases[$type]) ? self::$_type_aliases[$type] : $type;
	}

	public static function node_type_label($type)
	{
		$types = self::node_types();

		return (string) Arr::get($types, $type, $type);
	}

	public static function node_size($type)
	{
		$type = self::type($type);

		if ($type === 'start' OR $type === 'end')
		{
			return [86, 60];
		}

		if ($type === 'gateway_xor' OR $type === 'gateway_and')
		{
			return [92, 92];
		}

		if ($type === 'timer')
		{
			return [190, 78];
		}

		if ($type === 'subprocess')
		{
			return [200, 92];
		}

		return [196, 86];
	}

	// ------------------------------------------------------------- нормализация

	/**
	 * @param  array $input    сырой граф от модели/холста/БД
	 * @param  array $ontology  индекс справочников (Service_Nj670_Ontology::index)
	 * @param  array $opts     reflow, mode, max_nodes, max_edges
	 * @return array           чистый граф
	 */
	public static function normalize(array $input, array $ontology = [], array $opts = [])
	{
		$max_nodes = (int) Arr::get($opts, 'max_nodes', self::limits('max_nodes', 80));
		$max_edges = (int) Arr::get($opts, 'max_edges', self::limits('max_edges', 160));
		$reflow    = (bool) Arr::get($opts, 'reflow', FALSE);

		$nodes_in = Arr::get($input, 'nodes', []);
		$edges_in = Arr::get($input, 'edges', $input['transitions'] ?? []);
		$lanes_in = Arr::get($input, 'lanes', []);

		if ( ! is_array($nodes_in))
		{
			$nodes_in = [];
		}

		$nodes = [];
		$used_ids = [];
		$used_steps = [];

		foreach ($nodes_in as $row)
		{
			if ( ! is_array($row) OR count($nodes) >= $max_nodes)
			{
				continue;
			}

			$node = self::_node($row, $ontology);

			if ($node === NULL)
			{
				continue;
			}

			// id и step_id обязаны быть уникальны; конфликт — не молчаливая
			// потеря шага, а предсказуемый суффикс.
			$base_id = $node['id'];
			$k = 2;

			while (isset($used_ids[$node['id']]))
			{
				$node['id'] = $base_id.'_'.$k;
				$k++;
			}

			$used_ids[$node['id']] = TRUE;

			$base_step = $node['step_id'];
			$k = 2;

			while (isset($used_steps[$node['step_id']]))
			{
				$node['step_id'] = $base_step.'_'.$k;
				$k++;
			}

			$used_steps[$node['step_id']] = TRUE;

			$nodes[] = $node;
		}

		$nodes_by_id = [];
		$nodes_by_step = [];

		foreach ($nodes as $node)
		{
			$nodes_by_id[$node['id']] = $node;
			$nodes_by_step[$node['step_id']] = $node;
		}

		// Дорожки: из входа или по ролям. Пустой lane у шага допустим только
		// когда у процесса нет ролей вовсе.
		$lanes = self::_lanes($lanes_in, $nodes, $ontology);
		$lane_ids = [];

		foreach ($lanes as $lane)
		{
			$lane_ids[$lane['id']] = TRUE;
		}

		foreach ($nodes as &$node)
		{
			if ($node['lane'] === '' OR ! isset($lane_ids[$node['lane']]))
			{
				$node['lane'] = self::_lane_for_role($node['role_code'], $lanes);
			}
		}
		unset($node);

		// Рёбра: только между существующими шагами, без дублей и self-loop.
		$edges = [];
		$edge_keys = [];

		foreach ($edges_in as $row)
		{
			if ( ! is_array($row) OR count($edges) >= $max_edges)
			{
				continue;
			}

			$from = self::_id_of(Arr::get($row, 'from', Arr::get($row, 'source', '')));
			$to   = self::_id_of(Arr::get($row, 'to', Arr::get($row, 'target', '')));

			if ($from === '' OR $to === '' OR $from === $to)
			{
				continue;
			}

			if ( ! isset($nodes_by_id[$from]) OR ! isset($nodes_by_id[$to]))
			{
				continue;
			}

			$key = $from.'>'.$to;

			if (isset($edge_keys[$key]))
			{
				continue;
			}

			$edge_keys[$key] = TRUE;

			$type = strtolower(trim((string) Arr::get($row, 'type', Arr::get($row, 'transition_type', 'sequence'))));

			if ( ! in_array($type, ['sequence', 'conditional', 'parallel', 'loop'], TRUE))
			{
				$type = 'sequence';
			}

			$properties = Arr::get($row, 'properties', []);
			$properties = is_array($properties) ? self::_clean_props($properties) : [];

			$edges[] = [
				'id'       => UTF8::substr(trim((string) Arr::get($row, 'id', 'e'.count($edges))), 0, 60),
				'from'     => $from,
				'to'       => $to,
				'type'     => $type,
				'condition'=> UTF8::substr(trim((string) Arr::get($row, 'condition', Arr::get($row, 'label', ''))), 0, 300),
				'weight'   => max(0, round((float) Arr::get($row, 'weight', 1), 2)),
				'avg_minutes' => max(0, (int) Arr::get($row, 'avg_minutes', 0)),
				'properties'  => $properties,
			];
		}

		// Координаты. При сохранении холста они священны; при синтезе/импорте
		// считаем раскладку сами — иначе схема приезжает в центр одной точки.
		$has_coords = TRUE;

		foreach ($nodes as $node)
		{
			if ( ! isset($node['_x_set']) OR ! $node['_x_set'])
			{
				$has_coords = FALSE;
				break;
			}
		}

		if ( ! $has_coords OR $reflow)
		{
			$graph = self::layout([
				'lanes' => $lanes,
				'nodes' => $nodes,
				'edges' => $edges,
			]);

			$lanes = $graph['lanes'];
			$nodes = $graph['nodes'];
		}

		foreach ($nodes as &$node)
		{
			unset($node['_x_set'], $node['_y_set']);
		}
		unset($node);

		$meta = Arr::get($input, 'meta', []);
		$meta = is_array($meta) ? $meta : [];

		$width = max(980, (int) Arr::get($meta, 'width', 0));
		$height = max(420, (int) Arr::get($meta, 'height', 0));

		foreach ($nodes as $node)
		{
			$width = max($width, (int) $node['x'] + 260);
			$height = max($height, (int) $node['y'] + 140);
		}

		$mode = (string) Arr::get($opts, 'mode', Arr::get($meta, 'mode', 'plan'));

		return [
			'lanes' => $lanes,
			'nodes' => $nodes,
			'edges' => $edges,
			'meta'  => [
				'title'          => UTF8::substr(trim((string) Arr::get($meta, 'title', '')), 0, 255),
				'width'          => $width,
				'height'         => $height,
				'mode'           => $mode === 'fact' ? 'fact' : 'plan',
				'source'         => UTF8::substr(trim((string) Arr::get($meta, 'source', '')), 0, 60),
				'elapsed_minutes'=> max(0, (int) Arr::get($meta, 'elapsed_minutes', 0)),
				'note'           => UTF8::substr(trim((string) Arr::get($meta, 'note', '')), 0, 500),
			],
		];
	}

	protected static function _node(array $row, array $ontology)
	{
		$id = self::_id_of(Arr::get($row, 'id', Arr::get($row, 'step_id', '')));

		if ($id === '')
		{
			return NULL;
		}

		$type = self::type(Arr::get($row, 'type', Arr::get($row, 'node_type', 'user_task')));
		$title = trim((string) Arr::get($row, 'title', Arr::get($row, 'name', '')));

		if ($title === '')
		{
			$title = self::node_type_label($type);
		}

		$step = self::_id_of(Arr::get($row, 'step_id', $id));

		$role    = self::_entity_ref(Arr::get($row, 'role_code', Arr::get($row, 'role', '')), 'role', $ontology);
		$system  = self::_entity_ref(Arr::get($row, 'system_code', Arr::get($row, 'system', '')), 'system', $ontology);
		$input   = self::_entity_ref(Arr::get($row, 'input_code', Arr::get($row, 'input_artifact', Arr::get($row, 'input', ''))), 'artifact', $ontology);
		$output  = self::_entity_ref(Arr::get($row, 'output_code', Arr::get($row, 'output_artifact', Arr::get($row, 'output', ''))), 'artifact', $ontology);

		$properties = Arr::get($row, 'properties', []);
		$properties = is_array($properties) ? self::_clean_props($properties) : [];

		// Свободные подписи (роль не найдена в справочнике) сохраняем рядом,
		// чтобы холст не терял то, что написал человек.
		if ($role['label'] !== '' AND ! isset($properties['role_label']))
		{
			$properties['role_label'] = $role['label'];
		}
		if ($system['label'] !== '' AND ! isset($properties['system_label']))
		{
			$properties['system_label'] = $system['label'];
		}

		$x = Arr::get($row, 'x', Arr::get($row, 'position_x', NULL));
		$y = Arr::get($row, 'y', Arr::get($row, 'position_y', NULL));
		list($w, $h) = self::node_size($type);

		return [
			'id'                => $id,
			'step_id'           => $step,
			'type'              => $type,
			'title'             => UTF8::substr($title, 0, 255),
			'lane'              => self::_id_of(Arr::get($row, 'lane', Arr::get($row, 'lane_id', ''))),
			'role_code'         => $role['code'],
			'system_code'       => $system['code'],
			'input_code'        => $input['code'],
			'output_code'       => $output['code'],
			'duration_minutes'  => max(0, min(100000, (int) Arr::get($row, 'duration_minutes', Arr::get($row, 'duration', 0)))),
			'wait_time_minutes' => max(0, min(1000000, (int) Arr::get($row, 'wait_time_minutes', Arr::get($row, 'wait', 0)))),
			'x'                 => $x === NULL ? 0 : max(0, (int) $x),
			'y'                 => $y === NULL ? 0 : max(0, (int) $y),
			'w'                 => $w,
			'h'                 => $h,
			'properties'        => $properties,
			'_x_set'            => ($x !== NULL AND (int) $x > 0),
			'_y_set'            => ($y !== NULL AND (int) $y > 0),
		];
	}

	protected static function _id_of($value)
	{
		$value = trim((string) $value);

		if ($value === '')
		{
			return '';
		}

		$value = preg_replace('~[^a-zA-Z0-9_\-.]+~', '_', $value);

		return UTF8::substr(trim($value, '_-.'), 0, 60);
	}

	protected static function _entity_ref($value, $type, array $ontology)
	{
		$value = trim((string) $value);

		if ($value === '')
		{
			return ['code' => '', 'label' => ''];
		}

		$by_code = (array) Arr::path($ontology, 'by_code.'.$type, []);
		$by_name = (array) Arr::path($ontology, 'by_name.'.$type, []);
		$lower = mb_strtolower($value);

		if (isset($by_code[$value]))
		{
			return ['code' => $value, 'label' => ''];
		}

		if (isset($by_code[$lower]))
		{
			return ['code' => $lower, 'label' => ''];
		}

		if (isset($by_name[$lower]))
		{
			return ['code' => $by_name[$lower], 'label' => ''];
		}

		$slug = self::slug_code($value, '');
		$slug = str_replace('-', '_', $slug);

		if (isset($by_code[$slug]))
		{
			return ['code' => $slug, 'label' => ''];
		}

		return ['code' => '', 'label' => UTF8::substr($value, 0, 120)];
	}

	protected static function _clean_props(array $props)
	{
		$out = [];
		$allowed = [
			'shadow', 'problem', 'control', 'severity', 'condition', 'count',
			'role_label', 'system_label', 'source', 'evidence', 'description',
			'variant', 'rework', 'sla_minutes', 'criticality', 'metric',
			// Вызов другого процесса: подпроцесс-ссылка и координаты BPMN.
			'process_code', 'process_title', 'bpmn', 'bpmn_x', 'bpmn_y',
			// Аудит-оверлей на шаге: комментарий ревьюера и его тип.
			'comment', 'overlay', 'overlay_by', 'overlay_at',
		];

		foreach ($allowed as $key)
		{
			if ( ! array_key_exists($key, $props))
			{
				continue;
			}

			$value = $props[$key];

			if (is_bool($value) OR is_int($value) OR is_float($value))
			{
				$out[$key] = $value;
			}
			elseif (is_string($value))
			{
				$out[$key] = UTF8::substr(trim($value), 0, 500);
			}
		}

		// Оверлей — это метка аудита, а не свободный текст: неизвестное
		// значение на холсте не нарисуешь, поэтому отбрасываем его сразу.
		if (isset($out['overlay']) AND ! in_array($out['overlay'], self::overlays(), TRUE))
		{
			unset($out['overlay']);
		}

		return $out;
	}

	/**
	 * Типы аудит-оверлеев на шаге. Используются и холстом, и инспектором.
	 */
	public static function overlays()
	{
		return ['risk', 'control', 'question', 'idea'];
	}

	protected static function _lanes(array $lanes_in, array $nodes, array $ontology)
	{
		$lanes = [];
		$used = [];

		foreach ($lanes_in as $row)
		{
			if ( ! is_array($row))
			{
				continue;
			}

			$id = self::_id_of(Arr::get($row, 'id', Arr::get($row, 'role_code', '')));

			if ($id === '' OR isset($used[$id]))
			{
				continue;
			}

			$role = self::_entity_ref(Arr::get($row, 'role_code', Arr::get($row, 'role', '')), 'role', $ontology);
			$title = trim((string) Arr::get($row, 'title', ''));

			if ($title === '')
			{
				$title = self::_role_name($role['code'], $ontology, Arr::get($row, 'role_label', ''));
			}

			$used[$id] = TRUE;
			$lanes[] = [
				'id'        => $id,
				'title'     => UTF8::substr($title, 0, 160),
				'role_code' => $role['code'],
			];
		}

		if ( ! $lanes)
		{
			$seen = [];

			foreach ($nodes as $node)
			{
				$role = $node['role_code'];
				$key = $role !== '' ? $role : 'participants';

				if (isset($seen[$key]))
				{
					continue;
				}

				$seen[$key] = TRUE;
				$lanes[] = [
					'id'        => $key,
					'title'     => self::_role_name($role, $ontology,
						(string) Arr::get($node['properties'], 'role_label', '')),
					'role_code' => $role,
				];
			}
		}

		if ( ! $lanes)
		{
			$lanes[] = ['id' => 'participants', 'title' => 'Участники процесса', 'role_code' => ''];
		}

		return $lanes;
	}

	protected static function _lane_for_role($role_code, array $lanes)
	{
		foreach ($lanes as $lane)
		{
			if ($role_code !== '' AND $lane['role_code'] === $role_code)
			{
				return $lane['id'];
			}
		}

		return $lanes[0]['id'];
	}

	protected static function _role_name($role_code, array $ontology, $fallback = '')
	{
		if ($role_code !== '')
		{
			$by_code = (array) Arr::path($ontology, 'by_code.role', []);

			if (isset($by_code[$role_code]['name']))
			{
				return (string) $by_code[$role_code]['name'];
			}
		}

		if ($fallback !== '')
		{
			return $fallback;
		}

		return $role_code !== '' ? $role_code : 'Участники процесса';
	}

	// ------------------------------------------------------------- раскладка

	/**
	 * Раскладка «дорожки × глубина» (упрощённый Sugiyama/Dagre). Слои считаем
	 * по длиннейшему пути от начал; петли не раздувают координаты, а остаются
	 * обратными дугами. Возвращает граф с x/y/w/h и размерами полотна.
	 */
	public static function layout(array $graph)
	{
		$lanes = (array) Arr::get($graph, 'lanes', []);
		$nodes = (array) Arr::get($graph, 'nodes', []);
		$edges = (array) Arr::get($graph, 'edges', []);

		$by_id = [];

		foreach ($nodes as $i => $node)
		{
			$nodes[$i]['x'] = 0;
			$nodes[$i]['y'] = 0;
			list($nodes[$i]['w'], $nodes[$i]['h']) = self::node_size($node['type']);
			$by_id[$node['id']] = $i;
		}

		$out = [];
		$in = [];
		$adj = [];

		foreach ($nodes as $node)
		{
			$out[$node['id']] = [];
			$in[$node['id']] = 0;
			$adj[$node['id']] = [];
		}

		foreach ($edges as $edge)
		{
			$from = $edge['from'];
			$to = $edge['to'];

			if ( ! isset($adj[$from], $adj[$to]))
			{
				continue;
			}

			$out[$from][] = $to;
			$adj[$from][] = $to;
			$in[$to] = (int) $in[$to] + 1;
		}

		// глубины: Кан; оставшиеся (петля) получают глубину от известных предков
		$depth = [];
		$queue = [];

		foreach ($in as $id => $n)
		{
			if ($n === 0)
			{
				$depth[$id] = 0;
				$queue[] = $id;
			}
		}

		if ( ! $queue)
		{
			foreach ($nodes as $node)
			{
				$depth[$node['id']] = 0;
				$queue[] = $node['id'];
			}
		}

		$guard = 0;

		while ($queue AND $guard < 5000)
		{
			$guard++;
			$id = array_shift($queue);

			foreach ($adj[$id] as $to)
			{
				$depth[$to] = max((int) Arr::get($depth, $to, 0), (int) $depth[$id] + 1);

				if ($depth[$to] <= count($nodes))
				{
					$queue[] = $to;
				}
			}
		}

		foreach ($nodes as $node)
		{
			if ( ! isset($depth[$node['id']]))
			{
				$depth[$node['id']] = 0;
			}
		}

		// порядок дорожек: как заданы, иначе по первому появлению шага
		$lane_ids = [];

		foreach ($lanes as $lane)
		{
			$lane_ids[] = $lane['id'];
		}

		$lane_index = array_flip($lane_ids);
		$fallback_lane = $lane_ids ? $lane_ids[0] : 'participants';

		// группируем по (дорожка, глубина), чтобы шаги не накладывались
		$grouped = [];

		foreach ($nodes as $i => $node)
		{
			$lane = $node['lane'] !== '' ? $node['lane'] : $fallback_lane;

			if ( ! isset($lane_index[$lane]))
			{
				$lane = $fallback_lane;
				$nodes[$i]['lane'] = $lane;
			}

			$grouped[$lane][(int) $depth[$node['id']]][] = $i;
		}

		$width = 0;

		foreach ($nodes as $i => $node)
		{
			if ( ! isset($lane_index[$node['lane']]))
			{
				continue;
			}

			$lane_pos = (int) $lane_index[$node['lane']];
			$d = (int) $depth[$node['id']];
			$same = $grouped[$node['lane']][$d];
			$slot = array_search($i, $same, TRUE);
			$slot = $slot === FALSE ? 0 : (int) $slot;

			$x = self::X0 + $d * self::X_GAP;
			$lane_y = self::LANE_TOP + $lane_pos * self::LANE_HEIGHT;
			$center_y = $lane_y + 40 + (self::LANE_HEIGHT - 40) / 2 + $slot * 22;
			$y = (int) round($center_y - $node['h'] / 2);

			$nodes[$i]['x'] = $x;
			$nodes[$i]['y'] = max(self::LANE_TOP + 32, $y);
			$width = max($width, $x + $node['w'] + 100);
		}

		$height = self::LANE_TOP + max(1, count($lanes)) * self::LANE_HEIGHT + 30;

		return [
			'lanes' => $lanes,
			'nodes' => $nodes,
			'edges' => $edges,
			'meta'  => [
				'title'  => (string) Arr::path($graph, 'meta.title', ''),
				'width'  => max(1100, $width),
				'height' => max(460, $height),
				'mode'   => (string) Arr::path($graph, 'meta.mode', 'plan'),
			],
		];
	}

	// ------------------------------------------------------------- метрики

	/**
	 * @param array $graph    нормализованный граф
	 * @param array $ontology индекс справочников (Service_Nj670_Ontology::index)
	 * @return array          метрики версии + узлы с долями простоя
	 */
	public static function metrics(array $graph, array $ontology = [])
	{
		$nodes = (array) Arr::get($graph, 'nodes', []);
		$edges = (array) Arr::get($graph, 'edges', []);

		$work = 0;
		$wait = 0;
		$cost = 0.0;
		$wait_cost_share = (float) Arr::get(self::branch('economy'), 'wait_cost_share', 0.5);
		$default_rate = (float) Arr::get(self::branch('economy'), 'default_rate_per_hour', 1500);
		$rates = [];

		foreach ($nodes as $node)
		{
			$work += (int) Arr::get($node, 'duration_minutes', 0);
			$wait += (int) Arr::get($node, 'wait_time_minutes', 0);

			$rate = $default_rate;
			$by_code = (array) Arr::path($ontology, 'by_code.role', []);
			$role_code = (string) Arr::get($node, 'role_code', '');

			if ($role_code !== '' AND isset($by_code[$role_code]))
			{
				$attrs = (array) Arr::get($by_code[$role_code], 'attributes', []);
				$rate = (float) Arr::get($attrs, 'rate_per_hour', $default_rate);
			}
			elseif ($role_code === '')
			{
				// Автоматические шаги не стоят часов человека. Если модель
				// оставила роль пустой у ручной задачи, берём среднюю ставку.
				$rate = in_array((string) Arr::get($node, 'type', 'user_task'), ['service_task', 'start', 'end', 'gateway_xor', 'gateway_and'], TRUE)
					? 0.0 : $default_rate;
			}

			$rates[$node['id']] = $rate;
			$cost += ((float) Arr::get($node, 'duration_minutes', 0) / 60) * $rate
				+ ((float) Arr::get($node, 'wait_time_minutes', 0) / 60) * $rate * $wait_cost_share;
		}

		$elapsed = self::_critical_path($nodes, $edges);

		if ($elapsed <= 0)
		{
			$elapsed = $work + $wait;
		}

		$explicit = (int) Arr::path($graph, 'meta.elapsed_minutes', 0);

		if ($explicit > 0)
		{
			$elapsed = $explicit;
		}

		$bottleneck = NULL;
		$max_wait = 0;

		foreach ($nodes as $node)
		{
			$w = (int) Arr::get($node, 'wait_time_minutes', 0);

			if ($w > $max_wait)
			{
				$max_wait = $w;
				$bottleneck = $node;
			}
		}

		$share = $elapsed > 0 ? min(1, $max_wait / $elapsed) : 0;

		$loops = 0;
		$shadow = 0;

		foreach ($edges as $edge)
		{
			if ((string) Arr::get($edge, 'type', '') === 'loop' OR (int) Arr::path($edge, 'properties.variant', 0) > 0)
			{
				$loops++;
			}
		}

		foreach ($nodes as $node)
		{
			if ( ! empty($node['properties']['shadow']))
			{
				$shadow++;
			}
		}

		return [
			'work_minutes'     => (int) $work,
			'wait_minutes'     => (int) $wait,
			'elapsed_minutes'  => (int) round($elapsed),
			'cost_rub'         => round($cost, 2),
			'nodes_count'      => count($nodes),
			'edges_count'      => count($edges),
			'lanes_count'      => count((array) Arr::get($graph, 'lanes', [])),
			'loop_count'       => $loops,
			'shadow_count'     => $shadow,
			'bottleneck'       => $bottleneck ? [
				'step_id'  => (string) Arr::get($bottleneck, 'step_id', ''),
				'title'    => (string) Arr::get($bottleneck, 'title', ''),
				'wait'     => (int) Arr::get($bottleneck, 'wait_time_minutes', 0),
				'share'    => round($share, 4),
				'role'     => (string) Arr::get($bottleneck, 'role_code', ''),
			] : NULL,
		];
	}

	/**
	 * Длительность по критическому пути. Петли не раскручиваем: если шаг уже
	 * в стеке, возвращаем 0 — иначе demo-петля «юрист вернул договор» дала бы
	 * бесконечную длительность.
	 */
	protected static function _critical_path(array $nodes, array $edges)
	{
		$by_id = [];
		$weight = [];

		foreach ($nodes as $node)
		{
			$by_id[$node['id']] = $node;
			$weight[$node['id']] = (int) Arr::get($node, 'duration_minutes', 0)
				+ (int) Arr::get($node, 'wait_time_minutes', 0);
		}

		$adj = [];

		foreach ($nodes as $node)
		{
			$adj[$node['id']] = [];
		}

		foreach ($edges as $edge)
		{
			if (isset($adj[$edge['from']]))
			{
				$adj[$edge['from']][] = $edge['to'];
			}
		}

		$memo = [];

		$walk = function ($id, array $stack) use (&$walk, &$memo, $adj, $weight) {
			if (isset($memo[$id]))
			{
				return $memo[$id];
			}

			if (in_array($id, $stack, TRUE))
			{
				return 0;
			}

			$stack[] = $id;
			$best = 0;

			foreach ($adj[$id] as $to)
			{
				$best = max($best, $walk($to, $stack));
			}

			$value = (int) Arr::get($weight, $id, 0) + $best;
			$memo[$id] = $value;

			return $value;
		};

		$max = 0;

		foreach ($nodes as $node)
		{
			$max = max($max, $walk($node['id'], []));
		}

		return $max;
	}

	/**
	 * Узлы с долей простоя — для подсветки на «Факте».
	 */
	public static function bottlenecks(array $graph, $limit = 5)
	{
		$nodes = (array) Arr::get($graph, 'nodes', []);
		$metrics = self::metrics($graph);
		$elapsed = max(1, (int) $metrics['elapsed_minutes']);
		$rows = [];

		foreach ($nodes as $node)
		{
			$wait = (int) $node['wait_time_minutes'];

			if ($wait <= 0)
			{
				continue;
			}

			$rows[] = [
				'step_id' => $node['step_id'],
				'title'   => $node['title'],
				'wait'    => $wait,
				'share'   => round($wait / $elapsed, 4),
				'role'    => $node['role_code'],
			];
		}

		usort($rows, function ($a, $b) { return $b['wait'] <=> $a['wait']; });

		return array_slice($rows, 0, (int) $limit);
	}

	public static function severity_of_wait($share)
	{
		$share = (float) $share;

		if ($share >= 0.4)
		{
			return 'critical';
		}

		if ($share >= 0.2)
		{
			return 'high';
		}

		if ($share > 0.05)
		{
			return 'medium';
		}

		return 'low';
	}

	// ------------------------------------------------------------- SVG-превью

	/**
	 * Серверная схема для списков, аудита и отчёта. Холст на X6 — отдельно:
	 * здесь нужен предсказуемый SVG без JS (печать, письма, карточки).
	 */
	/**
	 * Рамка шага для статической SVG-миниатюры. Размеры берём из раскладки;
	 * если их нет (старые схемы) — по типу узла.
	 */
	protected static function _svg_rect(array $node)
	{
		$size = self::node_size((string) Arr::get($node, 'type', 'user_task'));

		return [
			'id' => (string) Arr::get($node, 'id', ''),
			'x'  => (int) Arr::get($node, 'x', 0),
			'y'  => (int) Arr::get($node, 'y', 0),
			'w'  => (int) Arr::get($node, 'w', $size[0]),
			'h'  => (int) Arr::get($node, 'h', $size[1]),
		];
	}

	/** Точка на грани рамки. t — доля вдоль грани, края не занимаем. */
	protected static function _svg_port(array $r, $side, $t)
	{
		$t = max(0.12, min(0.88, (float) $t));

		if ($side === 'left')
		{
			return ['x' => $r['x'], 'y' => $r['y'] + $r['h'] * $t];
		}

		if ($side === 'right')
		{
			return ['x' => $r['x'] + $r['w'], 'y' => $r['y'] + $r['h'] * $t];
		}

		if ($side === 'top')
		{
			return ['x' => $r['x'] + $r['w'] * $t, 'y' => $r['y']];
		}

		return ['x' => $r['x'] + $r['w'] * $t, 'y' => $r['y'] + $r['h']];
	}

	/**
	 * Геометрия ребра: пара граней, дуга или ортогональный обход.
	 * Возвращает d — path и lx/ly — точку подписи.
	 */
	protected static function _svg_edge(array $A, array $B, array $rects = [])
	{
		// Самопетля: дуга над шагом, вход и выход на верхней грани.
		if ($A['id'] === $B['id'])
		{
			$out = self::_svg_port($A, 'top', 0.66);
			$into = self::_svg_port($A, 'top', 0.34);
			$reach = 46;

			return [
				'd' => 'M '.$out['x'].' '.$out['y']
					.' C '.($out['x'] + 34).' '.($out['y'] - $reach).', '
					.($into['x'] - 34).' '.($into['y'] - $reach).', '
					.$into['x'].' '.$into['y'],
				'lx' => (int) (($out['x'] + $into['x']) / 2),
				'ly' => (int) ($out['y'] - $reach * 0.72),
			];
		}

		$dx = ($B['x'] + $B['w'] / 2) - ($A['x'] + $A['w'] / 2);
		$dy = ($B['y'] + $B['h'] / 2) - ($A['y'] + $A['h'] / 2);

		$sides = ['right', 'left'];

		if (abs($dy) > abs($dx) * 1.15)
		{
			$sides = $dy >= 0 ? ['bottom', 'top'] : ['top', 'bottom'];
		}
		elseif ($B['x'] + $B['w'] <= $A['x'] - 16)
		{
			$sides = ['bottom', 'bottom'];
		}

		// Петля назад: обе точки на нижней грани, обход ниже обеих рамок.
		if ($sides[0] === $sides[1])
		{
			$out = self::_svg_port($A, 'bottom', ($B['x'] + $B['w'] / 2 - $A['x']) / max(1, $A['w']));
			$into = self::_svg_port($B, 'bottom', ($A['x'] + $A['w'] / 2 - $B['x']) / max(1, $B['w']));
			$ch = max($A['y'] + $A['h'], $B['y'] + $B['h']) + 30;

			return [
				'd' => 'M '.$out['x'].' '.$out['y']
					.' L '.$out['x'].' '.$ch.' L '.$into['x'].' '.$ch
					.' L '.$into['x'].' '.$into['y'],
				'lx' => (int) (($out['x'] + $into['x']) / 2),
				'ly' => (int) $ch - 8,
			];
		}

		$p1 = self::_svg_port($A, $sides[0], 0.5);
		$p2 = self::_svg_port($B, $sides[1], 0.5);
		$horizontal = ($sides[0] === 'right' OR $sides[0] === 'left');

		// Третий шаг в коридоре: прямой провод перечеркнул бы его. Уводим
		// линию под/над (для горизонтали) или вбок (для вертикали) — так же,
		// как это делает холст.
		if (self::_svg_blocked($p1, $p2, $A, $B, $rects))
		{
			if ($horizontal)
			{
				$low = max($A['y'] + $A['h'], $B['y'] + $B['h']);

				foreach ($rects as $r)
				{
					if ($r['id'] !== $A['id'] AND $r['id'] !== $B['id'])
					{
						$low = max($low, $r['y'] + $r['h']);
					}
				}

				$ch = $low + 26;
				$from = self::_svg_port($A, 'bottom', 0.5);
				$into = self::_svg_port($B, 'bottom', 0.5);

				return [
					'd' => 'M '.$from['x'].' '.$from['y']
						.' L '.$from['x'].' '.$ch.' L '.$into['x'].' '.$ch
						.' L '.$into['x'].' '.$into['y'],
					'lx' => (int) (($from['x'] + $into['x']) / 2),
					'ly' => (int) $ch - 8,
				];
			}

			$right = max($A['x'] + $A['w'], $B['x'] + $B['w']);

			foreach ($rects as $r)
			{
				if ($r['id'] !== $A['id'] AND $r['id'] !== $B['id'])
				{
					$right = max($right, $r['x'] + $r['w']);
				}
			}

			$chx = $right + 30;
			$from = self::_svg_port($A, 'right', 0.5);
			$into = self::_svg_port($B, 'right', 0.5);

			return [
				'd' => 'M '.$from['x'].' '.$from['y']
					.' L '.$chx.' '.$from['y'].' L '.$chx.' '.$into['y']
					.' L '.$into['x'].' '.$into['y'],
				'lx' => (int) $chx + 6,
				'ly' => (int) (($from['y'] + $into['y']) / 2),
			];
		}

		if ($horizontal)
		{
			$mx = (int) (($p1['x'] + $p2['x']) / 2);

			return [
				'd' => 'M '.$p1['x'].' '.$p1['y']
					.' C '.$mx.' '.$p1['y'].', '.$mx.' '.$p2['y'].', '.$p2['x'].' '.$p2['y'],
				'lx' => $mx,
				'ly' => (int) (($p1['y'] + $p2['y']) / 2) - 8,
			];
		}

		$my = (int) (($p1['y'] + $p2['y']) / 2);

		return [
			'd' => 'M '.$p1['x'].' '.$p1['y']
				.' C '.$p1['x'].' '.$my.', '.$p2['x'].' '.$my.', '.$p2['x'].' '.$p2['y'],
			'lx' => (int) (($p1['x'] + $p2['x']) / 2),
			'ly' => $my,
		];
	}

	/**
	 * Пересекает ли третий блок прямую между портами. Проверяем именно
	 * линию (12 замеров), а не широкий прямоугольник: иначе наклонная
	 * связь «сверху-слева → снизу-справа» считалась бы перекрытой и уходила
	 * в обход без причины.
	 */
	protected static function _svg_blocked(array $p1, array $p2, array $A, array $B, array $rects)
	{
		if ( ! $rects)
		{
			return FALSE;
		}

		for ($i = 1; $i < 12; $i++)
		{
			$t = $i / 12;
			$x = $p1['x'] + ($p2['x'] - $p1['x']) * $t;
			$y = $p1['y'] + ($p2['y'] - $p1['y']) * $t;

			foreach ($rects as $r)
			{
				if ($r['id'] === $A['id'] OR $r['id'] === $B['id'])
				{
					continue;
				}

				if ($x > $r['x'] - 6 AND $x < $r['x'] + $r['w'] + 6
					AND $y > $r['y'] - 6 AND $y < $r['y'] + $r['h'] + 6)
				{
					return TRUE;
				}
			}
		}

		return FALSE;
	}

	public static function svg(array $graph, array $opts = [])
	{
		$nodes = (array) Arr::get($graph, 'nodes', []);
		$edges = (array) Arr::get($graph, 'edges', []);
		$lanes = (array) Arr::get($graph, 'lanes', []);
		$mode = (string) Arr::get($opts, 'mode', Arr::path($graph, 'meta.mode', 'plan'));
		$mode = $mode === 'fact' ? 'fact' : 'plan';
		$highlight = (array) Arr::get($opts, 'highlight', []);
		$ontology  = (array) Arr::get($opts, 'ontology', []);
		$compact = ! empty($opts['compact']);
		$class = (string) Arr::get($opts, 'class', 'nj670-svg');

		$width = max(900, (int) Arr::path($graph, 'meta.width', 1100));
		$height = max(360, (int) Arr::path($graph, 'meta.height', 520));

		$metrics = self::metrics($graph);
		$elapsed = max(1, (int) $metrics['elapsed_minutes']);

		$by_id = [];

		foreach ($nodes as $node)
		{
			$by_id[$node['id']] = $node;
		}

		$out = [];
		$out[] = '<svg class="'.HTML::chars($class).'" viewBox="0 0 '.$width.' '.$height.'"'
			.' xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Схема процесса">';
		$out[] = '<defs><marker id="nj670-arrow" viewBox="0 0 10 10" refX="9" refY="5"'
			.' markerWidth="7" markerHeight="7" orient="auto-start-reverse">'
			.'<path d="M 0 0 L 10 5 L 0 10 z" fill="#94a3b8"/></marker></defs>';
		$out[] = '<rect x="0" y="0" width="'.$width.'" height="'.$height.'" fill="#f8fafc"/>';

		$lane_h = self::LANE_HEIGHT;

		foreach ($lanes as $i => $lane)
		{
			$y = self::LANE_TOP + $i * $lane_h;
			$fill = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
			$out[] = '<rect x="16" y="'.$y.'" width="'.($width - 32).'" height="'.$lane_h.'"'
				.' rx="12" fill="'.$fill.'" stroke="#e2e8f0" stroke-width="1"/>';
			$out[] = '<line x1="16" y1="'.($y + 38).'" x2="'.($width - 16).'" y2="'.($y + 38).'" stroke="#eef2f7"/>';
			$out[] = '<text x="34" y="'.($y + 25).'" font-size="13" font-weight="700" fill="#475569">'
				.HTML::chars(UTF8::substr((string) $lane['title'], 0, 70)).'</text>';
		}

		// рёбра. Геометрия повторяет правила холста: порт выбирается по
		// положению цели, петля обходит рамки снаружи, самопетля — дугой над
		// шагом. Иначе миниатюра на страницах аудита и факта рисовала стрелку
		// из правой грани в левую даже когда цель стояла ниже — линия шла
		// сквозь блоки и читалась как ошибка схемы.
		foreach ($edges as $edge)
		{
			if ( ! isset($by_id[$edge['from']], $by_id[$edge['to']]))
			{
				continue;
			}

			$from = self::_svg_rect($by_id[$edge['from']]);
			$to = self::_svg_rect($by_id[$edge['to']]);

			$stroke = '#94a3b8';
			$sw = 1.4;

			if ($mode === 'fact')
			{
				// Толщина — объём переходов, цвет — температура ожидания
				// (T_wait): рубиновый ≥ 8 ч, янтарный ≥ 2 ч, голубой — меньше.
				$sw = max(1.2, min(6.0, 1.2 + (float) $edge['weight'] * 0.08));
				$avg_wait = (int) Arr::get($edge, 'avg_minutes', 0);

				if ($avg_wait >= 480)
				{
					$stroke = '#a85252';
				}
				elseif ($avg_wait >= 120)
				{
					$stroke = '#9a7b3f';
				}
				elseif ($avg_wait > 0)
				{
					$stroke = '#7c8da3';
				}
			}

			if ( ! empty($edge['properties']['problem']) OR $edge['type'] === 'loop')
			{
				$stroke = '#a85252';
			}

			$rects = [];

			foreach ($by_id as $rect_node)
			{
				$rects[] = self::_svg_rect($rect_node);
			}

			$geom = self::_svg_edge($from, $to, $rects);

			$out[] = '<path d="'.$geom['d'].'" fill="none" stroke="'.$stroke.'" stroke-width="'.$sw.'"'
				.' marker-end="url(#nj670-arrow)"'.($edge['type'] === 'loop' ? ' stroke-dasharray="6 5"' : '').'/>';

			if ( ! $compact AND $edge['condition'] !== '')
			{
				$text = trim(UTF8::substr((string) $edge['condition'], 0, 28));
				$mx = (int) $geom['lx'];
				$my = (int) $geom['ly'];

				// Подпись читается на линии за счёт светлой обводки, а не
				// плашки: плашка закрывала соседние шаги на миниатюре.
				$out[] = '<text x="'.$mx.'" y="'.$my.'" font-size="10.5" font-weight="600" text-anchor="middle"'
					.' fill="#7a5c2e" stroke="#f8fafc" stroke-width="3" paint-order="stroke" stroke-linejoin="round">'
					.HTML::chars($text).'</text>';
			}

			if ( ! empty($edge['properties']['count']) AND ! $compact)
			{
				$out[] = '<text x="'.(int) $geom['lx'].'" y="'.((int) $geom['ly'] + 18).'" font-size="10" text-anchor="middle" fill="#64748b">'
					.(int) $edge['properties']['count'].' раз</text>';
			}
		}

		// узлы
		foreach ($nodes as $node)
		{
			$type = $node['type'];
			$x = (int) $node['x'];
			$y = (int) $node['y'];
			$w = (int) $node['w'];
			$h = (int) $node['h'];
			$wait = (int) Arr::get($node, 'wait_time_minutes', 0);
			$share = $wait / $elapsed;
			$severity = self::severity_of_wait($share);
			$flag = (string) Arr::get($highlight, (string) Arr::get($node, 'step_id', ''),
				(string) Arr::get($node['properties'], 'severity', ''));
			$problem = ( ! empty($node['properties']['problem']) OR ! empty($node['properties']['shadow'])
				OR in_array($flag, ['critical', 'high'], TRUE));

			$fill = '#ffffff';
			$stroke = '#94a3b8';

			if ($mode === 'fact')
			{
				$fill = '#eef4f0';
				$stroke = '#5f8a72';

				if ($severity === 'medium')
				{
					$fill = '#faf4e6';
					$stroke = '#9a7b3f';
				}
				elseif ($severity === 'high')
				{
					$fill = '#f9efe4';
					$stroke = '#a8703f';
				}
				elseif ($severity === 'critical')
				{
					$fill = '#f9eaea';
					$stroke = '#a85252';
				}
			}

			if ($type === 'start' OR $type === 'end')
			{
				$fill = $type === 'start' ? '#eef4f0' : '#f8eeee';
				$stroke = $type === 'start' ? '#5f8a72' : '#a06a6a';
			}

			if ($type === 'gateway_xor' OR $type === 'gateway_and')
			{
				$fill = '#f7f3e2';
				$stroke = '#99803c';
				$cx = $x + $w / 2;
				$cy = $y + $h / 2;
				$out[] = '<polygon points="'.$cx.','.$y.' '.($x + $w).','.$cy.' '.$cx.','.($y + $h).' '.$x.','.$cy.'"'
					.' fill="'.$fill.'" stroke="'.$stroke.'" stroke-width="2"/>';
				$out[] = '<text x="'.$cx.'" y="'.($cy + 4).'" font-size="11" font-weight="700" text-anchor="middle" fill="'.$stroke.'">'
					.($type === 'gateway_xor' ? 'XOR' : 'AND').'</text>';
				continue;
			}

			if ($type === 'start' OR $type === 'end')
			{
				$cx = $x + $w / 2;
				$cy = $y + $h / 2;
				$out[] = '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.($h / 2).'" fill="'.$fill.'" stroke="'.$stroke.'" stroke-width="2"/>';
				$out[] = '<text x="'.$cx.'" y="'.($cy + 4).'" font-size="11" font-weight="700" text-anchor="middle" fill="'.$stroke.'">'
					.($type === 'start' ? 'СТАРТ' : 'ФИНИШ').'</text>';
				continue;
			}

			$dash = $type === 'timer' ? ' stroke-dasharray="7 5"' : '';
			$out[] = '<rect x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" rx="10"'
				.' fill="'.$fill.'" stroke="'.$stroke.'" stroke-width="'.($problem ? '2.5' : '1.6').'"'.$dash.'/>';
			$out[] = '<rect x="'.$x.'" y="'.$y.'" width="6" height="'.$h.'" rx="3" fill="'.$stroke.'"/>';

			$lines = self::_wrap((string) $node['title'], 28, 2);
			$ty = $y + 24;

			foreach ($lines as $line)
			{
				$out[] = '<text x="'.($x + 18).'" y="'.$ty.'" font-size="12.5" font-weight="600" fill="#0f172a">'
					.HTML::chars($line).'</text>';
				$ty += 16;
			}

			$meta = [];

			if ($node['role_code'] !== '')
			{
				$meta[] = self::_role_name($node['role_code'], $ontology, (string) Arr::get($node['properties'], 'role_label', ''));
			}
			elseif ( ! empty($node['properties']['role_label']))
			{
				$meta[] = (string) $node['properties']['role_label'];
			}

			if ($node['system_code'] !== '')
			{
				$by_sys = (array) Arr::path($ontology, 'by_code.system', []);
				$meta[] = isset($by_sys[$node['system_code']]['name'])
					? (string) $by_sys[$node['system_code']]['name'] : $node['system_code'];
			}
			elseif ( ! empty($node['properties']['system_label']))
			{
				$meta[] = (string) $node['properties']['system_label'];
			}

			if ($meta)
			{
				$out[] = '<text x="'.($x + 18).'" y="'.($y + $h - 20).'" font-size="10" fill="#64748b">'
					.HTML::chars(UTF8::substr(implode(' · ', $meta), 0, 40)).'</text>';
			}

			if ($wait > 0 AND ! $compact)
			{
				$out[] = '<rect x="'.($x + $w - 64).'" y="'.($y + 8).'" width="56" height="18" rx="5" fill="'.$stroke.'" opacity="0.12"/>';
				$out[] = '<text x="'.($x + $w - 36).'" y="'.($y + 21).'" font-size="10" font-weight="700" text-anchor="middle" fill="'.$stroke.'">'
					.self::minutes_text($wait).'</text>';
			}

			if ( ! empty($node['properties']['shadow']))
			{
				$out[] = '<text x="'.($x + $w - 12).'" y="'.($y + $h - 8).'" font-size="11" text-anchor="end" fill="#a85252" font-weight="700">тень</text>';
			}
		}

		$out[] = '</svg>';

		return implode('', $out);
	}

	protected static function _wrap($text, $width, $max_lines = 2)
	{
		$text = trim(preg_replace('~\s+~u', ' ', (string) $text));

		if ($text === '')
		{
			return [''];
		}

		$words = preg_split('~\s+~u', $text, -1, PREG_SPLIT_NO_EMPTY);
		$lines = [];
		$line = '';

		foreach ($words as $word)
		{
			$candidate = $line === '' ? $word : $line.' '.$word;

			if (mb_strlen($candidate) <= $width)
			{
				$line = $candidate;
			}
			else
			{
				if ($line !== '')
				{
					$lines[] = $line;
				}

				$line = $word;

				if (count($lines) >= $max_lines - 1)
				{
					break;
				}
			}
		}

		if ($line !== '' AND count($lines) < $max_lines)
		{
			$lines[] = $line;
		}

		if ( ! $lines)
		{
			$lines[] = mb_substr($text, 0, $width);
		}

		$last = count($lines) - 1;

		if (mb_strlen($lines[$last]) >= $width)
		{
			$lines[$last] = mb_substr($lines[$last], 0, $width - 1).'…';
		}

		return $lines;
	}

	// ------------------------------------------------------------- RACI

	/**
	 * Матрица ответственности из связей онтологии.
	 *
	 * R — исполнитель шага; A — владелец процесса; C — роли, передавшие
	 * работу в этот шаг; I — роли следующих шагов. Если роль выполняет и
	 * владеет — в клетке «A/R».
	 */
	public static function raci(array $graph, $owner_role_code = '', array $ontology = [])
	{
		$nodes = (array) Arr::get($graph, 'nodes', []);
		$edges = (array) Arr::get($graph, 'edges', []);
		$by_id = [];
		$roles = [];

		foreach ($nodes as $node)
		{
			$by_id[$node['id']] = $node;

			if ($node['role_code'] !== '')
			{
				$roles[$node['role_code']] = self::_role_name($node['role_code'], $ontology);
			}
		}

		$owner_role_code = trim((string) $owner_role_code);

		if ($owner_role_code !== '')
		{
			$roles[$owner_role_code] = self::_role_name($owner_role_code, $ontology);
		}

		$prev = [];
		$next = [];

		foreach ($edges as $edge)
		{
			$prev[$edge['to']][] = $edge['from'];
			$next[$edge['from']][] = $edge['to'];
		}

		$rows = [];

		foreach ($nodes as $node)
		{
			if (in_array($node['type'], ['start', 'end', 'gateway_xor', 'gateway_and'], TRUE))
			{
				continue;
			}

			$cells = [];
			$performer = $node['role_code'];

			if ($performer !== '')
			{
				$cells[$performer] = 'R';
			}

			if ($owner_role_code !== '')
			{
				$cells[$owner_role_code] = isset($cells[$owner_role_code])
					? $cells[$owner_role_code].'/A'
					: 'A';
			}

			foreach ((array) Arr::get($prev, $node['id'], []) as $pid)
			{
				$role = (string) Arr::path($by_id, $pid.'.role_code', '');

				if ($role !== '' AND $role !== $performer AND $role !== $owner_role_code)
				{
					$cells[$role] = 'C';
				}
			}

			foreach ((array) Arr::get($next, $node['id'], []) as $nid)
			{
				$role = (string) Arr::path($by_id, $nid.'.role_code', '');

				if ($role !== '' AND $role !== $performer AND $role !== $owner_role_code AND ! isset($cells[$role]))
				{
					$cells[$role] = 'I';
				}
			}

			$rows[] = [
				'step_id' => $node['step_id'],
				'title'   => $node['title'],
				'type'    => $node['type'],
				'cells'   => $cells,
			];
		}

		$columns = [];

		foreach ($roles as $code => $name)
		{
			$columns[] = ['code' => $code, 'name' => $name];
		}

		return ['columns' => $columns, 'rows' => $rows];
	}

	/**
	 * BPMN 2.0 XML: обмен с другими редакторами (Storm, Camunda, Bizagi).
	 *
	 * Отдаём семантическую модель без диаграммной подложки (bpmndi): все
	 * редакторы читают process/laneSet/flow, а координаты у каждого свои.
	 * Если импортёр потребует DI — это следующий шаг, не потеря данных.
	 */
	public static function bpmn_xml(array $process, array $version, array $graph)
	{
		$code = (string) Arr::get($process, 'code', 'process');
		$id = 'Process_'.preg_replace('~[^a-zA-Z0-9_]~', '_', $code);
		$name = self::_xml((string) Arr::get($process, 'title', 'Процесс'));

		$xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$xml .= '<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL"'
			.' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
			.' xmlns:dc="http://www.omg.org/spec/DD/20100524/DC"'
			.' xmlns:di="http://www.omg.org/spec/DD/20100524/DI"'
			.' id="Definitions_'.$id.'" targetNamespace="https://n2j.ru/processhub"'
			.' exporter="ProcessHub" exporterVersion="1.0">'."\n";
		$xml .= '  <bpmn:collaboration id="Collaboration_'.$id.'">'."\n";
		$xml .= '    <bpmn:participant id="Participant_'.$id.'" name="'.$name.'" processRef="'.$id.'"/>'."\n";
		$xml .= '  </bpmn:collaboration>'."\n";
		$xml .= '  <bpmn:process id="'.$id.'" name="'.$name.'" isExecutable="false">'."\n";

		// Дорожки: id из role_code, имя человеческое.
		$lanes = (array) Arr::get($graph, 'lanes', []);

		if ($lanes)
		{
			$xml .= '    <bpmn:laneSet id="LaneSet_'.$id.'">'."\n";

			foreach ($lanes as $i => $lane)
			{
				$lane_id = 'Lane_'.self::_xml_id((string) Arr::get($lane, 'id', 'lane'.($i + 1)));
				$xml .= '      <bpmn:lane id="'.$lane_id.'" name="'.self::_xml((string) Arr::get($lane, 'title', '')).'">'."\n";

				foreach ((array) Arr::get($graph, 'nodes', []) as $node)
				{
					if ((string) Arr::get($node, 'lane', '') === (string) Arr::get($lane, 'id', ''))
					{
						$xml .= '        <bpmn:flowNodeRef>'.self::_xml_id((string) $node['id']).'</bpmn:flowNodeRef>'."\n";
					}
				}

				$xml .= '      </bpmn:lane>'."\n";
			}

			$xml .= '    </bpmn:laneSet>'."\n";
		}

		foreach ((array) Arr::get($graph, 'nodes', []) as $node)
		{
			$node_id = self::_xml_id((string) $node['id']);
			$node_name = self::_xml((string) $node['title']);
			$type = (string) $node['type'];

			if ($type === 'start')
			{
				$xml .= '    <bpmn:startEvent id="'.$node_id.'" name="'.$node_name.'"/>'."\n";
			}
			elseif ($type === 'end')
			{
				$xml .= '    <bpmn:endEvent id="'.$node_id.'" name="'.$node_name.'"/>'."\n";
			}
			elseif ($type === 'service_task')
			{
				$xml .= '    <bpmn:serviceTask id="'.$node_id.'" name="'.$node_name.'"/>'."\n";
			}
			elseif ($type === 'manual_task')
			{
				$xml .= '    <bpmn:manualTask id="'.$node_id.'" name="'.$node_name.'"/>'."\n";
			}
			elseif ($type === 'gateway_xor')
			{
				$xml .= '    <bpmn:exclusiveGateway id="'.$node_id.'" name="'.$node_name.'"/>'."\n";
			}
			elseif ($type === 'gateway_and')
			{
				$xml .= '    <bpmn:parallelGateway id="'.$node_id.'" name="'.$node_name.'"/>'."\n";
			}
			elseif ($type === 'timer')
			{
				$xml .= '    <bpmn:intermediateCatchEvent id="'.$node_id.'" name="'.$node_name.'">'."\n";
				$xml .= '      <bpmn:timerEventDefinition id="Timer_'.$node_id.'"/>'."\n";
				$xml .= '    </bpmn:intermediateCatchEvent>'."\n";
			}
			elseif ($type === 'subprocess')
			{
				$called = trim((string) Arr::get((array) Arr::get($node, 'properties', []), 'process_code', ''));

				if ($called !== '')
				{
					// Ссылка на другой процесс: callActivity + calledElement,
					// чтобы Storm/Camunda открыли её как связь, а не пустую рамку.
					$xml .= '    <bpmn:callActivity id="'.$node_id.'" name="'.$node_name.'"'
						.' calledElement="'.self::_xml_id($called).'"/>'."\n";
				}
				else
				{
					$xml .= '    <bpmn:subProcess id="'.$node_id.'" name="'.$node_name.'"/>'."\n";
				}
			}
			else
			{
				$xml .= '    <bpmn:userTask id="'.$node_id.'" name="'.$node_name.'"/>'."\n";
			}
		}

		// Комментарии ревьюера — в textAnnotation + association, чтобы они
		// пережили круг «экспорт → Storm → импорт» и были видны в чужом BPMN.
		foreach ((array) Arr::get($graph, 'nodes', []) as $node)
		{
			$comment = trim((string) Arr::get((array) Arr::get($node, 'properties', []), 'comment', ''));

			if ($comment === '')
			{
				continue;
			}

			$node_id = self::_xml_id((string) $node['id']);
			$note_id = 'Note_'.$node_id;
			$xml .= '    <bpmn:textAnnotation id="'.$note_id.'">'."\n";
			$xml .= '      <bpmn:text>'.self::_xml($comment).'</bpmn:text>'."\n";
			$xml .= '    </bpmn:textAnnotation>'."\n";
			$xml .= '    <bpmn:association id="Assoc_'.$node_id.'" sourceRef="'.$note_id.'" targetRef="'.$node_id.'"/>'."\n";
		}

		foreach ((array) Arr::get($graph, 'edges', []) as $i => $edge)
		{
			$condition = trim((string) Arr::get($edge, 'condition', ''));
			$xml .= '    <bpmn:sequenceFlow id="Flow_'.($i + 1).'"'
				.' sourceRef="'.self::_xml_id((string) $edge['from']).'"'
				.' targetRef="'.self::_xml_id((string) $edge['to']).'"'
				.($condition !== '' ? ' name="'.self::_xml($condition).'"' : '')
				.'/>'."\n";
		}

		$xml .= '  </bpmn:process>'."\n";
		$xml .= '</bpmn:definitions>'."\n";

		return $xml;
	}

	protected static function _xml($text)
	{
		return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
	}

	protected static function _xml_id($value)
	{
		$value = preg_replace('~[^a-zA-Z0-9_\-.]~', '_', (string) $value);

		if ($value === '' OR preg_match('~^[0-9]~', $value))
		{
			$value = 'n_'.$value;
		}

		return $value;
	}

	/**
	 * PlantUML: Activity Diagram с дорожками.
	 *
	 * Дешёвый экспорт для аналитиков: Storm поддерживает PlantUML «из коробки»,
	 * и такая выгрузка открывается в любом Markdown/Confluence. Требования к
	 * синтаксису жёсткие (метки не должны ломать кавычки), поэтому экранируем.
	 */
	public static function plantuml(array $process, array $graph, array $opts = [])
	{
		$title = self::_pu((string) Arr::get($process, 'title', 'Процесс'));
		$mode = (string) Arr::get($opts, 'mode', 'plan');
		$nodes = (array) Arr::get($graph, 'nodes', []);
		$edges = (array) Arr::get($graph, 'edges', []);
		$lanes = (array) Arr::get($graph, 'lanes', []);

		$by_id = [];
		$lane_title = [];

		foreach ($lanes as $lane)
		{
			$lane_title[(string) $lane['id']] = (string) Arr::get($lane, 'title', $lane['id']);
		}

		$outgoing = [];
		$incoming = [];

		foreach ($edges as $edge)
		{
			$outgoing[(string) $edge['from']][] = $edge;
			$incoming[(string) $edge['to']][] = $edge;
		}

		foreach ($nodes as $node)
		{
			$by_id[(string) $node['id']] = $node;
		}

		$lines = [];
		$lines[] = '@startuml';
		$lines[] = 'title '.$title.($mode === 'fact' ? ' — факт' : '');
		$lines[] = 'skinparam defaultFontName "Segoe UI"';
		$lines[] = 'skinparam ActivityBackgroundColor #ffffff';
		$lines[] = 'skinparam ActivityBorderColor #475569';
		$lines[] = '';

		// Старт: первое стартовое событие или шаг без входящих связей.
		$start = NULL;

		foreach ($nodes as $node)
		{
			if ((string) $node['type'] === 'start')
			{
				$start = $node;
				break;
			}
		}

		if ( ! $start)
		{
			foreach ($nodes as $node)
			{
				if (empty($incoming[(string) $node['id']]))
				{
					$start = $node;
					break;
				}
			}
		}

		if ( ! $start)
		{
			return "@startuml\ntitle ".$title."\nnote \"Схема пуста\" as N1\n@enduml\n";
		}

		$visited = [];
		$lines = array_merge($lines, self::_pu_walk((string) $start['id'], $by_id, $outgoing, $lane_title, $visited, 0, $mode));

		// Несвязные шаги: покажем отдельной заметкой, чтобы не терялись молча.
		$orphans = [];

		foreach ($nodes as $node)
		{
			$id = (string) $node['id'];

			if ($id !== (string) $start['id'] AND ! isset($visited[$id]) AND (string) $node['type'] !== 'end')
			{
				$orphans[] = '- '.self::_pu((string) $node['title']);
			}
		}

		if ($orphans)
		{
			$lines[] = '';
			$lines[] = 'note bottom';
			$lines[] = 'Шаги вне основного потока:';
			$lines = array_merge($lines, array_slice($orphans, 0, 12));
			$lines[] = 'end note';
		}

		$lines[] = '@enduml';

		return implode("\n", $lines)."\n";
	}

	/**
	 * Обход схемы: activity + ветвления. Глубина ограничена — циклы в графе
	 * легальны, но рекурсия без предела на них не остановится.
	 */
	protected static function _pu_walk($id, array $by_id, array $outgoing, array $lane_title, array &$visited, $depth, $mode)
	{
		if (isset($visited[$id]) OR $depth > 24 OR ! isset($by_id[$id]))
		{
			return [];
		}

		$visited[$id] = TRUE;
		$node = $by_id[$id];
		$type = (string) $node['type'];
		$label = self::_pu((string) $node['title']);

		if ($type === 'start' OR $type === 'end')
		{
			$lines = [($type === 'start' ? 'start' : 'stop')];
		}
		elseif ($type === 'gateway_xor' OR $type === 'gateway_and')
		{
			$lines = ['if ('.self::_pu((string) $node['title']).'?) then (да)'];
		}
		else
		{
			$lane = (string) Arr::get($node, 'lane', '');
			$who = isset($lane_title[$lane]) ? self::_pu($lane_title[$lane]) : '';

			if ($who !== '')
			{
				$lines = ['|'.$who.'|'];
			}
			else
			{
				$lines = [];
			}

			$minutes = (int) Arr::get($node, 'duration_minutes', 0);
			$lines[] = ':'.$label.($minutes > 0 ? '\\n<size:10>'.self::_pu(Service_Nj670_Repo::minutes_text($minutes)).'</size>' : '').';';
		}

		$next = (array) Arr::get($outgoing, $id, []);

		// Ветвление шлюза: первая связь — then, остальные — else if.
		if (($type === 'gateway_xor' OR $type === 'gateway_and') AND count($next) > 1)
		{
			$first = array_shift($next);
			$lines = array_merge($lines, self::_pu_walk((string) $first['to'], $by_id, $outgoing, $lane_title, $visited, $depth + 1, $mode));

			foreach ($next as $edge)
			{
				$condition = trim((string) Arr::get($edge, 'condition', ''));

				if ($condition === '')
				{
					$condition = 'иначе';
				}

				$lines[] = 'else ('.self::_pu($condition).')';
				$lines = array_merge($lines, self::_pu_walk((string) $edge['to'], $by_id, $outgoing, $lane_title, $visited, $depth + 1, $mode));
			}

			$lines[] = 'endif';

			return $lines;
		}

		foreach ($next as $edge)
		{
			$lines = array_merge($lines, self::_pu_walk((string) $edge['to'], $by_id, $outgoing, $lane_title, $visited, $depth + 1, $mode));
		}

		return $lines;
	}

	protected static function _pu($text)
	{
		$text = trim(preg_replace('~\s+~u', ' ', (string) $text));

		return str_replace(['"', '\\', '\n'], ['\'', '/', ' '], $text);
	}

	/**
	 * Компактная сводка для карточки процесса.
	 */
	public static function summary(array $graph)
	{
		$metrics = self::metrics($graph);

		return [
			'nodes'     => (int) $metrics['nodes_count'],
			'lanes'     => (int) $metrics['lanes_count'],
			'elapsed'   => (int) $metrics['elapsed_minutes'],
			'cost'      => (float) $metrics['cost_rub'],
			'loops'     => (int) $metrics['loop_count'],
			'shadows'   => (int) $metrics['shadow_count'],
			'bottleneck'=> $metrics['bottleneck'],
		];
	}
}
