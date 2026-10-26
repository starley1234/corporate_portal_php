<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-667 чистый граф без БД: нормализация, AI-Guard, патчи RFC 6902, склейка, SVG-превью.
 *
 * Контроллеры и вьюхи сюда не ходят — только через дверь Service_Nj667_Graph3d.
 * Стенд .tools/nj667_graph3d_stand.php покрывает эти функции (владелец, 11.10.2026).
 */
class Service_Nj667_Graph
{

	// ------------------------------------------------------------ чистые: граф

	/**
	 * Привести граф к виду, с которым можно работать: уникальные id, известные
	 * типы, координаты в пределах сцены, ребра только между существующими
	 * узлами. Модель регулярно присылает лишнее — это дешевле починить здесь,
	 * чем объяснять модели в сотый раз.
	 */
	public static function graph_clean($graph)
	{
		$graph = is_array($graph) ? $graph : [];

		$meta = (array) Arr::get($graph, 'meta', []);
		$meta = [
			'title' => UTF8::substr(trim((string) Arr::get($meta, 'title', '')), 0, 200),
			'units' => UTF8::substr(trim((string) Arr::get($meta, 'units', 'unit')), 0, 16) ?: 'unit',
		];

		$radius = (float) Service_Nj667_Graph3d::canvas('radius', 24.0);
		$types  = Service_Nj667_Graph3d::node_types();
		$etypes = Service_Nj667_Graph3d::edge_types();
		$max_lb = (int) Service_Nj667_Graph3d::limits('max_label', 80);

		$nodes = [];
		$seen  = [];

		foreach ((array) Arr::get($graph, 'nodes', []) as $i => $row)
		{
			if ( ! is_array($row))
			{
				continue;
			}

			$id = trim((string) Arr::get($row, 'id', ''));

			if ($id === '')
			{
				$id = 'n'.($i + 1);
			}

			$id = preg_replace('~[^A-Za-z0-9_\-]+~', '_', $id);

			if (isset($seen[$id]) OR count($nodes) >= (int) Service_Nj667_Graph3d::limits('max_nodes', 500))
			{
				continue;
			}

			$seen[$id] = TRUE;

			$type = (string) Arr::get($row, 'type', '');

			if ( ! isset($types[$type]))
			{
				$type = 'connector';
			}

			$data = (array) Arr::get($row, 'data', []);
			$clean_data = [];

			foreach ($data as $key => $value)
			{
				$key = preg_replace('~[^A-Za-z0-9_\-]+~', '_', (string) $key);

				if ($key === '')
				{
					continue;
				}

				$clean_data[$key] = is_scalar($value)
					? UTF8::substr(trim((string) $value), 0, 120)
					: (is_array($value) ? array_slice(array_values($value), 0, 12) : NULL);

				if ($clean_data[$key] === NULL)
				{
					unset($clean_data[$key]);
				}
			}

			$nodes[] = [
				'id'    => $id,
				'type'  => $type,
				'x'     => self::_clamp_coord(Arr::get($row, 'x'), $radius),
				'y'     => self::_clamp_coord(Arr::get($row, 'y'), $radius),
				'z'     => self::_clamp_coord(Arr::get($row, 'z'), $radius),
				// Подписи узлов на холсте мешаются (владелец, 10.10.2026):
				// модель и конструктор живут по id и координатам, label не храним.
				'label' => '',
				'data'  => $clean_data,
			];
		}

		$by_id = [];

		foreach ($nodes as $node)
		{
			$by_id[$node['id']] = TRUE;
		}

		$edges    = [];
		$seen_e   = [];
		$max_edge = (int) Service_Nj667_Graph3d::limits('max_edges', 1200);

		foreach ((array) Arr::get($graph, 'edges', []) as $i => $row)
		{
			if ( ! is_array($row) OR count($edges) >= $max_edge)
			{
				continue;
			}

			$source = (string) Arr::get($row, 'source', '');
			$target = (string) Arr::get($row, 'target', '');

			// Ребро без конца — это не граф, а мусор: модель иногда «забывает»
			// второй конец, и на холсте получается линия в никуда.
			if ( ! isset($by_id[$source]) OR ! isset($by_id[$target]) OR $source === $target)
			{
				continue;
			}

			$id = trim((string) Arr::get($row, 'id', ''));

			if ($id === '')
			{
				$id = 'e'.($i + 1);
			}

			$id = preg_replace('~[^A-Za-z0-9_\-]+~', '_', $id);

			if (isset($seen_e[$id]))
			{
				continue;
			}

			$seen_e[$id] = TRUE;

			$type = (string) Arr::get($row, 'type', '');

			if ( ! isset($etypes[$type]))
			{
				$type = 'rigid';
			}

			$edges[] = [
				'id'     => $id,
				'source' => $source,
				'target' => $target,
				'type'   => $type,
				'label'  => UTF8::substr(trim((string) Arr::get($row, 'label', '')), 0, $max_lb),
				'style'  => (string) Arr::get($row, 'style', '') === 'curved' ? 'curved' : 'line',
			];
		}

		return [
			'meta'  => $meta,
			'nodes' => $nodes,
			'edges' => $edges,
		];
	}

	/** Координата сцены: число в пределах ±radius, иначе 0. */
	protected static function _clamp_coord($value, $radius)
	{
		if ( ! is_numeric($value))
		{
			return 0.0;
		}

		$value = (float) $value;

		if ($value < -$radius) return -$radius;
		if ($value > $radius)  return $radius;

		$value = round($value, 2);

		// Отрицательный ноль (-0) в JSON выглядит дико и ломает сравнение
		// патчей: «-0» и «0» — одно и то же место, а строки разные.
		return $value == 0 ? 0.0 : $value;
	}

	/**
	 * AI-Guard (структура): граф не пустой, узлы связаны, всё в лимитах.
	 *
	 * Проверка дублирует часть graph_clean намеренно: clean молча чинит, а
	 * guard объясняет, почему работу нельзя публиковать.
	 */
	public static function graph_validate(array $graph, $auto_clean = FALSE)
	{
		if ($auto_clean)
		{
			$graph = self::graph_clean($graph);
		}

		$nodes = (array) Arr::get($graph, 'nodes', []);
		$edges = (array) Arr::get($graph, 'edges', []);
		$errors = [];

		if ( ! $nodes)
		{
			$errors[] = 'Граф пустой: нет ни одного узла.';
		}

		if (count($nodes) > (int) Service_Nj667_Graph3d::limits('max_nodes', 500))
		{
			$errors[] = 'Слишком много узлов: '.count($nodes).' (максимум '
				.(int) Service_Nj667_Graph3d::limits('max_nodes', 500).').';
		}

		if (count($edges) > (int) Service_Nj667_Graph3d::limits('max_edges', 1200))
		{
			$errors[] = 'Слишком много связей: '.count($edges).' (максимум '
				.(int) Service_Nj667_Graph3d::limits('max_edges', 1200).').';
		}

		// Висячие узлы: узел без единой связи — это огрызок, а не деталь
		// конструкции. Один-единственный узел без связей допускаем (заготовка).
		$linked = [];

		foreach ($edges as $edge)
		{
			$linked[(string) Arr::get($edge, 'source')] = TRUE;
			$linked[(string) Arr::get($edge, 'target')] = TRUE;
		}

		$isolated = 0;

		foreach ($nodes as $node)
		{
			if ( ! isset($linked[(string) Arr::get($node, 'id')]))
			{
				$isolated++;
			}
		}

		if ($isolated > (int) Service_Nj667_Graph3d::limits('max_isolated', 0)
			AND ! (count($nodes) === 1 AND ! $edges))
		{
			$errors[] = 'Есть узлы без связей: '.$isolated
				.' (достройте их или удалите, иначе конструкция рассыпается).';
		}

		// Ребро, у которого нет конца, — частый огрех модели после правки.
		$by_id = [];

		foreach ($nodes as $node)
		{
			$by_id[(string) Arr::get($node, 'id')] = TRUE;
		}

		$broken = 0;

		foreach ($edges as $edge)
		{
			if ( ! isset($by_id[(string) Arr::get($edge, 'source')])
				OR ! isset($by_id[(string) Arr::get($edge, 'target')]))
			{
				$broken++;
			}
		}

		if ($broken)
		{
			$errors[] = 'Связей без одного из узлов: '.$broken.'.';
		}

		// Подписи узлов не проверяем (владелец, 10.10.2026): они мешаются
		// на холсте, модель их не ставит, graph_clean выкидывает label.

		return [
			'ok'     => ! $errors,
			'errors' => $errors,
			'stats'  => [
				'nodes'    => count($nodes),
				'edges'    => count($edges),
				'isolated' => $isolated,
				'broken'   => $broken,
			],
		];
	}

	/**
	 * Подграф вокруг выбранных узлов: сами узлы + соседи на $depth шагов.
	 *
	 * В модель при правке уезжает НЕ весь проект: контекст дорогой, а правят
	 * обычно одно место («к сиденью — подлокотники»).
	 */
	public static function graph_neighborhood(array $graph, array $ids, $depth = 1)
	{
		$graph = self::graph_clean($graph);
		$depth = max(0, min(3, (int) $depth));

		$wanted = [];

		foreach ($ids as $id)
		{
			$id = trim((string) $id);

			if ($id !== '')
			{
				$wanted[$id] = TRUE;
			}
		}

		if ( ! $wanted)
		{
			return self::graph_new('');
		}

		$adjacency = [];

		foreach ((array) Arr::get($graph, 'edges', []) as $edge)
		{
			$source = (string) Arr::get($edge, 'source');
			$target = (string) Arr::get($edge, 'target');

			$adjacency[$source][$target] = TRUE;
			$adjacency[$target][$source] = TRUE;
		}

		$frontier = array_keys($wanted);

		for ($step = 0; $step < $depth; $step++)
		{
			$next = [];

			foreach ($frontier as $id)
			{
				foreach ((array) Arr::get($adjacency, $id, []) as $neighbour => $on)
				{
					if ( ! isset($wanted[$neighbour]))
					{
						$wanted[$neighbour] = TRUE;
						$next[]             = $neighbour;
					}
				}
			}

			$frontier = $next;

			if ( ! $frontier)
			{
				break;
			}
		}

		$nodes = [];

		foreach ((array) Arr::get($graph, 'nodes', []) as $node)
		{
			if (isset($wanted[(string) Arr::get($node, 'id')]))
			{
				$nodes[] = $node;
			}
		}

		$edges = [];

		foreach ((array) Arr::get($graph, 'edges', []) as $edge)
		{
			$source = (string) Arr::get($edge, 'source');
			$target = (string) Arr::get($edge, 'target');

			if (isset($wanted[$source]) AND isset($wanted[$target]))
			{
				$edges[] = $edge;
			}
		}

		return [
			'meta'  => (array) Arr::get($graph, 'meta', []),
			'nodes' => $nodes,
			'edges' => $edges,
		];
	}

	/** Заготовка графа: пустой, но правильной формы. */
	public static function graph_new($title = '')
	{
		return [
			'meta'  => ['title' => UTF8::substr(trim((string) $title), 0, 200), 'units' => 'unit'],
			'nodes' => [],
			'edges' => [],
		];
	}

	/**
	 * Заготовки, которые собираются локально — без вызова модели и очереди.
	 * Это намеренно небольшие каркасы: их можно сразу посмотреть и править,
	 * а из каждого ребра получается отдельная палочка в Service_Nj667_Build.
	 */
	public static function simple_templates()
	{
		return [
			'cube' => [
				'title' => 'Кубический каркас',
				'description' => '8 узлов · 18 палочек · диагонально раскреплённый куб',
				'nodes' => 8,
				'edges' => 18,
			],
			'stool' => [
				'title' => 'Табурет',
				'description' => 'Сиденье, 4 ножки, нижняя обвязка и раскосы',
				'nodes' => 8,
				'edges' => 17,
			],
			'bridge' => [
				'title' => 'Мостовая ферма',
				'description' => 'Две треугольные фермы с поперечинами',
				'nodes' => 10,
				'edges' => 19,
			],
			'house' => [
				'title' => 'Каркас домика',
				'description' => 'Основание, стойки, двускатная крыша и подкосы',
				'nodes' => 10,
				'edges' => 23,
			],
		];
	}

	/**
	 * Построить проектный граф из одного из локальных шаблонов.
	 *
	 * Возвращает NULL для неизвестного шаблона; так контроллер не сможет
	 * случайно создать пустой «успешный» проект по подменённому значению.
	 */
	public static function simple_graph($template, $title = '')
	{
		$template = strtolower(trim((string) $template));
		$catalog  = self::simple_templates();

		if ( ! isset($catalog[$template]))
		{
			return NULL;
		}

		$points = [];
		$links  = [];

		if ($template === 'cube')
		{
			$points = [
				'n1' => [-8, -8, -8], 'n2' => [8, -8, -8],
				'n3' => [8, 8, -8],  'n4' => [-8, 8, -8],
				'n5' => [-8, -8, 8], 'n6' => [8, -8, 8],
				'n7' => [8, 8, 8],  'n8' => [-8, 8, 8],
			];
			$links = [
				['n1', 'n2'], ['n2', 'n3'], ['n3', 'n4'], ['n4', 'n1'],
				['n5', 'n6'], ['n6', 'n7'], ['n7', 'n8'], ['n8', 'n5'],
				['n1', 'n5'], ['n2', 'n6'], ['n3', 'n7'], ['n4', 'n8'],
				// По одной диагонали на грань: куб не складывается ромбом.
				['n1', 'n3'], ['n5', 'n7'], ['n1', 'n6'],
				['n4', 'n7'], ['n1', 'n8'], ['n2', 'n7'],
			];
		}
		elseif ($template === 'stool')
		{
			$points = [
				'n1' => [-8, 6, -8], 'n2' => [8, 6, -8],
				'n3' => [8, 6, 8],   'n4' => [-8, 6, 8],
				'n5' => [-8, -10, -8], 'n6' => [8, -10, -8],
				'n7' => [8, -10, 8],   'n8' => [-8, -10, 8],
			];
			$links = [
				// Сиденье, ножки и нижняя обвязка — по четыре палочки.
				['n1', 'n2'], ['n2', 'n3'], ['n3', 'n4'], ['n4', 'n1'],
				['n1', 'n5'], ['n2', 'n6'], ['n3', 'n7'], ['n4', 'n8'],
				['n5', 'n6'], ['n6', 'n7'], ['n7', 'n8'], ['n8', 'n5'],
				// Раскосы по четырём граням и диагональ сиденья гасят перекос.
				['n1', 'n6'], ['n2', 'n7'], ['n4', 'n7'], ['n1', 'n8'], ['n1', 'n3'],
			];
		}
		elseif ($template === 'bridge')
		{
			// По пять узлов на каждую боковую ферму; z задаёт ширину моста.
			$points = [
				'n1' => [-10, -5, -5], 'n2' => [0, -5, -5], 'n3' => [10, -5, -5],
				'n4' => [-5, 5, -5],  'n5' => [5, 5, -5],
				'n6' => [-10, -5, 5], 'n7' => [0, -5, 5], 'n8' => [10, -5, 5],
				'n9' => [-5, 5, 5],   'n10' => [5, 5, 5],
			];

			foreach ([['n1', 'n2', 'n3', 'n4', 'n5'], ['n6', 'n7', 'n8', 'n9', 'n10']] as $side)
			{
				list($b0, $b1, $b2, $t0, $t1) = $side;
				$links = array_merge($links, [
					[$b0, $b1], [$b1, $b2], [$t0, $t1],
					[$b0, $t0], [$t0, $b1], [$b1, $t1], [$t1, $b2],
				]);
			}

			$links = array_merge($links, [
				['n1', 'n6'], ['n2', 'n7'], ['n3', 'n8'],
				['n4', 'n9'], ['n5', 'n10'],
			]);
		}
		else // house
		{
			$points = [
				'n1' => [-8, -8, -8], 'n2' => [8, -8, -8],
				'n3' => [8, -8, 8],   'n4' => [-8, -8, 8],
				'n5' => [-8, 0, -8],  'n6' => [8, 0, -8],
				'n7' => [8, 0, 8],    'n8' => [-8, 0, 8],
				'n9' => [0, 8, -8],   'n10' => [0, 8, 8],
			];
			$links = [
				// Периметр основания и четыре стойки.
				['n1', 'n2'], ['n2', 'n3'], ['n3', 'n4'], ['n4', 'n1'],
				['n1', 'n5'], ['n2', 'n6'], ['n3', 'n7'], ['n4', 'n8'],
				// Периметр стен, стропила и конёк.
				['n5', 'n6'], ['n6', 'n7'], ['n7', 'n8'], ['n8', 'n5'],
				['n5', 'n9'], ['n6', 'n9'], ['n7', 'n10'], ['n8', 'n10'],
				['n9', 'n10'],
				// По диагонали на каждую стену и плоскость крыши.
				['n1', 'n6'], ['n4', 'n7'], ['n1', 'n8'], ['n2', 'n7'],
				['n5', 'n10'], ['n6', 'n10'],
			];
		}

		$title = UTF8::substr(trim((string) $title), 0, 200);
		$title = $title !== '' ? $title : (string) $catalog[$template]['title'];
		$nodes = [];

		foreach ($points as $id => $xyz)
		{
			$nodes[] = [
				'id' => (string) $id,
				'type' => 'connector',
				'x' => (float) $xyz[0],
				'y' => (float) $xyz[1],
				'z' => (float) $xyz[2],
				'label' => '',
				'data' => ['template' => $template],
			];
		}

		$edges = [];

		foreach ($links as $index => $link)
		{
			$edges[] = [
				'id' => 'e'.($index + 1),
				'source' => (string) $link[0],
				'target' => (string) $link[1],
				'type' => 'structural',
				'label' => '',
				'style' => 'line',
			];
		}

		return self::graph_clean([
			'meta' => ['title' => $title, 'units' => 'unit'],
			'nodes' => $nodes,
			'edges' => $edges,
		]);
	}

	/**
	 * Текст графа для поиска и для саммари, если модель его не дала:
	 * id узлов и связи словами. Подписи узлов больше не храним (владелец,
	 * 10.10.2026) — в поиске остаются название проекта и топология.
	 */
	public static function graph_text(array $graph)
	{
		$graph = self::graph_clean($graph);
		$names = [];
		$by_id = [];

		foreach ((array) Arr::get($graph, 'nodes', []) as $node)
		{
			$id         = (string) Arr::get($node, 'id');
			$by_id[$id] = $id;
			$names[]    = $id;
		}

		$links = [];

		foreach ((array) Arr::get($graph, 'edges', []) as $edge)
		{
			$source = (string) Arr::get($edge, 'source');
			$target = (string) Arr::get($edge, 'target');

			$links[] = Arr::get($by_id, $source, $source).' — '.Arr::get($by_id, $target, $target);
		}

		$title = trim((string) Arr::get(Arr::get($graph, 'meta', []), 'title', ''));

		return UTF8::substr(trim(
			($title !== '' ? $title.'. ' : '')
			.'Узлы: '.(implode(', ', $names) ?: 'нет').'. '
			.'Связи: '.(implode('; ', array_slice($links, 0, 60)) ?: 'нет').'.'
		), 0, (int) Service_Nj667_Graph3d::limits('max_summary', 1000));
	}

	/**
	 * Топологический отпечаток: степени узлов и типовой состав.
	 *
	 * Нужен, чтобы искать похожие КОНСТРУКЦИИ, а не только похожие слова:
	 * у двух стульев степень «сиденья» одинаковая, даже если подписи разные.
	 */
	public static function topology_hash(array $graph)
	{
		$graph = self::graph_clean($graph);

		$degree = [];
		$types  = [];

		foreach ((array) Arr::get($graph, 'nodes', []) as $node)
		{
			$degree[(string) Arr::get($node, 'id')] = 0;
			$types[(string) Arr::get($node, 'type', '')] = (int) Arr::get($types, (string) Arr::get($node, 'type', ''), 0) + 1;
		}

		foreach ((array) Arr::get($graph, 'edges', []) as $edge)
		{
			$source = (string) Arr::get($edge, 'source');
			$target = (string) Arr::get($edge, 'target');

			if (isset($degree[$source])) $degree[$source]++;
			if (isset($degree[$target])) $degree[$target]++;
		}

		$sequence = array_values($degree);
		rsort($sequence);

		ksort($types);

		$signature = implode(',', $sequence).'|'.count($degree).'|';

		foreach ($types as $type => $count)
		{
			$signature .= $type.':'.$count.';';
		}

		return md5($signature);
	}

	/**
	 * Собрать few-shot: сначала конструкции с тем же топологическим
	 * отпечатком, потом похожие по вектору саммари, потом текстовый поиск.
	 *
	 * Дубликаты по id выкидываем, свой проект — тоже. «Why» помечает, откуда
	 * строка: воркер пишет это в подсказку, чтобы модель понимала, что стул
	 * с тем же каркасом важнее кресла с похожим описанием.
	 *
	 * Чистая: без БД, покрыта стендом.
	 */
	public static function few_shot_merge(array $by_topo, array $by_embed, array $by_text, $limit, $exclude_id = 0)
	{
		$limit      = max(1, min(8, (int) $limit));
		$exclude_id = (int) $exclude_id;
		$seen       = [];
		$out        = [];

		if ($exclude_id > 0)
		{
			$seen[$exclude_id] = TRUE;
		}

		$batches = [
			['topology', $by_topo],
			['embed',    $by_embed],
			['text',     $by_text],
		];

		foreach ($batches as $pair)
		{
			$why = $pair[0];

			foreach ((array) $pair[1] as $row)
			{
				if ( ! is_array($row))
				{
					continue;
				}

				$id = (int) Arr::get($row, 'id', 0);

				if ($id <= 0 OR isset($seen[$id]))
				{
					continue;
				}

				$seen[$id]    = TRUE;
				$row['why']   = $why;
				$out[]        = $row;

				if (count($out) >= $limit)
				{
					return $out;
				}
			}
		}

		return $out;
	}

	/**
	 * План уплотнения истории.
	 *
	 * Если коммитов больше $keep, старшие схлопываются: последний из
	 * удаляемых становится контрольной точкой (снимок состояния после него),
	 * остальные удаляются. Существующие контрольные точки не трогаем:
	 * иначе откат к оставшимся коммитам не накатит патчи, которых уже нет.
	 *
	 * Возвращает delete_ids / convert_id / convert_no. Чистая: без БД.
	 */
	public static function compact_plan(array $revisions, $keep = 2000)
	{
		$keep = max(1, (int) $keep);
		$rows = array_values($revisions);

		usort($rows, function ($a, $b) {
			return ((int) Arr::get($a, 'no', 0)) - ((int) Arr::get($b, 'no', 0));
		});

		$commits = [];

		foreach ($rows as $row)
		{
			if (Arr::get($row, 'kind') === 'commit')
			{
				$commits[] = $row;
			}
		}

		if (count($commits) <= $keep)
		{
			return ['delete_ids' => [], 'convert_id' => 0, 'convert_no' => 0];
		}

		$drop       = array_slice($commits, 0, count($commits) - $keep);
		$last       = $drop[count($drop) - 1];
		$first_kept = $commits[count($commits) - $keep];
		$last_no    = (int) Arr::get($last, 'no', 0);
		$kept_no    = (int) Arr::get($first_kept, 'no', 0);
		$covered    = FALSE;

		foreach ($rows as $row)
		{
			if (Arr::get($row, 'kind') !== 'milestone')
			{
				continue;
			}

			$no = (int) Arr::get($row, 'no', 0);

			// Между последним удаляемым коммитом и первым оставляемым уже
			// стоит снимок — накатка оставшихся патчей от него не сломается.
			if ($no > $last_no AND $no < $kept_no)
			{
				$covered = TRUE;
				break;
			}
		}

		$delete = [];

		foreach ($drop as $row)
		{
			$delete[] = (int) Arr::get($row, 'id', 0);
		}

		$delete = array_values(array_filter($delete));

		$convert_id = 0;
		$convert_no = 0;

		if ( ! $covered)
		{
			$convert_id = (int) Arr::get($last, 'id', 0);
			$convert_no = $last_no;
			$kept_id    = $convert_id;
			$delete     = array_values(array_filter($delete, function ($id) use ($kept_id) {
				return $id !== $kept_id;
			}));
		}

		return [
			'delete_ids' => $delete,
			'convert_id' => $convert_id,
			'convert_no' => $convert_no,
		];
	}

	/**
	 * План ограниченного окна истории: оставить последние $keep версий.
	 *
	 * Если граница окна — commit, вызывающий код превращает его в milestone
	 * после replay, чтобы оставшиеся патчи продолжали накатываться. Милestones
	 * также входят в лимит строк: ручные контрольные точки не обходят квоту.
	 */
	public static function history_window_plan(array $revisions, $keep = 4)
	{
		$keep = max(1, (int) $keep);
		$rows = array_values($revisions);

		usort($rows, function ($a, $b) {
			return ((int) Arr::get($a, 'no', 0)) - ((int) Arr::get($b, 'no', 0));
		});

		if (count($rows) <= $keep)
		{
			return ['delete_ids' => [], 'convert_id' => 0, 'convert_no' => 0];
		}

		$cut       = count($rows) - $keep;
		$boundary  = $rows[$cut];
		$delete    = [];

		for ($i = 0; $i < $cut; $i++)
		{
			$id = (int) Arr::get($rows[$i], 'id', 0);

			if ($id > 0)
			{
				$delete[] = $id;
			}
		}

		$convert_id = Arr::get($boundary, 'kind') === 'milestone'
			? 0
			: (int) Arr::get($boundary, 'id', 0);

		return [
			'delete_ids' => $delete,
			'convert_id' => $convert_id,
			'convert_no' => (int) Arr::get($boundary, 'no', 0),
		];
	}

	/**
	 * Накатить историю до ревизии $target_no.
	 *
	 * Берём ближайший снимок (milestone) с no ≤ цели и применяем коммиты
	 * после него. Тот же алгоритм, что у restore(), но без БД: стенд и
	 * сжатие истории считают состояние на границе, не ходя в PostgreSQL.
	 */
	public static function replay_revisions(array $revisions, $target_no, array $start = NULL)
	{
		$target_no = (int) $target_no;
		$rows      = array_values($revisions);

		usort($rows, function ($a, $b) {
			return ((int) Arr::get($a, 'no', 0)) - ((int) Arr::get($b, 'no', 0));
		});

		$graph = $start !== NULL ? self::graph_clean($start) : self::graph_new('');
		$from  = 0;

		foreach ($rows as $rev)
		{
			$no = (int) Arr::get($rev, 'no', 0);

			if ($no > $target_no)
			{
				break;
			}

			if (Arr::get($rev, 'kind') !== 'milestone')
			{
				continue;
			}

			$snap = json_decode((string) Arr::get($rev, 'graph_json', ''), TRUE);

			if (is_array($snap))
			{
				$graph = self::graph_clean($snap);
				$from  = $no;
			}
		}

		foreach ($rows as $rev)
		{
			$no = (int) Arr::get($rev, 'no', 0);

			if ($no <= $from OR $no > $target_no)
			{
				continue;
			}

			if (Arr::get($rev, 'kind') === 'milestone')
			{
				$snap = json_decode((string) Arr::get($rev, 'graph_json', ''), TRUE);

				if (is_array($snap))
				{
					$graph = self::graph_clean($snap);
				}

				continue;
			}

			$ops   = json_decode((string) Arr::get($rev, 'patch_json', '[]'), TRUE);
			$apply = self::patch_apply($graph, is_array($ops) ? $ops : []);

			if ( ! Arr::get($apply, 'ok'))
			{
				return [
					'ok'    => FALSE,
					'error' => 'Патч '.$no.' не накатывается: '.Arr::get($apply, 'error', ''),
					'graph' => $graph,
				];
			}

			$graph = Arr::get($apply, 'graph');
		}

		return ['ok' => TRUE, 'graph' => $graph];
	}

	// -------------------------------------------------- чистые: RFC 6902

	/**
	 * Разность двух графов → операции RFC 6902.
	 *
	 * Порядок операций важен и повторяет жизнь: сначала удаления (с конца
	 * списка — индексы не поедут), потом правки полей по их текущим индексам,
	 * потом добавления в конец. Так патч остаётся применимым к тому графу,
	 * от которого считан.
	 */
	public static function patch_diff($before, $after)
	{
		$before = self::graph_clean($before);
		$after  = self::graph_clean($after);

		$ops       = [];
		$meta_from = (array) Arr::get($before, 'meta', []);
		$meta_to   = (array) Arr::get($after, 'meta', []);

		foreach (array_keys($meta_to + $meta_from) as $key)
		{
			$was = Arr::get($meta_from, $key);
			$now = Arr::get($meta_to, $key);

			if (is_scalar($was) AND is_scalar($now) AND (string) $was !== (string) $now)
			{
				$ops[] = ['op' => 'replace', 'path' => '/meta/'.$key, 'value' => $now];
			}
		}

		foreach (['nodes', 'edges'] as $section)
		{
			$ops = array_merge($ops, self::_diff_section(
				(array) Arr::get($before, $section, []),
				(array) Arr::get($after, $section, []),
				'/'.$section
			));
		}

		return $ops;
	}

	/** Разность одного списка (узлы или ребра) с идентификаторами. */
	protected static function _diff_section(array $before, array $after, $base)
	{
		$ops    = [];
		$state  = array_values($before);
		$by_id  = [];

		foreach ($after as $row)
		{
			$by_id[(string) Arr::get($row, 'id', '')] = $row;
		}

		// 1. Удаления — с конца, чтобы индексы живых строк не поехали.
		for ($i = count($state) - 1; $i >= 0; $i--)
		{
			$id = (string) Arr::get($state[$i], 'id', '');

			if ( ! isset($by_id[$id]))
			{
				$ops[] = ['op' => 'remove', 'path' => $base.'/'.$i];

				array_splice($state, $i, 1);
			}
		}

		// 2. Правки полей по индексам уже почищенного списка.
		foreach ($state as $index => $row)
		{
			$new = Arr::get($by_id, (string) Arr::get($row, 'id', ''));

			if ( ! is_array($new))
			{
				continue;
			}

			foreach ($new as $field => $value)
			{
				$old = Arr::get($row, $field);

				if ($field === 'data')
				{
					$ops = array_merge($ops, self::_diff_assoc(
						(array) $old, (array) $value, $base.'/'.$index.'/data'));

					continue;
				}

				if (is_scalar($value) AND is_scalar($old) AND (string) $value !== (string) $old)
				{
					$ops[] = ['op' => 'replace', 'path' => $base.'/'.$index.'/'.$field, 'value' => $value];
				}
			}
		}

		// 3. Добавления — в конец, в порядке нового графа.
		$have = [];

		foreach ($state as $row)
		{
			$have[(string) Arr::get($row, 'id', '')] = TRUE;
		}

		foreach ($after as $row)
		{
			$id = (string) Arr::get($row, 'id', '');

			if ( ! isset($have[$id]))
			{
				$ops[] = ['op' => 'add', 'path' => $base.'/-', 'value' => $row];
			}
		}

		return $ops;
	}

	/** Разность ассоциативного блока (data узла): скалярные ключи. */
	protected static function _diff_assoc(array $before, array $after, $base)
	{
		$ops = [];

		foreach (array_keys($after + $before) as $key)
		{
			$was = Arr::get($before, $key);
			$now = Arr::get($after, $key);

			if (is_scalar($was) AND is_scalar($now) AND (string) $was !== (string) $now)
			{
				$ops[] = ['op' => 'replace', 'path' => $base.'/'.$key, 'value' => $now];
			}
			elseif ($was === NULL AND is_scalar($now))
			{
				$ops[] = ['op' => 'add', 'path' => $base.'/'.$key, 'value' => $now];
			}
			elseif ($now === NULL AND is_scalar($was))
			{
				$ops[] = ['op' => 'remove', 'path' => $base.'/'.$key];
			}
		}

		return $ops;
	}

	/**
	 * Применить патч RFC 6902 к графу.
	 *
	 * Поддержаны add / replace / remove (и move — он нужен, если узлы
	 * переупорядочили). test/copy не поддерживаем: они не нужны и только
	 * расширяют поверхность для сюрпризов.
	 */
	public static function patch_apply($graph, $ops)
	{
		$graph = is_array($graph) ? $graph : [];
		$ops   = is_array($ops) ? $ops : [];

		foreach ($ops as $i => $op)
		{
			if ( ! is_array($op))
			{
				return ['ok' => FALSE, 'error' => 'Операция '.$i.' не объект.', 'graph' => $graph];
			}

			$kind = (string) Arr::get($op, 'op', '');
			$path = (string) Arr::get($op, 'path', '');
			$parts = self::pointer_parts($path);

			if ($parts === NULL)
			{
				return ['ok' => FALSE, 'error' => 'Путь «'.$path.'» не по RFC 6901.', 'graph' => $graph];
			}

			if ($kind === 'move')
			{
				$from = self::pointer_parts((string) Arr::get($op, 'from', ''));

				if ($from === NULL)
				{
					return ['ok' => FALSE, 'error' => 'В move нет «from».', 'graph' => $graph];
				}

				try
				{
					$value = self::_pointer_get($graph, $from);
					$graph = self::_node_apply($graph, 'remove', $from, NULL);
					$graph = self::_node_apply($graph, 'add', $parts, $value);
				}
				catch (Exception $e)
				{
					return ['ok' => FALSE, 'error' => $e->getMessage(), 'graph' => $graph];
				}

				continue;
			}

			if ( ! in_array($kind, ['add', 'replace', 'remove'], TRUE))
			{
				return ['ok' => FALSE, 'error' => 'Операция «'.$kind.'» не поддерживается.', 'graph' => $graph];
			}

			try
			{
				$graph = self::_node_apply($graph, $kind, $parts, Arr::get($op, 'value'));
			}
			catch (Exception $e)
			{
				return ['ok' => FALSE, 'error' => $e->getMessage(), 'graph' => $graph];
			}
		}

		return ['ok' => TRUE, 'graph' => self::graph_clean($graph)];
	}

	/** Путь RFC 6901 → список ключей. NULL — путь не по стандарту. */
	public static function pointer_parts($path)
	{
		$path = (string) $path;

		if ($path === '')
		{
			return [];
		}

		if ($path[0] !== '/')
		{
			return NULL;
		}

		$parts = explode('/', UTF8::substr($path, 1));

		foreach ($parts as $i => $part)
		{
			$parts[$i] = str_replace(['~1', '~0'], ['/', '~'], $part);
		}

		return $parts;
	}

	/** Значение по указателю (для move). NULL, если пути нет. */
	protected static function _pointer_get($node, array $parts)
	{
		foreach ($parts as $key)
		{
			if (is_array($node))
			{
				if ( ! array_key_exists($key, $node))
				{
					return NULL;
				}

				$node = $node[$key];

				continue;
			}

			return NULL;
		}

		return $node;
	}

	/** Одна операция: рекурсивный спуск по указателю. */
	protected static function _node_apply($node, $kind, array $parts, $value)
	{
		if ( ! $parts)
		{
			if ($kind === 'remove')
			{
				return [];
			}

			return $value;
		}

		$key  = array_shift($parts);
		$list = is_array($node) && self::_is_list($node);

		if ($list)
		{
			if ($key === '-')
			{
				if ($kind === 'remove')
				{
					throw new Exception('«-» удалять нельзя.');
				}

				$node[] = $value;

				return $node;
			}

			if ( ! ctype_digit((string) $key))
			{
				throw new Exception('Индекс «'.$key.'» не число.');
			}

			$index = (int) $key;

			if ($kind === 'add')
			{
				array_splice($node, min($index, count($node)), 0, [$value]);

				return $node;
			}

			if ($index < 0 OR $index >= count($node))
			{
				throw new Exception('Нет элемента с номером '.$index.'.');
			}

			if ( ! $parts)
			{
				if ($kind === 'remove')
				{
					array_splice($node, $index, 1);

					return $node;
				}

				$node[$index] = $value;

				return $node;
			}

			$node[$index] = self::_node_apply($node[$index], $kind, $parts, $value);

			return $node;
		}

		if ( ! is_array($node))
		{
			throw new Exception('Путь идёт внутрь значения, а не объекта.');
		}

		if ( ! $parts)
		{
			if ($kind === 'remove')
			{
				if ( ! array_key_exists($key, $node))
				{
					throw new Exception('Нет поля «'.$key.'».');
				}

				unset($node[$key]);

				return $node;
			}

			$node[$key] = $value;

			return $node;
		}

		if ( ! array_key_exists($key, $node))
		{
			if ($kind === 'remove')
			{
				throw new Exception('Нет поля «'.$key.'».');
			}

			$node[$key] = [];
		}

		$node[$key] = self::_node_apply($node[$key], $kind, $parts, $value);

		return $node;
	}

	/** TRUE, если массив — список (ключи 0..n-1 по порядку). */
	protected static function _is_list(array $array)
	{
		return $array === [] OR array_keys($array) === range(0, count($array) - 1);
	}

	/**
	 * Склейка: новый граф накладывается поверх текущего.
	 *
	 * Узлы ищем по id: совпал — обновляем поля, нет — добавляем. Так модель,
	 * приславшая «подлокотник» с новым id, не стирает весь стул.
	 */
	public static function graph_merge(array $current, array $incoming)
	{
		$current  = self::graph_clean($current);
		$incoming = self::graph_clean($incoming);

		$nodes = (array) Arr::get($current, 'nodes', []);
		$index = [];

		foreach ($nodes as $i => $node)
		{
			$index[(string) Arr::get($node, 'id')] = $i;
		}

		foreach ((array) Arr::get($incoming, 'nodes', []) as $node)
		{
			$id = (string) Arr::get($node, 'id');

			if (isset($index[$id]))
			{
				$nodes[$index[$id]] = $node;
			}
			else
			{
				$index[$id] = count($nodes);
				$nodes[]    = $node;
			}
		}

		$edges = (array) Arr::get($current, 'edges', []);
		$index = [];

		foreach ($edges as $i => $edge)
		{
			$index[(string) Arr::get($edge, 'id')] = $i;
		}

		foreach ((array) Arr::get($incoming, 'edges', []) as $edge)
		{
			$id = (string) Arr::get($edge, 'id');

			if (isset($index[$id]))
			{
				$edges[$index[$id]] = $edge;
			}
			else
			{
				$index[$id] = count($edges);
				$edges[]    = $edge;
			}
		}

		return self::graph_clean([
			'meta'  => Arr::get($incoming, 'meta', Arr::get($current, 'meta', [])),
			'nodes' => $nodes,
			'edges' => $edges,
		]);
	}

	/**
	 * Превью графа картинкой: изометрия 30° (x/z — плоскость, y — высота).
	 * $night — ночная палитра холста; днём белый фон. Никакой модели: каталог
	 * обязан открываться, даже если воркер лежит (владелец, 11.10.2026).
	 */
	public static function svg_preview(array $graph, $width = 480, $height = 320, $night = FALSE)
	{
		$graph = self::graph_clean($graph);
		$nodes = (array) Arr::get($graph, 'nodes', []);

		$width  = max(80, (int) $width);
		$height = max(60, (int) $height);
		$pad    = 18;
		$night  = ($night === TRUE OR $night === 'night' OR $night === 'dark');

		$bg     = $night ? '#0f172a' : '#ffffff';
		$grid_c = $night ? '#1e293b' : '#e2e8f0';
		$stem_c = $night ? '#334155' : '#94a3b8';
		$mute   = $night ? '#94a3b8' : '#64748b';
		$ring   = $night ? '#475569' : '#64748b';

		// Классическая изометрия: равные углы, cos 30° / sin 30°.
		$cos = 0.8660254;
		$sin = 0.5;
		$iso = function ($x, $y, $z) use ($cos, $sin)
		{
			return array(($x - $z) * $cos, ($x + $z) * $sin - $y);
		};

		$out = ['<?xml version="1.0" encoding="UTF-8"?>',
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' '.$height.'" '
				.'width="'.$width.'" height="'.$height.'" role="img" data-view="isometric" '
				.'data-theme="'.($night ? 'night' : 'day').'" '
				.'aria-label="Схема графа: '.count($nodes).' узлов">',
			'<rect width="'.$width.'" height="'.$height.'" fill="'.$bg.'"/>'];

		if ( ! $nodes)
		{
			$out[] = '<text x="'.(int) ($width / 2).'" y="'.(int) ($height / 2).'" fill="'.$mute.'" '
				.'font-size="14" text-anchor="middle">граф пуст</text>';
			$out[] = '</svg>';

			return implode("\n", $out);
		}

		$by_id = [];
		$pts   = [];
		$minx = $maxx = $miny = $maxy = $minz = $maxz = NULL;

		foreach ($nodes as $node)
		{
			$x = (float) Arr::get($node, 'x', 0);
			$y = (float) Arr::get($node, 'y', 0);
			$z = (float) Arr::get($node, 'z', 0);
			list($u, $v) = $iso($x, $y, $z);
			$pts[] = array($u, $v, $node, $x, $y, $z);
			$by_id[(string) Arr::get($node, 'id')] = $node;
			$minx = $minx === NULL ? $x : min($minx, $x);
			$maxx = $maxx === NULL ? $x : max($maxx, $x);
			$miny = $miny === NULL ? $y : min($miny, $y);
			$maxy = $maxy === NULL ? $y : max($maxy, $y);
			$minz = $minz === NULL ? $z : min($minz, $z);
			$maxz = $maxz === NULL ? $z : max($maxz, $z);
		}

		$ground = $miny;
		$span_x = max(6.0, $maxx - $minx);
		$span_z = max(6.0, $maxz - $minz);
		$mid_x  = ($minx + $maxx) / 2.0;
		$mid_z  = ($minz + $maxz) / 2.0;
		$gx0    = $mid_x - $span_x / 2.0;
		$gx1    = $mid_x + $span_x / 2.0;
		$gz0    = $mid_z - $span_z / 2.0;
		$gz1    = $mid_z + $span_z / 2.0;

		$all_u = [];
		$all_v = [];

		foreach ($pts as $p)
		{
			$all_u[] = $p[0];
			$all_v[] = $p[1];
		}

		foreach (array(array($gx0, $gz0), array($gx1, $gz0), array($gx0, $gz1), array($gx1, $gz1)) as $corner)
		{
			list($u, $v) = $iso($corner[0], $ground, $corner[1]);
			$all_u[] = $u;
			$all_v[] = $v;
		}

		$umin = min($all_u);
		$umax = max($all_u);
		$vmin = min($all_v);
		$vmax = max($all_v);
		$du   = max(0.001, $umax - $umin);
		$dv   = max(0.001, $vmax - $vmin);
		$iw   = $width - 2 * $pad;
		$ih   = $height - 2 * $pad;
		$scale = min($iw / $du, $ih / $dv);
		$ox    = $pad + ($iw - $du * $scale) / 2.0 - $umin * $scale;
		$oy    = $pad + ($ih - $dv * $scale) / 2.0 - $vmin * $scale;

		$fit = function ($u, $v) use ($scale, $ox, $oy)
		{
			return array(round($ox + $u * $scale, 1), round($oy + $v * $scale, 1));
		};

		$steps = 5;

		for ($i = 0; $i <= $steps; $i++)
		{
			$t = $i / $steps;
			$x = $gx0 + ($gx1 - $gx0) * $t;
			$z = $gz0 + ($gz1 - $gz0) * $t;
			list($u1, $v1) = $iso($x, $ground, $gz0);
			list($u2, $v2) = $iso($x, $ground, $gz1);
			list($x1, $y1) = $fit($u1, $v1);
			list($x2, $y2) = $fit($u2, $v2);
			$out[] = '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="'.$grid_c.'" stroke-width="1"/>';
			list($u1, $v1) = $iso($gx0, $ground, $z);
			list($u2, $v2) = $iso($gx1, $ground, $z);
			list($x1, $y1) = $fit($u1, $v1);
			list($x2, $y2) = $fit($u2, $v2);
			$out[] = '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="'.$grid_c.'" stroke-width="1"/>';
		}

		foreach ($pts as $p)
		{
			if (abs($p[4] - $ground) < 0.08)
			{
				continue;
			}

			list($ug, $vg) = $iso($p[3], $ground, $p[5]);
			list($sx, $sy) = $fit($p[0], $p[1]);
			list($gx, $gy) = $fit($ug, $vg);
			$out[] = '<line x1="'.$gx.'" y1="'.$gy.'" x2="'.$sx.'" y2="'.$sy.'" stroke="'.$stem_c.'" '
				.'stroke-width="1" stroke-opacity="0.55"/>';
		}

		foreach ((array) Arr::get($graph, 'edges', []) as $edge)
		{
			$from = Arr::get($by_id, (string) Arr::get($edge, 'source'));
			$to   = Arr::get($by_id, (string) Arr::get($edge, 'target'));

			if ($from === NULL OR $to === NULL)
			{
				continue;
			}

			$color = (string) Arr::get(Arr::get(Service_Nj667_Graph3d::edge_types(), (string) Arr::get($edge, 'type', ''), []),
				'color', '#94a3b8');
			list($u1, $v1) = $iso((float) Arr::get($from, 'x', 0), (float) Arr::get($from, 'y', 0), (float) Arr::get($from, 'z', 0));
			list($u2, $v2) = $iso((float) Arr::get($to, 'x', 0), (float) Arr::get($to, 'y', 0), (float) Arr::get($to, 'z', 0));
			list($x1, $y1) = $fit($u1, $v1);
			list($x2, $y2) = $fit($u2, $v2);
			$out[] = '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" '
				.'stroke="'.HTML::chars($color).'" stroke-width="1.6" stroke-opacity="0.85"/>';
		}

		usort($pts, function ($a, $b)
		{
			$da = $a[3] + $a[5];
			$db = $b[3] + $b[5];

			if ($da === $db)
			{
				return ($a[4] < $b[4]) ? -1 : 1;
			}

			return ($da < $db) ? -1 : 1;
		});

		foreach ($pts as $p)
		{
			$node = $p[2];
			list($cx, $cy) = $fit($p[0], $p[1]);
			$type = (string) Arr::get($node, 'type', '');
			$base = (string) Arr::get(Arr::get(Service_Nj667_Graph3d::node_types(), $type, []), 'color', '#6366f1');
			$size = $type === 'hub' ? 7 : ($type === 'panel' ? 6 : 5);
			$out[] = '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$size.'" fill="'.HTML::chars($base).'" '
				.'stroke="'.$ring.'" stroke-width="1"/>';
		}

		$out[] = '</svg>';

		return implode("\n", $out);
	}
}
