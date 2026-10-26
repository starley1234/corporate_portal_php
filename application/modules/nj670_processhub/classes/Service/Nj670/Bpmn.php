<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Импорт BPMN 2.0 XML: схемы из Storm, Camunda, Bizagi, draw.io.
 *
 * Читаем семантику, а не картинку: process/laneSet, задачи, шлюзы и
 * sequenceFlow. bpmndi (координаты) используем, если он есть и осмысленный;
 * иначе раскладываем схему сами — как при синтезе. Неизвестные типы не
 * теряются: они становятся пользовательской задачей с пометкой в properties.
 *
 * Разбор собственный, на XMLReader: на хосте и в php-wasm доступен libxml,
 * а SimpleXML/DOM тянут весь документ в память — для регламента на мегабайт
 * это лишнее. Тест вызывает xml_to_graph() напрямую, без БД.
 */
class Service_Nj670_Bpmn extends Service_Nj670_Repo
{
	const NS_BPMN = 'http://www.omg.org/spec/BPMN/20100524/MODEL';
	const NS_BPMNDI = 'http://www.omg.org/spec/BPMN/20100524/DI';
	const NS_DC = 'http://www.omg.org/spec/DD/20100524/DC';
	const NS_DI = 'http://www.omg.org/spec/DD/20100524/DI';

	protected static $_task_types = [
		'task' => 'user_task', 'userTask' => 'user_task', 'manualTask' => 'manual_task',
		'serviceTask' => 'service_task', 'scriptTask' => 'service_task',
		'sendTask' => 'service_task', 'receiveTask' => 'user_task',
		'businessRuleTask' => 'service_task', 'callActivity' => 'subprocess',
		'subProcess' => 'subprocess', 'adHocSubProcess' => 'subprocess',
	];

	protected static $_event_types = [
		'startEvent' => 'start', 'endEvent' => 'end',
		'intermediateCatchEvent' => 'timer', 'intermediateThrowEvent' => 'user_task',
		'boundaryEvent' => 'timer',
	];

	protected static $_gateway_types = [
		'exclusiveGateway' => 'gateway_xor', 'parallelGateway' => 'gateway_and',
		'inclusiveGateway' => 'gateway_xor', 'complexGateway' => 'gateway_xor',
		'eventBasedGateway' => 'gateway_xor',
	];

	/**
	 * XML → граф ProcessHub. Без БД: ontology — готовый индекс.
	 *
	 * @return array{ok:bool,process:array,graph:array,warnings:array,stats:array}
	 */
	public static function xml_to_graph($xml, array $ontology = [])
	{
		$xml = trim((string) $xml);

		if ($xml === '')
		{
			return self::_fail('Файл пуст.');
		}

		if (stripos($xml, '<bpmn:definitions') === FALSE AND stripos($xml, '<definitions') === FALSE)
		{
			return self::_fail('Это не BPMN 2.0: нет элемента definitions. Подойдёт .bpmn или .xml из Storm/Camunda.');
		}

		$reader = new XMLReader();

		if ( ! @$reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING))
		{
			return self::_fail('XML повреждён: не удалось прочитать документ.');
		}

		$state = [
			'process'   => ['id' => '', 'name' => '', 'documentation' => ''],
			'lanes'     => [],
			'lane_stack'=> [],
			// Аннотации Storm/draw.io: текстовый комментарий + association.
			'annotations'=> [],
			'annotation_id' => '',
			'associations' => [],
			'nodes'     => [],
			'node_refs' => [],
			'flows'     => [],
			'shapes'    => [],
			'edges_di'  => [],
			'found'     => FALSE,
			'warnings'  => [],
			'depth'     => 0,
			'process_depth' => -1,
		];

		$seen_process = FALSE;

		while ( @$reader->read())
		{
			if ($reader->nodeType === XMLReader::ELEMENT)
			{
				$local = $reader->localName;
				$depth = $reader->depth;

				if ($local === 'process' AND ! $seen_process)
				{
					$seen_process = TRUE;
					$state['found'] = TRUE;
					$state['process_depth'] = $depth;
					$state['process']['id'] = (string) $reader->getAttribute('id');
					$state['process']['name'] = (string) $reader->getAttribute('name');

					continue;
				}

				if ($state['process_depth'] < 0)
				{
					continue;
				}

				// Всё, что глубже процесса, — семантика схемы.
				if ($local === 'documentation' AND $state['process']['documentation'] === '')
				{
					$state['process']['documentation'] = UTF8::substr(trim((string) $reader->readString()), 0, 2000);
					continue;
				}

				if ($local === 'lane' OR $local === 'participant')
				{
					$lane_id = self::_id((string) $reader->getAttribute('id'));

					if ($lane_id !== '' AND ! isset($state['lanes'][$lane_id]))
					{
						$state['lanes'][$lane_id] = [
							'id'    => $lane_id,
							'name'  => trim((string) $reader->getAttribute('name')),
							'roles' => [],
						];
					}

					// Держим стек: flowNodeRef относится к ближайшей открытой
					// дорожке, а не к последней в массиве (в laneSet их много).
					$state['lane_stack'][] = $lane_id;

					continue;
				}

				if ($local === 'textAnnotation')
				{
					$annotation_id = self::_id((string) $reader->getAttribute('id'));

					if ($annotation_id !== '')
					{
						$state['annotation_id'] = $annotation_id;
						$state['annotations'][$annotation_id] = '';
					}

					continue;
				}

				if ($local === 'text' AND $state['annotation_id'] !== '')
				{
					$text = trim((string) $reader->readString());
					$state['annotations'][$state['annotation_id']] = UTF8::substr($text, 0, 500);

					continue;
				}

				if ($local === 'association')
				{
					$state['associations'][] = [
						'source' => self::_id((string) $reader->getAttribute('sourceRef')),
						'target' => self::_id((string) $reader->getAttribute('targetRef')),
					];

					continue;
				}

				if ($local === 'flowNodeRef')
				{
					$ref = self::_id(trim((string) $reader->readString()));
					$lane_id = end($state['lane_stack']);

					if ($ref !== '' AND $lane_id AND isset($state['lanes'][$lane_id]))
					{
						$state['lanes'][$lane_id]['roles'][$ref] = TRUE;
					}

					continue;
				}

				if (isset(self::$_task_types[$local]))
				{
					$state['nodes'][] = [
						'id'     => self::_id((string) $reader->getAttribute('id')),
						'type'   => self::$_task_types[$local],
						'name'   => trim((string) $reader->getAttribute('name')),
						'source' => $local,
						'lane'   => self::_lane_hint($reader),
						'called' => self::_id((string) $reader->getAttribute('calledElement')),
					];

					continue;
				}

				if (isset(self::$_event_types[$local]))
				{
					$state['nodes'][] = [
						'id'     => self::_id((string) $reader->getAttribute('id')),
						'type'   => self::$_event_types[$local],
						'name'   => trim((string) $reader->getAttribute('name')),
						'source' => $local,
						'lane'   => '',
					];

					continue;
				}

				if (isset(self::$_gateway_types[$local]))
				{
					$state['nodes'][] = [
						'id'     => self::_id((string) $reader->getAttribute('id')),
						'type'   => self::$_gateway_types[$local],
						'name'   => trim((string) $reader->getAttribute('name')),
						'source' => $local,
						'lane'   => '',
					];

					continue;
				}

				if ($local === 'sequenceFlow')
				{
					$state['flows'][] = [
						'id'     => self::_id((string) $reader->getAttribute('id')),
						'from'   => self::_id((string) $reader->getAttribute('sourceRef')),
						'to'     => self::_id((string) $reader->getAttribute('targetRef')),
						'name'   => trim((string) $reader->getAttribute('name')),
						'condition' => '',
					];

					continue;
				}

				if ($local === 'conditionExpression' AND $state['flows'])
				{
					$condition = trim((string) $reader->readString());
					$keys = array_keys($state['flows']);
					$key = end($keys);
					$state['flows'][$key]['condition'] = UTF8::substr($condition, 0, 300);
				}
			}
			elseif ($reader->nodeType === XMLReader::END_ELEMENT)
			{
				if ($reader->localName === 'textAnnotation')
				{
					$state['annotation_id'] = '';
				}

				if (($reader->localName === 'lane' OR $reader->localName === 'participant')
					AND $state['lane_stack'])
				{
					array_pop($state['lane_stack']);
				}

				if ($reader->localName === 'process' AND $reader->depth === $state['process_depth'])
				{
					// Первый процесс — основной; дальше только пулы/вложенность.
					$state['process_depth'] = -2;
				}
			}
		}

		$reader->close();

		if ( ! $state['found'])
		{
			return self::_fail('В файле нет bpmn:process — импортировать нечего.');
		}

		$bpmndi = self::_read_di($xml);

		if ( ! empty($bpmndi['warnings']))
		{
			$state['warnings'] = array_merge($state['warnings'], $bpmndi['warnings']);
		}

		// Дорожка → роль: имя дорожки ищем в справочнике, иначе создаём
		// ссылку по коду и оставляем подпись в properties.role_label.
		$lanes = [];
		$lane_of_node = [];

		foreach ($state['lanes'] as $lane_id => $lane)
		{
			$name = $lane['name'] !== '' ? $lane['name'] : $lane_id;
			$role = Service_Nj670_Ontology::match_index($ontology, 'role', $name);
			$lanes[] = [
				'id'        => $lane_id,
				'title'     => UTF8::substr($name, 0, 120),
				'role_code' => $role,
			];

			foreach (array_keys($lane['roles']) as $ref)
			{
				$lane_of_node[$ref] = $lane_id;
			}
		}

		$nodes = [];
		$used = [];

		foreach ($state['nodes'] as $node)
		{
			if ($node['id'] === '')
			{
				$state['warnings'][] = 'Пропущен элемент без id ('.$node['source'].').';
				continue;
			}

			if (isset($used[$node['id']]))
			{
				continue;
			}

			$used[$node['id']] = TRUE;
			$lane_id = isset($lane_of_node[$node['id']]) ? $lane_of_node[$node['id']] : (string) $node['lane'];
			$lane_row = NULL;

			foreach ($lanes as $lane)
			{
				if ($lane['id'] === $lane_id)
				{
					$lane_row = $lane;
					break;
				}
			}

			$title = $node['name'];

			if ($title === '')
			{
				$title = Service_Nj670_Graph::node_type_label($node['type']);
			}

			$coords = isset($bpmndi['shapes'][$node['id']]) ? $bpmndi['shapes'][$node['id']] : NULL;
			$properties = ['bpmn' => $node['source']];

			if ( ! empty($node['called']))
			{
				$properties['process_code'] = $node['called'];
			}

			if ($lane_row AND $lane_row['role_code'] === '' AND $lane_row['title'] !== '')
			{
				$properties['role_label'] = $lane_row['title'];
			}

			if ($coords)
			{
				$properties['bpmn_x'] = (int) round($coords['x']);
				$properties['bpmn_y'] = (int) round($coords['y']);
			}

			$nodes[] = [
				'id'         => $node['id'],
				'type'       => $node['type'],
				'title'      => $title,
				'lane'       => $lane_id,
				'role_code'  => $lane_row ? $lane_row['role_code'] : '',
				'input_code' => '',
				'output_code'=> '',
				'properties' => $properties,
				'x'          => $coords ? (int) round($coords['x']) : 0,
				'y'          => $coords ? (int) round($coords['y']) : 0,
			];
		}

		// Комментарий из аннотации: association связывает её с задачей. Если
		// связь не найдена, не догадываемся — предупреждаем и не теряем текст
		// молча (он остался в исходном файле).
		$node_index = [];

		foreach ($nodes as $index => $node)
		{
			$node_index[$node['id']] = $index;
		}

		foreach ($state['associations'] as $association)
		{
			$source = $association['source'];
			$target = $association['target'];

			if (isset($state['annotations'][$source]))
			{
				$annotation = $source;
				$node_id = $target;
			}
			elseif (isset($state['annotations'][$target]))
			{
				$annotation = $target;
				$node_id = $source;
			}
			else
			{
				continue;
			}

			$text = trim((string) Arr::get($state['annotations'], $annotation, ''));

			if ($text === '')
			{
				continue;
			}

			if ( ! isset($node_index[$node_id]))
			{
				$state['warnings'][] = 'Аннотация ссылается на неизвестный элемент — пропущена.';
				continue;
			}

			$index = $node_index[$node_id];
			$existing = trim((string) Arr::get($nodes[$index]['properties'], 'comment', ''));
			$nodes[$index]['properties']['comment'] = UTF8::substr(
				$existing === '' ? $text : $existing."\n\n".$text, 0, 500);
		}

		$edges = [];
		$edge_seen = [];

		foreach ($state['flows'] as $flow)
		{
			if ($flow['from'] === '' OR $flow['to'] === '' OR $flow['from'] === $flow['to'])
			{
				continue;
			}

			if ( ! isset($used[$flow['from']]) OR ! isset($used[$flow['to']]))
			{
				$state['warnings'][] = 'Связь ведёт на неизвестный элемент — пропущена.';
				continue;
			}

			$key = $flow['from'].'>'.$flow['to'];

			if (isset($edge_seen[$key]))
			{
				continue;
			}

			$edge_seen[$key] = TRUE;
			$edges[] = [
				'id'        => $flow['id'] !== '' ? $flow['id'] : 'e'.count($edges),
				'from'      => $flow['from'],
				'to'        => $flow['to'],
				'type'      => $flow['condition'] !== '' ? 'conditional' : 'sequence',
				'condition' => $flow['condition'] !== '' ? $flow['condition'] : $flow['name'],
			];
		}

		$graph = [
			'lanes' => $lanes,
			'nodes' => $nodes,
			'edges' => $edges,
			'meta'  => [
				'title'  => $state['process']['name'],
				'source' => 'bpmn',
				'mode'   => 'plan',
			],
		];

		if (empty($nodes))
		{
			return self::_fail('В BPMN-файле нет ни одной задачи или события.');
		}

		return [
			'ok'       => TRUE,
			'process'  => [
				'title'       => UTF8::substr($state['process']['name'], 0, 255),
				'description' => $state['process']['documentation'],
			],
			'graph'    => $graph,
			'warnings' => array_slice(array_values(array_unique($state['warnings'])), 0, 20),
			'stats'    => [
				'nodes'  => count($nodes),
				'edges'  => count($edges),
				'lanes'  => count($lanes),
				'layout' => ! empty($bpmndi['shapes']) ? 'bpmndi' : 'auto',
			],
		];
	}

	/**
	 * Импорт файла в новое рабочее пространство процесса.
	 *
	 * Создаём процесс и версию плана сразу «на рассмотрении»: схема из
	 * чужого редактора — это заявка, а не утверждённый регламент. Дорожки,
	 * которых нет в справочнике, остаются подписями в properties, пока
	 * владелец не заведёт роли.
	 */
	public static function import($user_id, array $file, array $opts = [])
	{
		$name = (string) Arr::get($file, 'name', '');
		$tmp  = (string) Arr::get($file, 'tmp_name', '');
		$size = (int) Arr::get($file, 'size', 0);
		$err  = (int) Arr::get($file, 'error', UPLOAD_ERR_NO_FILE);
		$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

		if ($err !== UPLOAD_ERR_OK OR $tmp === '' OR ! is_uploaded_file($tmp))
		{
			return ['ok' => FALSE, 'error' => 'Файл не принят. Подойдёт .bpmn или .xml из Storm, Camunda, Bizagi.'];
		}

		if ( ! in_array($ext, ['bpmn', 'xml'], TRUE))
		{
			return ['ok' => FALSE, 'error' => 'Формат .'.$ext.' не поддерживается: нужен .bpmn или .xml.'];
		}

		$max = 5 * 1024 * 1024;

		if ($size <= 0 OR $size > $max)
		{
			return ['ok' => FALSE, 'error' => 'Размер BPMN-файла — от 1 байта до 5 МБ.'];
		}

		$xml = (string) file_get_contents($tmp);

		if ($xml === '')
		{
			return ['ok' => FALSE, 'error' => 'Файл пуст.'];
		}

		$workspace = Service_Nj670_Process::workspace_for((int) $user_id);

		if ( ! $workspace)
		{
			return ['ok' => FALSE, 'error' => 'Рабочее пространство не найдено.'];
		}

		$parsed = self::xml_to_graph($xml, Service_Nj670_Ontology::index((int) $workspace['id']));

		if ( ! Arr::get($parsed, 'ok'))
		{
			return $parsed;
		}

		$title = trim((string) Arr::path($parsed, 'process.title', ''));

		if ($title === '')
		{
			$title = Service_Nj670_Repo::title_from_text('', pathinfo($name, PATHINFO_FILENAME));
		}

		$created = Service_Nj670_Process::create_process((int) $user_id, [
			'title'       => $title,
			'description' => (string) Arr::path($parsed, 'process.description', ''),
		]);

		if ( ! Arr::get($created, 'ok'))
		{
			return $created;
		}

		$graph = (array) $parsed['graph'];
		$graph['meta']['title'] = $title;
		$graph['meta']['source'] = 'bpmn';

		$version = Service_Nj670_Process::create_version((int) $created['id'], 'plan', [
			'status'      => Service_Nj670_Process::S_IN_REVIEW,
			'graph'       => $graph,
			'source_kind' => 'bpmn',
			'source_text' => 'Импорт BPMN: '.$name,
			'created_by'  => (int) $user_id,
			'model'       => 'bpmn-import',
			'activate'    => TRUE,
		]);

		if ( ! Arr::get($version, 'ok'))
		{
			return $version;
		}

		Service_Nj670_Process::_event((int) $created['id'], (int) $user_id, 'bpmn.imported',
			'Импортирован BPMN из «'.$name.'»: '.(int) Arr::path($parsed, 'stats.nodes', 0).' шагов');

		return [
			'ok'       => TRUE,
			'process'  => $created['process'],
			'version'  => $version['id'],
			'stats'    => (array) Arr::get($parsed, 'stats', []),
			'warnings' => (array) Arr::get($parsed, 'warnings', []),
		];
	}

	/**
	 * bpmndi: координаты фигур. Необязателен — без него схема раскладывается сама.
	 */
	protected static function _read_di($xml)
	{
		$shapes = [];
		$warnings = [];
		$plain = preg_replace('~xmlns(:\w+)?="[^"]*"~', '', (string) $xml);

		// Плоский разбор через регулярки: DI — это плоский список фигур,
		// XMLReader здесь усложнил бы код без выигрыша.
		if (preg_match_all('~<(?:\w+:)?BPMNShape\b[^>]*>.*?</(?:\w+:)?BPMNShape>~s', $plain, $matches))
		{
			foreach ($matches[0] as $chunk)
			{
				if ( ! preg_match('~bpmnElement="([^"]+)"~', $chunk, $element))
				{
					continue;
				}

				if ( ! preg_match('~<(?:\w+:)?Bounds\b([^>]*)/?>~', $chunk, $bounds))
				{
					continue;
				}

				$attrs = [];

				if (preg_match_all('~(\w+)="([^"]*)"~', $bounds[1], $pairs, PREG_SET_ORDER))
				{
					foreach ($pairs as $pair)
					{
						$attrs[$pair[1]] = $pair[2];
					}
				}

				if ( ! isset($attrs['x'], $attrs['y']))
				{
					continue;
				}

				$shapes[self::_id($element[1])] = [
					'x' => (float) $attrs['x'],
					'y' => (float) $attrs['y'],
					'w' => isset($attrs['width']) ? (float) $attrs['width'] : 0,
					'h' => isset($attrs['height']) ? (float) $attrs['height'] : 0,
				];
			}
		}

		$all_origin = count($shapes) > 2;
		$non_zero = 0;

		foreach ($shapes as $shape)
		{
			if ($shape['x'] != 0 OR $shape['y'] != 0)
			{
				$non_zero++;
			}
		}

		if ($all_origin AND $non_zero < 2)
		{
			// Экспортёры без реальной геометрии ставят всё в 0 — раскладываем сами.
			$warnings[] = 'Координаты BPMN пустые — схема разложена автоматически.';
			$shapes = [];
		}

		return ['shapes' => $shapes, 'warnings' => $warnings];
	}

	protected static function _lane_hint(XMLReader $reader)
	{
		// В некоторых экспортерах дорожка видна только по вложенности тегов.
		// Полноценно это ловит flowNodeRef; здесь — запасной вариант по атрибуту.
		$lane = (string) $reader->getAttribute('lane');

		return $lane !== '' ? self::_id($lane) : '';
	}

	protected static function _id($value)
	{
		$value = trim((string) $value);

		if ($value === '')
		{
			return '';
		}

		$value = preg_replace('~[^a-zA-Z0-9_\-.]+~', '_', $value);

		return UTF8::substr(trim($value, '_-.'), 0, 60);
	}

	protected static function _fail($error)
	{
		return ['ok' => FALSE, 'error' => $error, 'warnings' => [], 'graph' => []];
	}
}
