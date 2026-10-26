<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сравнение двух версий схемы: что появилось, что исчезло, что изменилось.
 *
 * Зачем отдельно от Diff: Diff сравнивает план с фактом (разные процессы
 * исполнения), а здесь — две редакции одного контура. Шаг сопоставляем по
 * step_id: он стабилен при переименовании, поэтому «Юрист проверяет договор»
 * → «Юрист проверяет договор и визу» — это изменение, а не удаление+создание.
 *
 * Всё считается по графам, без БД: тест вызывает compare() напрямую.
 */
class Service_Nj670_Compare extends Service_Nj670_Repo
{
	/**
	 * @return array{added:array,removed:array,changed:array,edges:array,summary:array}
	 */
	public static function compare(array $before, array $after)
	{
		$a = self::_index($before);
		$b = self::_index($after);

		$added = [];
		$removed = [];
		$changed = [];

		foreach ($b['nodes'] as $step => $node)
		{
			if ( ! isset($a['nodes'][$step]))
			{
				$added[] = self::_row($node);
				continue;
			}

			$diff = self::_node_diff($a['nodes'][$step], $node);

			if ($diff)
			{
				$before_node = $a['nodes'][$step];
				$row = self::_row($node);
				$row['before'] = $diff['before'];
				$row['after'] = $diff['after'];
				$row['fields'] = $diff['fields'];
				$changed[] = $row;
			}
		}

		foreach ($a['nodes'] as $step => $node)
		{
			if ( ! isset($b['nodes'][$step]))
			{
				$removed[] = self::_row($node);
			}
		}

		$edges_added = [];
		$edges_removed = [];

		foreach ($b['edges'] as $key => $edge)
		{
			if ( ! isset($a['edges'][$key]))
			{
				$edges_added[] = $edge;
			}
		}

		foreach ($a['edges'] as $key => $edge)
		{
			if ( ! isset($b['edges'][$key]))
			{
				$edges_removed[] = $edge;
			}
		}

		$minutes_before = self::_sum($a['nodes'], 'duration_minutes');
		$minutes_after = self::_sum($b['nodes'], 'duration_minutes');
		$wait_before = self::_sum($a['nodes'], 'wait_time_minutes');
		$wait_after = self::_sum($b['nodes'], 'wait_time_minutes');

		return [
			'added'   => $added,
			'removed' => $removed,
			'changed' => $changed,
			'edges'   => [
				'added'   => $edges_added,
				'removed' => $edges_removed,
				'common'  => max(0, count($b['edges']) - count($edges_added)),
			],
			'summary' => [
				'nodes_before'  => count($a['nodes']),
				'nodes_after'   => count($b['nodes']),
				'added'         => count($added),
				'removed'       => count($removed),
				'changed'       => count($changed),
				'edges_added'   => count($edges_added),
				'edges_removed' => count($edges_removed),
				'minutes_before'=> $minutes_before,
				'minutes_after' => $minutes_after,
				'wait_before'   => $wait_before,
				'wait_after'    => $wait_after,
				'total_before'  => $minutes_before + $wait_before,
				'total_after'   => $minutes_after + $wait_after,
				'is_empty'      => empty($added) AND empty($removed) AND empty($changed),
			],
		];
	}

	protected static function _index(array $graph)
	{
		$nodes = [];
		$edges = [];

		foreach ((array) Arr::get($graph, 'nodes', []) as $node)
		{
			$key = trim((string) Arr::get($node, 'step_id', ''));

			if ($key === '')
			{
				$key = (string) Arr::get($node, 'id', '');
			}

			if ($key !== '' AND ! isset($nodes[$key]))
			{
				$nodes[$key] = $node;
			}
		}

		foreach ((array) Arr::get($graph, 'edges', []) as $edge)
		{
			$from = (string) Arr::get($edge, 'from', '');
			$to = (string) Arr::get($edge, 'to', '');

			if ($from !== '' AND $to !== '')
			{
				$edges[$from.'>'.$to] = [
					'from' => $from,
					'to'   => $to,
					'type' => (string) Arr::get($edge, 'type', 'sequence'),
					'condition' => (string) Arr::get($edge, 'condition', ''),
				];
			}
		}

		// Рёбра нормализуем к step_id, иначе сравнение ломается при смене id.
		$id_to_step = [];

		foreach ((array) Arr::get($graph, 'nodes', []) as $node)
		{
			$id_to_step[(string) Arr::get($node, 'id', '')] = (string) Arr::get($node, 'step_id', Arr::get($node, 'id', ''));
		}

		$normalized = [];

		foreach ($edges as $edge)
		{
			$from = (string) Arr::get($id_to_step, $edge['from'], $edge['from']);
			$to = (string) Arr::get($id_to_step, $edge['to'], $edge['to']);
			$normalized[$from.'>'.$to] = [
				'from' => $from,
				'to'   => $to,
				'type' => $edge['type'],
				'condition' => $edge['condition'],
			];
		}

		return ['nodes' => $nodes, 'edges' => $normalized];
	}

	protected static function _node_diff(array $before, array $after)
	{
		$fields = [
			'title'             => ['title' => 'Название'],
			'type'              => ['type' => 'Тип'],
			'role_code'         => ['role' => 'Роль'],
			'system_code'       => ['system' => 'Система'],
			'duration_minutes'  => ['minutes' => 'Работа'],
			'wait_time_minutes' => ['wait' => 'Ожидание'],
			'input_code'        => ['input' => 'Вход'],
			'output_code'       => ['output' => 'Выход'],
		];

		$diff = ['fields' => [], 'before' => [], 'after' => []];

		foreach ($fields as $key => $meta)
		{
			$old = Arr::get($before, $key, '');
			$new = Arr::get($after, $key, '');

			if ((string) $old === (string) $new)
			{
				continue;
			}

			$label = reset($meta);
			$diff['fields'][] = $label;
			$diff['before'][$label] = self::_human($key, $old);
			$diff['after'][$label] = self::_human($key, $new);
		}

		// Пустой массив в PHP7+ всё равно truthy, если в нём есть ключи:
		// возвращаем [], иначе каждый неизменённый шаг попадал в «изменённые».
		return $diff['fields'] ? $diff : [];
	}

	protected static function _human($key, $value)
	{
		if ($key === 'duration_minutes' OR $key === 'wait_time_minutes')
		{
			return Service_Nj670_Repo::minutes_text((int) $value);
		}

		return (string) $value;
	}

	protected static function _row(array $node)
	{
		return [
			'step_id'  => (string) Arr::get($node, 'step_id', ''),
			'title'    => (string) Arr::get($node, 'title', ''),
			'type'     => (string) Arr::get($node, 'type', ''),
			'role_code'=> (string) Arr::get($node, 'role_code', ''),
			'system_code' => (string) Arr::get($node, 'system_code', ''),
			'minutes'  => (int) Arr::get($node, 'duration_minutes', 0),
			'wait'     => (int) Arr::get($node, 'wait_time_minutes', 0),
		];
	}

	protected static function _sum(array $nodes, $key)
	{
		$total = 0;

		foreach ($nodes as $node)
		{
			$total += (int) Arr::get($node, $key, 0);
		}

		return $total;
	}
}
