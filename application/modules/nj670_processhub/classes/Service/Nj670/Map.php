<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Карта организации: процессы, роли, системы и пробелы в одной картине.
 *
 * Все данные — из уже сохранённых версий и справочника; карта ничего не
 * запускает и не меняет. Это ответ на вопрос «как устроена компания целиком»:
 * какие подразделения покрыты процессами, какие системы реально используются,
 * где владелец не назначен и где план расходится с фактом.
 *
 * Тяжёлые агрегаты считает SQL по nj_670_nodes (семантический индекс шагов),
 * а не PHP по JSONB: индекс обновляется при каждом сохранении версии.
 */
class Service_Nj670_Map extends Service_Nj670_Repo
{
	public static function overview($workspace_id)
	{
		$workspace_id = (int) $workspace_id;
		$processes = Service_Nj670_Process::for_workspace($workspace_id, 200);
		$index = Service_Nj670_Ontology::index($workspace_id);

		$systems = self::_usage($workspace_id, 'system_id');
		$roles = self::_usage($workspace_id, 'role_id');
		$artifacts = self::_artifact_usage($workspace_id);
		$matrix = self::_matrix($workspace_id);

		// Владельцы: считаем по p.owner_role_id, а не по шагам.
		$owned = [];

		foreach ($processes as $process)
		{
			$owner_id = (int) Arr::get($process, 'owner_role_id', 0);

			if ($owner_id > 0)
			{
				$owned[$owner_id] = (int) Arr::get($owned, $owner_id, 0) + 1;
			}
		}

		foreach ($roles as &$role)
		{
			$role['owned'] = (int) Arr::get($owned, (int) $role['id'], 0);
		}
		unset($role);

		$calls = Service_Nj670_Process::calls($workspace_id);
		$tree = Service_Nj670_Process::tree($processes);
		$roots = (array) Arr::get((array) Arr::get($tree, 'children', []), 0, []);

		$departments = self::_departments($processes, $index);
		$gaps = self::_gaps($processes, $index, $systems, $artifacts, $roles, $matrix);
		$insights = self::_insights($processes, $systems, $roles);

		return [
			'workspace'   => Service_Nj670_Process::workspace($workspace_id),
			'processes'   => $processes,
			'systems'     => $systems,
			'artifacts'   => $artifacts,
			'roles'       => $roles,
			'departments' => $departments,
			'matrix'      => $matrix,
			'calls'       => $calls,
			'tree'        => $tree,
			'roots'       => $roots,
			'gaps'        => $gaps,
			'insights'    => $insights,
			'ontology'    => [
				'role'       => count((array) Arr::path($index, 'list.role', [])),
				'system'     => count((array) Arr::path($index, 'list.system', [])),
				'artifact'   => count((array) Arr::path($index, 'list.artifact', [])),
				'department' => count((array) Arr::path($index, 'list.department', [])),
			],
		];
	}

	/**
	 * Использование сущности в шагах: процессов и шагов.
	 *
	 * @param string $column role_id|system_id (значение проверяется по списку)
	 */
	protected static function _usage($workspace_id, $column)
	{
		$column = $column === 'role_id' ? 'role_id' : 'system_id';

		try
		{
			return self::_all('SELECT e.id, e.code, e.name,
					count(DISTINCT v.process_id) AS processes,
					count(*) AS steps
				FROM nj_670_nodes n
				JOIN nj_670_versions v ON v.id = n.version_id
				JOIN nj_670_processes p ON p.id = v.process_id
				JOIN nj_670_entities e ON e.id = n.'.$column.'
				AND v.id = COALESCE(p.plan_version_id, p.fact_version_id)
				WHERE p.workspace_id = '.(int) $workspace_id.'
				GROUP BY e.id, e.code, e.name
				ORDER BY processes DESC, steps DESC, e.name');
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	protected static function _artifact_usage($workspace_id)
	{
		try
		{
			return self::_all('SELECT e.id, e.code, e.name,
					count(DISTINCT x.process_id) AS processes,
					count(*) AS steps
				FROM (
					SELECT v.process_id, n.input_artifact_id AS artifact_id
					FROM nj_670_nodes n
					JOIN nj_670_versions v ON v.id = n.version_id
					JOIN nj_670_processes p ON p.id = v.process_id
					WHERE p.workspace_id = '.(int) $workspace_id.'
					  AND v.id = COALESCE(p.plan_version_id, p.fact_version_id)
					  AND n.input_artifact_id IS NOT NULL
					UNION ALL
					SELECT v.process_id, n.output_artifact_id AS artifact_id
					FROM nj_670_nodes n
					JOIN nj_670_versions v ON v.id = n.version_id
					JOIN nj_670_processes p ON p.id = v.process_id
					WHERE p.workspace_id = '.(int) $workspace_id.'
					  AND v.id = COALESCE(p.plan_version_id, p.fact_version_id)
					  AND n.output_artifact_id IS NOT NULL
				) x
				JOIN nj_670_entities e ON e.id = x.artifact_id
				GROUP BY e.id, e.code, e.name
				ORDER BY processes DESC, steps DESC, e.name');
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	/**
	 * Матрица «процесс × система»: [process_id][system_code] = шагов.
	 */
	protected static function _matrix($workspace_id)
	{
		try
		{
			$rows = self::_all('SELECT v.process_id, e.code, count(*) AS n
				FROM nj_670_nodes n
				JOIN nj_670_versions v ON v.id = n.version_id
				JOIN nj_670_processes p ON p.id = v.process_id
				JOIN nj_670_entities e ON e.id = n.system_id
				WHERE p.workspace_id = '.(int) $workspace_id.'
				  AND v.id = COALESCE(p.plan_version_id, p.fact_version_id)
				GROUP BY v.process_id, e.code');
		}
		catch (Throwable $e)
		{
			return [];
		}

		$out = [];

		foreach ($rows as $row)
		{
			$out[(int) $row['process_id']][(string) $row['code']] = (int) $row['n'];
		}

		return $out;
	}

	protected static function _departments(array $processes, array $index)
	{
		$groups = [];

		foreach ($processes as $process)
		{
			$name = trim((string) Arr::get($process, 'department', ''));
			$name = $name !== '' ? $name : 'Не указано';
			$groups[] = $name;
		}

		$departments = array_values(array_unique($groups));
		sort($departments);

		$out = [];

		foreach ($departments as $name)
		{
			$items = array_values(array_filter($processes, function ($process) use ($name) {
				$own = trim((string) Arr::get($process, 'department', ''));
				$own = $own !== '' ? $own : 'Не указано';

				return $own === $name;
			}));

			$out[] = ['name' => $name, 'processes' => $items];
		}

		return $out;
	}

	/**
	 * Пробелы: то, что не видно в отдельной схеме, но видно сверху.
	 */
	protected static function _gaps(array $processes, array $index, array $systems, array $artifacts, array $roles, array $matrix)
	{
		$gaps = [];

		$without_owner = array_filter($processes, function ($process) {
			return (int) Arr::get($process, 'owner_role_id', 0) <= 0;
		});
		$without_plan = array_filter($processes, function ($process) {
			return (int) Arr::get($process, 'plan_minutes', 0) <= 0;
		});
		$without_fact = array_filter($processes, function ($process) {
			return (int) Arr::get($process, 'fact_minutes', 0) <= 0;
		});
		$without_audit = array_filter($processes, function ($process) {
			return (int) Arr::get($process, 'audits_count', 0) <= 0;
		});

		$with_systems = [];

		foreach ($matrix as $process_id => $by_system)
		{
			if ($by_system)
			{
				$with_systems[(int) $process_id] = TRUE;
			}
		}

		$no_systems = array_filter($processes, function ($process) use ($with_systems) {
			return ! isset($with_systems[(int) $process['id']]);
		});

		$used_systems = [];

		foreach ($systems as $system)
		{
			$used_systems[(string) $system['code']] = TRUE;
		}

		$unused_systems = array_values(array_filter((array) Arr::path($index, 'list.system', []), function ($system) use ($used_systems) {
			return ! isset($used_systems[(string) $system['code']]);
		}));

		$used_artifacts = [];

		foreach ($artifacts as $artifact)
		{
			$used_artifacts[(string) $artifact['code']] = TRUE;
		}

		$unused_artifacts = array_values(array_filter((array) Arr::path($index, 'list.artifact', []), function ($artifact) use ($used_artifacts) {
			return ! isset($used_artifacts[(string) $artifact['code']]);
		}));

		$used_roles = [];

		foreach ($roles as $role)
		{
			$used_roles[(string) $role['code']] = TRUE;
		}

		$unused_roles = array_values(array_filter((array) Arr::path($index, 'list.role', []), function ($role) use ($used_roles) {
			return ! isset($used_roles[(string) $role['code']]);
		}));

		$gaps[] = self::_gap('Без владельца', 'У процесса не назначена роль-владелец: некому утверждать план и отвечать за факт.',
			$without_owner, 'danger');
		$gaps[] = self::_gap('Без плана', 'Нет ни одной версии плана — процесс существует только как карточка.',
			$without_plan, 'warn');
		$gaps[] = self::_gap('Без факта', 'Журнал событий не загружен: сравнить план с реальностью нечем.',
			$without_fact, 'warn');
		$gaps[] = self::_gap('Без аудита', 'Процесс ни разу не проверялся AI-аудитом.',
			$without_audit, 'muted');
		$gaps[] = self::_gap('Без систем', 'Ни один шаг не привязан к ИТ-системе: картина «кто в чём работает» неполная.',
			$no_systems, 'warn');

		$gap = [];
		$gap[] = [
			'title' => 'Системы вне процессов',
			'text'  => 'В справочнике есть, но ни в одной схеме не используются. Либо legacy, либо «теневой» контур.',
			'type'  => 'muted',
			'items' => array_map(function ($system) {
				return ['name' => (string) $system['name'], 'code' => (string) $system['code']];
			}, $unused_systems),
		];
		$gap[] = [
			'title' => 'Артефакты вне процессов',
			'text'  => 'Документы, которые никто не производит и не потребляет в схемах.',
			'type'  => 'muted',
			'items' => array_map(function ($artifact) {
				return ['name' => (string) $artifact['name'], 'code' => (string) $artifact['code']];
			}, $unused_artifacts),
		];
		$gap[] = [
			'title' => 'Роли без шагов',
			'text'  => 'Заведены в онтологии, но не встречаются ни в одном процессе.',
			'type'  => 'muted',
			'items' => array_map(function ($role) {
				return ['name' => (string) $role['name'], 'code' => (string) $role['code']];
			}, $unused_roles),
		];

		return array_merge($gaps, $gap);
	}

	protected static function _gap($title, $text, array $items, $type)
	{
		return [
			'title' => $title,
			'text'  => $text,
			'type'  => $type,
			'items' => array_map(function ($process) {
				return ['name' => (string) $process['title'], 'code' => (string) $process['code']];
			}, array_values($items)),
		];
	}

	protected static function _insights(array $processes, array $systems, array $roles)
	{
		$insights = [];
		$best_gap = NULL;

		foreach ($processes as $process)
		{
			$plan = (int) Arr::get($process, 'plan_minutes', 0);
			$fact = (int) Arr::get($process, 'fact_minutes', 0);

			if ($plan <= 0 OR $fact <= 0)
			{
				continue;
			}

			$ratio = $fact / max(1, $plan);

			if ($best_gap === NULL OR $ratio > $best_gap['ratio'])
			{
				$best_gap = [
					'ratio'  => $ratio,
					'plan'   => $plan,
					'fact'   => $fact,
					'title'  => (string) $process['title'],
					'code'   => (string) $process['code'],
				];
			}
		}

		if ($best_gap !== NULL AND $best_gap['ratio'] >= 1.5)
		{
			$insights[] = [
				'title' => 'Самый большой разрыв план/факт',
				'text'  => '«'.$best_gap['title'].'»: план '.Service_Nj670_Repo::minutes_text($best_gap['plan'])
					.' против факта '.Service_Nj670_Repo::minutes_text($best_gap['fact'])
					.' — в '.number_format($best_gap['ratio'], 1, ',', ' ').' раза дольше.',
				'href'  => '/process/'.$best_gap['code'].'/audit',
				'icon'  => 'fa-scale-unbalanced',
			];
		}

		if ($systems)
		{
			$top = $systems[0];
			$insights[] = [
				'title' => 'Самая нагруженная система',
				'text'  => (string) $top['name'].' встречается в '.(int) $top['processes'].' процессах ('.(int) $top['steps'].' шагов).',
				'href'  => '/process/ontology',
				'icon'  => 'fa-server',
			];
		}

		if ($roles)
		{
			$top = $roles[0];
			$insights[] = [
				'title' => 'Самая задействованная роль',
				'text'  => (string) $top['name'].': '.(int) $top['processes'].' процессов, '.(int) $top['steps'].' шагов, владелец '.(int) $top['owned'].' процессов.',
				'href'  => '/process/ontology',
				'icon'  => 'fa-user-tie',
			];
		}

		$no_fact = 0;

		foreach ($processes as $process)
		{
			if ((int) Arr::get($process, 'fact_minutes', 0) <= 0)
			{
				$no_fact++;
			}
		}

		if ($processes AND $no_fact > 0)
		{
			$insights[] = [
				'title' => 'Картина неполная',
				'text'  => $no_fact.' из '.count($processes).' процессов без факта: видно только замысел, не реальность.',
				'href'  => '/process/create',
				'icon'  => 'fa-database',
			];
		}

		return $insights;
	}
}
