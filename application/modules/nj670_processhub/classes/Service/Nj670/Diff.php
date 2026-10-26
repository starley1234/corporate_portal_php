<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Правиловый аудит «План ↔ Факт» без LLM.
 *
 * Нужен двум сценариям:
 *   1) демо и песочница без ключей модели;
 *   2) кнопка «быстрый расчёт» — когда воркер занят или недоступен, человек
 *      всё равно получает разрывы, а не пустую страницу.
 *
 * LLM-аудит (канал nj670.diff) использует этот же результат как каркас и
 * дополняет его формулировками; цифры остаются детерминированными.
 */
class Service_Nj670_Diff extends Service_Nj670_Repo
{
	/**
	 * @return array summary, findings, total_impact_rub, risk_level, mode
	 */
	public static function analyze(array $plan, array $fact, array $ontology = [], array $economy = [])
	{
		$plan_nodes = (array) Arr::get($plan, 'nodes', []);
		$fact_nodes = (array) Arr::get($fact, 'nodes', []);
		$plan_edges = (array) Arr::get($plan, 'edges', []);
		$fact_edges = (array) Arr::get($fact, 'edges', []);

		$plan_metrics = Service_Nj670_Graph::metrics($plan, $ontology);
		$fact_metrics = Service_Nj670_Graph::metrics($fact, $ontology);

		$matches = self::_match($plan_nodes, $fact_nodes);
		$matched_plan = [];
		$matched_fact = [];

		foreach ($matches as $pair)
		{
			$matched_plan[$pair['plan']] = $pair['fact'];
			$matched_fact[$pair['fact']] = $pair['plan'];
		}

		$findings = [];
		$rates = (array) Arr::get($economy, 'default_rate_per_hour', 1500);

		// 1. Теневые шаги: есть в факте, нет в плане.
		foreach ($fact_nodes as $node)
		{
			if (isset($matched_fact[$node['id']]))
			{
				continue;
			}

			$shadow = ( ! empty($node['properties']['shadow'])
				OR in_array($node['system_code'], ['telegram', 'excel'], TRUE));

			$impact = self::_node_cost($node, $ontology, $economy);

			$findings[] = [
				'id'             => 'rules-shadow-'.$node['id'],
				'severity'       => $shadow ? 'critical' : 'high',
				'category'       => $shadow ? 'shadow_it' : 'extra_step',
				'title'          => 'Шаг вне регламента: '.(string) $node['title'],
				'description'    => $shadow
					? 'В факте появился неучтённый шаг (теневая автоматизация). Он не описан в регламенте, ответственность и SLA не заданы.'
					: 'Шаг выполняется в реальности, но отсутствует в утверждённой схеме.',
				'evidence'       => 'Шаг «'.(string) $node['title'].'»: '
					.number_format((int) $node['duration_minutes'] + (int) $node['wait_time_minutes'], 0, '.', ' ').' мин, '
					.'система: '.self::_system_name($node['system_code'], $ontology).'.',
				'recommendation' => $shadow
					? 'Перенести операцию в утверждённую систему или узаконить её отдельным шагом регламента.'
					: 'Добавить шаг в план либо устранить его причину.',
				'impact_rub'     => round($impact, 2),
				'node_id'        => (string) $node['step_id'],
				'metric'         => 'нет в плане',
			];
		}

		// 2. Пропущенные шаги плана, особенно контрольные.
		foreach ($plan_nodes as $node)
		{
			if (isset($matched_plan[$node['id']]))
			{
				continue;
			}

			$control = ( ! empty($node['properties']['control'])
				OR in_array($node['role_code'], ['lawyer', 'security', 'cfo', 'accountant'], TRUE));

			$findings[] = [
				'id'             => 'rules-missing-'.$node['id'],
				'severity'       => $control ? 'high' : 'medium',
				'category'       => $control ? 'missing_control' : 'bypass',
				'title'          => 'В факте нет шага: '.(string) $node['title'],
				'description'    => $control
					? 'Контрольная процедура из регламента не встречается в журнале. Это признак обхода контроля.'
					: 'Шаг утверждённого процесса не найден в фактических событиях.',
				'evidence'       => 'План: «'.(string) $node['title'].'» ('.self::_role_name($node['role_code'], $ontology).').',
				'recommendation' => 'Вернуть шаг в маршрут и сделать его обязательным в учётной системе.',
				'impact_rub'     => 0,
				'node_id'        => (string) $node['step_id'],
				'metric'         => 'не найдено в факте',
			];
		}

		// 3. Подмена системы у совпавшего шага.
		foreach ($matches as $pair)
		{
			$p = $pair['plan_node'];
			$f = $pair['fact_node'];

			if ($p['system_code'] === '' OR $f['system_code'] === '' OR $p['system_code'] === $f['system_code'])
			{
				continue;
			}

			$risky = in_array($f['system_code'], ['telegram', 'excel'], TRUE);

			$findings[] = [
				'id'             => 'rules-system-'.$f['id'],
				'severity'       => $risky ? 'high' : 'medium',
				'category'       => 'unauthorized_system',
				'title'          => 'Шаг выполняется не в системе регламента',
				'description'    => 'В плане — '.self::_system_name($p['system_code'], $ontology)
					.', в факте — '.self::_system_name($f['system_code'], $ontology).'.',
				'evidence'       => 'Шаг «'.(string) $f['title'].'».',
				'recommendation' => 'Вернуть операцию в целевую систему; для мессенджеров — запретить передачу документов.',
				'impact_rub'     => $risky ? 2000 : 0,
				'node_id'        => (string) $f['step_id'],
				'metric'         => self::_system_name($f['system_code'], $ontology),
			];
		}

		// 4. Петли возврата.
		$loops = 0;
		$loop_nodes = [];

		foreach ($fact_edges as $edge)
		{
			if ($edge['type'] === 'loop' OR (int) Arr::path($edge, 'properties.variant', 0) > 0)
			{
				$loops++;
				$loop_nodes[$edge['from']] = TRUE;
				$loop_nodes[$edge['to']] = TRUE;
			}
		}

		if ($loops > 0)
		{
			$names = [];

			foreach ($fact_nodes as $node)
			{
				if (isset($loop_nodes[$node['id']]))
				{
					$names[] = (string) $node['title'];
				}
			}

			$findings[] = [
				'id'             => 'rules-loop',
				'severity'       => 'medium',
				'category'       => 'loop',
				'title'          => 'Возвраты на доработку: '.$loops,
				'description'    => 'В фактическом маршруте есть обратные переходы. Каждый возврат увеличивает срок и стоимость.',
				'evidence'       => 'Петля вокруг: '.UTF8::substr(implode(', ', array_slice($names, 0, 4)), 0, 400).'.',
				'recommendation' => 'Убрать причину возврата (обязательные поля, автопроверка реквизитов).',
				'impact_rub'     => 0,
				'node_id'        => '',
				'metric'         => $loops.' переход(ов) назад',
			];
		}

		// 5. Узкие места по ожиданию.
		$elapsed = max(1, (int) $fact_metrics['elapsed_minutes']);

		foreach (Service_Nj670_Graph::bottlenecks($fact, 5) as $bottleneck)
		{
			$share = (float) $bottleneck['share'];

			if ($share < 0.1)
			{
				continue;
			}

			$node = NULL;

			foreach ($fact_nodes as $row)
			{
				if ($row['step_id'] === $bottleneck['step_id'])
				{
					$node = $row;
					break;
				}
			}

			$rate = $node ? self::_rate($node['role_code'], $ontology, $economy) : (float) $rates;
			$impact = ($bottleneck['wait'] / 60) * $rate * (float) Arr::get($economy, 'wait_cost_share', 0.5);

			$findings[] = [
				'id'             => 'rules-bottleneck-'.$bottleneck['step_id'],
				'severity'       => $share >= 0.4 ? 'critical' : ($share >= 0.2 ? 'high' : 'medium'),
				'category'       => 'bottleneck',
				'title'          => 'Узкое место: '.(string) $bottleneck['title'],
				'description'    => 'Шаг держит '.round($share * 100, 1).' % календарного срока.',
				'evidence'       => Service_Nj670_Repo::minutes_text((int) $bottleneck['wait']).' ожидания из '
					.Service_Nj670_Repo::minutes_text($elapsed).'.',
				'recommendation' => 'Сократить ожидание: SLA, автоматический эскалейт, параллельная ветка.',
				'impact_rub'     => round($impact, 2),
				'node_id'        => (string) $bottleneck['step_id'],
				'metric'         => round($share * 100, 1).' %',
			];
		}

		// 6. Разрыв срока.
		$plan_elapsed = max(1, (int) $plan_metrics['elapsed_minutes']);
		$delta = $elapsed - $plan_elapsed;

		if ($delta > 0)
		{
			$findings[] = [
				'id'             => 'rules-sla',
				'severity'       => $delta >= $plan_elapsed ? 'critical' : 'high',
				'category'       => 'sla',
				'title'          => 'Срок вырос в '.round($elapsed / $plan_elapsed, 1).' раза',
				'description'    => 'Фактический срок превышает плановый на '.Service_Nj670_Repo::minutes_text($delta).'.',
				'evidence'       => 'План: '.Service_Nj670_Repo::minutes_text($plan_elapsed)
					.', факт: '.Service_Nj670_Repo::minutes_text($elapsed).'.',
				'recommendation' => 'Разобрать ожидания по дорожкам и установить SLA на каждую передачу.',
				'impact_rub'     => 0,
				'node_id'        => '',
				'metric'         => round(($delta / $plan_elapsed) * 100, 0).' %',
			];
		}

		$impact = 0.0;
		$rank = 0;
		$weights = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

		foreach ($findings as $finding)
		{
			$impact += (float) $finding['impact_rub'];
			$rank = max($rank, (int) Arr::get($weights, $finding['severity'], 1));
		}

		$risk = 'low';

		foreach ($weights as $name => $value)
		{
			if ($value === $rank)
			{
				$risk = $name;
			}
		}

		$summary = 'План: '.round($plan_elapsed / 480, 1).' раб. дн., факт: '.round($elapsed / 480, 1)
			.' раб. дн. Найдено разрывов: '.count($findings).'. '
			.($impact > 0 ? 'Оценка потерь: '.Service_Nj670_Repo::money($impact).'.' : 'Денежная оценка требует ставок ролей.');

		return [
			'summary'          => $summary,
			'findings'         => self::_sort($findings),
			'total_impact_rub' => round($impact, 2),
			'risk_level'       => $risk,
			'mode'             => 'rules',
			'metrics'          => [
				'plan_elapsed' => $plan_elapsed,
				'fact_elapsed' => $elapsed,
				'delta'        => $delta,
			],
		];
	}

	protected static function _sort(array $findings)
	{
		$rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

		usort($findings, function ($a, $b) use ($rank) {
			$ra = (int) Arr::get($rank, (string) Arr::get($a, 'severity', 'medium'), 9);
			$rb = (int) Arr::get($rank, (string) Arr::get($b, 'severity', 'medium'), 9);

			return $ra <=> $rb;
		});

		return $findings;
	}

	protected static function _normalize($text)
	{
		$text = mb_strtolower((string) $text);
		$text = preg_replace('~[^а-яёa-z0-9\s]+~u', ' ', $text);
		$text = preg_replace('~\s+~u', ' ', (string) $text);

		$stop = ['и', 'в', 'во', 'на', 'с', 'со', 'по', 'для', 'из', 'от', 'до', 'к', 'ко', 'у', 'о', 'об', 'не', 'а', 'или', 'это', 'его', 'ее', 'их'];

		$tokens = [];

		foreach (explode(' ', trim((string) $text)) as $word)
		{
			if (mb_strlen($word) >= 3 AND ! in_array($word, $stop, TRUE))
			{
				$tokens[] = $word;
			}
		}

		return array_values(array_unique($tokens));
	}

	protected static function _match(array $plan_nodes, array $fact_nodes)
	{
		$out = [];
		$used = [];

		foreach ($plan_nodes as $plan)
		{
			if (in_array($plan['type'], ['start', 'end'], TRUE))
			{
				continue;
			}

			$best = NULL;
			$best_score = 0.0;

			$pt = self::_normalize($plan['title']);

			foreach ($fact_nodes as $fact)
			{
				if (in_array($fact['type'], ['start', 'end'], TRUE) OR isset($used[$fact['id']]))
				{
					continue;
				}

				if ($fact['step_id'] === $plan['step_id'])
				{
					$best = $fact;
					$best_score = 1.0;
					break;
				}

				$ft = self::_normalize($fact['title']);
				$inter = array_intersect($pt, $ft);
				$union = array_unique(array_merge($pt, $ft));
				$score = $union ? count($inter) / count($union) : 0.0;

				if ($plan['role_code'] !== '' AND $plan['role_code'] === $fact['role_code'])
				{
					$score += 0.12;
				}

				if ($plan['system_code'] !== '' AND $plan['system_code'] === $fact['system_code'])
				{
					$score += 0.08;
				}

				if ($score > $best_score)
				{
					$best_score = $score;
					$best = $fact;
				}
			}

			if ($best AND $best_score >= 0.45)
			{
				$used[$best['id']] = TRUE;
				$out[] = [
					'plan' => $plan['id'],
					'fact' => $best['id'],
					'plan_node' => $plan,
					'fact_node' => $best,
					'score' => round($best_score, 2),
				];
			}
		}

		return $out;
	}

	protected static function _node_cost(array $node, array $ontology, array $economy)
	{
		$rate = self::_rate($node['role_code'], $ontology, $economy);
		$wait_share = (float) Arr::get($economy, 'wait_cost_share', 0.5);

		return (((float) $node['duration_minutes'] / 60) * $rate
			+ ((float) $node['wait_time_minutes'] / 60) * $rate * $wait_share);
	}

	protected static function _rate($role_code, array $ontology, array $economy)
	{
		$default = (float) Arr::get($economy, 'default_rate_per_hour', 1500);
		$by_code = (array) Arr::path($ontology, 'by_code.role', []);

		if ($role_code !== '' AND isset($by_code[$role_code]))
		{
			return (float) Arr::get((array) Arr::get($by_code[$role_code], 'attributes', []), 'rate_per_hour', $default);
		}

		return $default;
	}

	protected static function _role_name($role_code, array $ontology)
	{
		$by_code = (array) Arr::path($ontology, 'by_code.role', []);

		return isset($by_code[$role_code]['name']) ? (string) $by_code[$role_code]['name'] : ($role_code !== '' ? $role_code : '—');
	}

	protected static function _system_name($system_code, array $ontology)
	{
		$by_code = (array) Arr::path($ontology, 'by_code.system', []);

		return isset($by_code[$system_code]['name']) ? (string) $by_code[$system_code]['name'] : ($system_code !== '' ? $system_code : '—');
	}

	/**
	 * Быстрый аудит без очереди: считает и сразу закрывает строку аудита.
	 */
	public static function run_fast($process_id, $user_id)
	{
		$process = Service_Nj670_Process::process_by_id($process_id);

		if ( ! $process)
		{
			return ['ok' => FALSE, 'error' => 'Процесс не найден.'];
		}

		$plan = Service_Nj670_Process::current_version($process, 'plan');
		$fact = Service_Nj670_Process::current_version($process, 'fact');

		if ( ! $plan OR ! $fact)
		{
			return ['ok' => FALSE, 'error' => 'Нужны обе схемы: План и Факт.'];
		}

		$ontology = Service_Nj670_Ontology::index((int) $process['workspace_id']);
		$result = self::analyze(
			Service_Nj670_Process::graph_of($plan),
			Service_Nj670_Process::graph_of($fact),
			$ontology,
			Service_Nj670_Process::branch('economy')
		);

		$id = self::_insert('nj_670_audits', [
			'process_id'      => (int) $process['id'],
			'plan_version_id' => (int) $plan['id'],
			'fact_version_id' => (int) $fact['id'],
			'status'          => 'done',
			'summary'         => (string) $result['summary'],
			'findings'        => $result['findings'],
			'total_impact_rub'=> (float) $result['total_impact_rub'],
			'risk_level'      => (string) $result['risk_level'],
			'mode'            => 'rules',
			'model'           => 'rules-engine',
			'created_by'      => (int) $user_id,
		], ['findings']);

		self::_event((int) $process['id'], $user_id, 'audit.done',
			'Быстрый аудит по правилам: '.count($result['findings']).' находок', [
				'audit_id' => $id,
				'impact' => (float) $result['total_impact_rub'],
			]);

		return [
			'ok'       => TRUE,
			'audit_id' => $id,
			'audit'    => Service_Nj670_Process::audit($id),
			'result'   => $result,
		];
	}
}
