<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Что если»: загрузка ролей и очередь на текущей схеме.
 *
 * Это не полноценная DES-симуляция Storm: считаем детерминированную
 * ёмкость по ролям и очередь M/M/1 на роль. Задача — за минуту ответить
 * «сколько людей нужно, чтобы 300 заявок в месяц не стояли в очереди»,
 * а не воспроизвести каждый токен.
 *
 * Допущения (и они же предупреждения в ответе):
 *  - работы ролей складываются; параллельные ветки не разводятся;
 *  - ёмкость = люди × рабочий день × рабочие дни;
 *  - очередь — M/M/1, обслуживание — сумма работ роли на один кейс;
 *  - шаги без роли в расчёт не входят (они видны отдельным счётчиком).
 */
class Service_Nj670_Simulate extends Service_Nj670_Repo
{
	const TARGET_UTILIZATION = 0.8;

	/**
	 * @param array $graph  граф ProcessHub
	 * @param array $index  индекс онтологии
	 * @param array $input  volume, workdays, workday_minutes, people[role_code]
	 */
	public static function run(array $graph, array $index = [], array $input = [])
	{
		$volume = self::_int($input, 'volume', 200, 1, 1000000);
		$workdays = self::_int($input, 'workdays', 21, 1, 31);
		$workday = self::_int($input, 'workday_minutes', 480, 60, 1440);
		$people_in = (array) Arr::get($input, 'people', []);

		$nodes = (array) Arr::get($graph, 'nodes', []);
		$metrics = Service_Nj670_Graph::metrics($graph, $index);
		$by_code = (array) Arr::path($index, 'by_code.role', []);

		$roles = [];
		$unassigned_work = 0;
		$unassigned_steps = 0;

		foreach ($nodes as $node)
		{
			$type = (string) Arr::get($node, 'type', '');

			if (in_array($type, ['start', 'end', 'gateway_xor', 'gateway_and'], TRUE))
			{
				continue;
			}

			$work = max(0, (int) Arr::get($node, 'duration_minutes', 0));
			$wait = max(0, (int) Arr::get($node, 'wait_time_minutes', 0));
			$role_code = (string) Arr::get($node, 'role_code', '');

			if ($role_code === '')
			{
				$unassigned_work += $work + $wait;
				$unassigned_steps++;
				continue;
			}

			if ( ! isset($roles[$role_code]))
			{
				$roles[$role_code] = [
					'code'      => $role_code,
					'name'      => (string) Arr::get((array) Arr::get($by_code, $role_code, []), 'name', $role_code),
					'steps'     => 0,
					'work'      => 0,
					'wait'      => 0,
					'rate_per_hour' => (float) Arr::get(
						(array) Arr::get((array) Arr::get($by_code, $role_code, []), 'attributes', []),
						'rate_per_hour',
						(float) Arr::get(self::branch('economy'), 'default_rate_per_hour', 1500)
					),
				];
			}

			$roles[$role_code]['steps']++;
			$roles[$role_code]['work'] += $work;
			$roles[$role_code]['wait'] += $wait;
		}

		$capacity_month = $workday * $workdays;
		$rows = [];
		$total_queue = 0.0;
		$max_queue = 0.0;
		$bottleneck = NULL;
		$infeasible = FALSE;
		$role_cost = 0.0;

		foreach ($roles as $code => $role)
		{
			$people = self::_int($people_in, $code, 1, 1, 1000);
			$service = $role['work'];
			$capacity = $capacity_month * $people;
			$utilization = $capacity > 0 ? ($volume * $service) / $capacity : 0.0;
			$queue = NULL;
			$queue_text = '';
			$feasible = $utilization < 1.0;

			if ($feasible)
			{
				// M/M/1: Wq = ρ / (1 − ρ) × среднее обслуживание.
				$queue = $utilization > 0
					? $service * ($utilization / (1.0 - $utilization))
					: 0.0;
				$queue = min($queue, 1000000.0);
				$total_queue += $queue;
			}
			else
			{
				$infeasible = TRUE;
				$queue_text = 'очередь неограниченно растёт';
			}

			$required = $service > 0
				? (int) ceil(($volume * $service) / (self::TARGET_UTILIZATION * $capacity_month))
				: 0;
			$required = max(1, $required);

			$throughput = $service > 0 ? (int) floor($capacity / $service) : 0;
			// Стоимость ролей: часы на ноги, а не на кейс, — именно её
			// собственник сравнивает с фондом оплаты труда.
			$role_cost += ($capacity_month * $people / 60.0) * $role['rate_per_hour'];

			$rows[] = [
				'code'        => $code,
				'name'        => $role['name'],
				'steps'       => $role['steps'],
				'work'        => $service,
				'wait'        => $role['wait'],
				'people'      => $people,
				'capacity'    => $capacity,
				'utilization' => round($utilization, 3),
				'queue'       => $queue !== NULL ? round($queue, 1) : NULL,
				'queue_text'  => $queue_text,
				'required'    => $required,
				'throughput'  => $throughput,
				'rate_per_hour' => $role['rate_per_hour'],
				'monthly_cost' => round($capacity_month * $people / 60.0 * $role['rate_per_hour'], 2),
			];

			if ($queue !== NULL AND $queue > $max_queue)
			{
				$max_queue = $queue;
			}
		}

		usort($rows, function ($a, $b) {
			if (abs($a['utilization'] - $b['utilization']) < 0.0005)
			{
				// При равной загрузке узкое место — там, где длиннее очередь.
				$left = $a['queue'] === NULL ? INF : $a['queue'];
				$right = $b['queue'] === NULL ? INF : $b['queue'];

				if ($left === $right)
				{
					return strcmp($a['name'], $b['name']);
				}

				return $left < $right ? 1 : -1;
			}

			return $a['utilization'] < $b['utilization'] ? 1 : -1;
		});

		// Первая строка — самая загруженная роль с самой длинной очередью.
		$bottleneck = $rows ? [
			'code'        => (string) $rows[0]['code'],
			'name'        => (string) $rows[0]['name'],
			'utilization' => (float) $rows[0]['utilization'],
		] : NULL;

		$throughput_max = NULL;

		foreach ($rows as $row)
		{
			if ($row['throughput'] <= 0)
			{
				continue;
			}

			if ($throughput_max === NULL OR $row['throughput'] < $throughput_max)
			{
				$throughput_max = $row['throughput'];
			}
		}

		$base_cycle = (int) Arr::get($metrics, 'elapsed_minutes', 0);
		$cycle = $base_cycle + (int) round($total_queue);
		$warnings = [];

		if (empty($roles))
		{
			$warnings[] = 'В схеме нет шагов с ролью — загрузку считать не по чему.';
		}

		if ($unassigned_steps > 0)
		{
			$warnings[] = 'Шагов без роли: '.$unassigned_steps.' ('.Service_Nj670_Repo::minutes_text($unassigned_work).'). В загрузку ролей они не вошли.';
		}

		if ($infeasible)
		{
			$warnings[] = 'Мощности не хватает: хотя бы одна роль загружена больше 100 %. Очередь будет расти.';
		}

		$warnings[] = 'Оценка «сверху»: работы ролей складываются, параллельные ветки не моделируются.';

		return [
			'ok'            => TRUE,
			'volume'        => $volume,
			'workdays'      => $workdays,
			'workday_minutes' => $workday,
			'capacity_month'=> $capacity_month,
			'base_cycle'    => $base_cycle,
			'queue_minutes' => (int) round($total_queue),
			'cycle_minutes' => $cycle,
			'throughput_max'=> $throughput_max,
			'feasible'      => ! $infeasible,
			'unassigned_steps' => $unassigned_steps,
			'unassigned_minutes' => $unassigned_work,
			'roles'         => $rows,
			'bottleneck'    => $bottleneck,
			'monthly_cost'  => round($role_cost, 2),
			'target_utilization' => self::TARGET_UTILIZATION,
			'warnings'      => $warnings,
		];
	}

	protected static function _int(array $source, $key, $default, $min, $max)
	{
		$value = Arr::get($source, $key, $default);

		if ( ! is_numeric($value))
		{
			return (int) $default;
		}

		return (int) max($min, min($max, (int) $value));
	}
}
