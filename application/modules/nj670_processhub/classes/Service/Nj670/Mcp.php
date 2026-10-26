<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты ProcessHub (nj670).
 *
 * Хаб nj160_mcp вызывает обработчик с одним аргументом — массивом args.
 * Владельца действия хаб кладёт в args['_user_id'] (ключ всегда
 * перезаписывается на стороне хаба, подделать его из запроса нельзя);
 * для legacy-токена без пользователя остаётся поиск по owner/админу.
 *
 * Инструменты работают через сервисы модуля, а не через Controller_Nj670_Api:
 * у API сессия и CSRF, у агента их нет. Права проверяются те же:
 * чтение — can_view, запись — nj670.start.
 */
class Service_Nj670_Mcp extends Service_Nj670_Repo {

	/** Владелец и его пространство либо ошибка. */
	protected static function _context(array $args, $need_start = FALSE, $create_workspace = FALSE)
	{
		$uid = (int) Arr::get($args, '_user_id', 0);

		if ($uid <= 0)
		{
			$uid = Service_Nj670_Process::resolve_user_id((string) Arr::get($args, 'owner', ''));
		}

		if ($uid <= 0)
		{
			return [0, NULL, ['error' => 'Не удалось определить владельца пространства. Передайте owner (e-mail) или используйте персональный ключ.']];
		}

		if ($need_start AND ! self::_can_start($uid))
		{
			return [0, NULL, ['error' => 'У владельца ключа нет права nj670.start: создавать процессы, версии и менять онтологию нельзя.']];
		}

		$workspace = Service_Nj670_Process::workspace_for($uid, $create_workspace);

		if ( ! $workspace)
		{
			return [0, NULL, ['error' => 'Рабочее пространство ProcessHub не найдено.']];
		}

		return [$uid, $workspace, NULL];
	}

	protected static function _can_start($uid)
	{
		if (class_exists('Service_Nj160_Tokens') AND Service_Nj160_Tokens::is_admin($uid))
		{
			return TRUE;
		}

		return Service_Nj670_Access::can_start($uid);
	}

	protected static function _is_admin($uid)
	{
		return (class_exists('Service_Nj160_Tokens') AND Service_Nj160_Tokens::is_admin($uid))
			OR Service_Nj670_Access::can($uid, 'nj670.admin');
	}

	protected static function _process_or_error($code, $uid)
	{
		$process = Service_Nj670_Process::process((string) $code);

		if ( ! $process)
		{
			return [NULL, ['error' => 'Процесс не найден: '.$code]];
		}

		if ( ! Service_Nj670_Process::can_view($process, $uid, self::_is_admin($uid)))
		{
			return [NULL, ['error' => 'Этот процесс принадлежит другому рабочему пространству.']];
		}

		return [$process, NULL];
	}

	protected static function _graph_arg(array $args)
	{
		$graph = Arr::get($args, 'graph', NULL);

		if (is_string($graph))
		{
			$decoded = json_decode($graph, TRUE);
			$graph = is_array($decoded) ? $decoded : NULL;
		}

		if ( ! is_array($graph))
		{
			$graph = [
				'nodes' => Arr::get($args, 'nodes', []),
				'edges' => Arr::get($args, 'edges', []),
				'lanes' => Arr::get($args, 'lanes', []),
				'meta'  => (array) Arr::get($args, 'meta', []),
			];
		}

		return $graph;
	}

	// ------------------------------------------------------------------ чтение

	public static function mcp_processes(array $args)
	{
		list($uid, $workspace, $error) = self::_context($args);

		if ($error)
		{
			return $error;
		}

		$rows = Service_Nj670_Process::for_workspace((int) $workspace['id'], 200);
		$query = [
			'q'     => (string) Arr::get($args, 'q', ''),
			'state' => (string) Arr::get($args, 'state', ''),
		];

		if ($query['q'] !== '' OR $query['state'] !== '')
		{
			$rows = Service_Nj670_Process::filter($rows, $query);
		}

		$limit = max(1, min(200, (int) Arr::get($args, 'limit', 50)));
		$out = [];

		foreach (array_slice($rows, 0, $limit) as $row)
		{
			$out[] = [
				'code'        => (string) $row['code'],
				'title'       => (string) $row['title'],
				'department'  => (string) $row['department'],
				'owner'       => (string) Arr::get($row, 'owner_role_name', ''),
				'is_demo'     => ! empty($row['is_demo']),
				'plan_status' => (string) Arr::get($row, 'plan_status', ''),
				'fact_status' => (string) Arr::get($row, 'fact_status', ''),
				'plan_minutes'=> (int) Arr::get($row, 'plan_minutes', 0),
				'updated_at'  => (string) $row['updated_at'],
			];
		}

		return ['ok' => TRUE, 'count' => count($out), 'processes' => $out];
	}

	public static function mcp_process(array $args)
	{
		list($uid, $workspace, $error) = self::_context($args);

		if ($error)
		{
			return $error;
		}

		list($process, $error) = self::_process_or_error((string) Arr::get($args, 'code', ''), $uid);

		if ($error)
		{
			return $error;
		}

		$ontology = Service_Nj670_Ontology::index((int) $process['workspace_id']);
		$out = [
			'ok' => TRUE,
			'process' => [
				'code'        => (string) $process['code'],
				'title'       => (string) $process['title'],
				'description' => (string) $process['description'],
				'department'  => (string) $process['department'],
				'is_demo'     => ! empty($process['is_demo']),
				'updated_at'  => (string) $process['updated_at'],
			],
		];

		foreach (['plan', 'fact'] as $kind)
		{
			$version = Service_Nj670_Process::current_version($process, $kind);

			if ( ! $version)
			{
				$out[$kind] = NULL;
				continue;
			}

			$graph = Service_Nj670_Process::graph_of($version);

			$out[$kind] = [
				'version_id'  => (int) $version['id'],
				'version_tag' => (string) $version['version_tag'],
				'status'      => (string) $version['status'],
				'source_kind' => (string) $version['source_kind'],
				'graph'       => $graph,
				'metrics'     => Service_Nj670_Graph::metrics($graph, $ontology),
			];
		}

		$out['versions'] = array_map(function ($row) {
			return [
				'id'    => (int) $row['id'],
				'kind'  => (string) $row['kind'],
				'tag'   => (string) $row['version_tag'],
				'status'=> (string) $row['status'],
				'date'  => (string) $row['created_at'],
			];
		}, Service_Nj670_Process::versions((int) $process['id'], NULL, 10));

		return $out;
	}

	public static function mcp_ontology(array $args)
	{
		list($uid, $workspace, $error) = self::_context($args);

		if ($error)
		{
			return $error;
		}

		$grouped = Service_Nj670_Ontology::grouped((int) $workspace['id']);
		$only = trim((string) Arr::get($args, 'kind', ''));
		$out = ['ok' => TRUE, 'kinds' => [], 'entities' => []];

		foreach ($grouped as $kind => $group)
		{
			if ($only !== '' AND $kind !== $only)
			{
				continue;
			}

			$items = [];

			foreach ((array) Arr::get($group, 'items', []) as $item)
			{
				$items[] = [
					'id'         => (int) $item['id'],
					'code'       => (string) $item['code'],
					'name'       => (string) $item['name'],
					'attributes' => (array) Arr::get($item, 'attributes', []),
				];
			}

			$out['kinds'][$kind] = (string) Arr::get($group, 'label', $kind);
			$out['entities'][$kind] = $items;
		}

		return $out;
	}

	public static function mcp_status(array $args)
	{
		list($uid, $workspace, $error) = self::_context($args);

		if ($error)
		{
			return $error;
		}

		list($process, $error) = self::_process_or_error((string) Arr::get($args, 'code', ''), $uid);

		if ($error)
		{
			return $error;
		}

		$payload = Service_Nj670_Process::status_payload($process, $uid);
		$payload['ok'] = TRUE;

		return $payload;
	}

	// ------------------------------------------------------------------ запись

	public static function mcp_create(array $args)
	{
		list($uid, $workspace, $error) = self::_context($args, TRUE, TRUE);

		if ($error)
		{
			return $error;
		}

		$text = trim((string) Arr::get($args, 'text', ''));
		$title = trim((string) Arr::get($args, 'title', ''));

		if ($text === '' AND $title === '')
		{
			return ['error' => 'Нужен title или text: пустой процесс без названия и схемы не создаём.'];
		}

		if ($title === '' AND $text !== '')
		{
			$title = Service_Nj670_Repo::title_from_text($text);
		}

		$created = Service_Nj670_Process::create_with_graph($uid, [
			'title'           => $title,
			'description'     => (string) Arr::get($args, 'description', ''),
			'department'      => (string) Arr::get($args, 'department', ''),
			'owner_role_code' => (string) Arr::get($args, 'owner_role_code', ''),
			'parent_code'     => (string) Arr::get($args, 'parent_code', ''),
			'text'            => $text,
		]);

		if ( ! Arr::get($created, 'ok'))
		{
			return ['error' => (string) Arr::get($created, 'error', 'Не удалось создать процесс.')];
		}

		return [
			'ok'       => TRUE,
			'code'     => (string) $created['code'],
			'id'       => (int) $created['id'],
			'title'    => (string) $created['process']['title'],
			'queued'   => ! empty($created['queued']),
			'job_id'   => (int) Arr::get($created, 'job_id', 0),
			'fallback' => ! empty($created['fallback']),
			'warning'  => (string) Arr::get($created, 'warning', ''),
			'next'     => 'Схему можно дочитать через nj670_process, дополнить — через nj670_save_graph.',
		];
	}

	public static function mcp_save_graph(array $args)
	{
		list($uid, $workspace, $error) = self::_context($args, TRUE, TRUE);

		if ($error)
		{
			return $error;
		}

		list($process, $error) = self::_process_or_error((string) Arr::get($args, 'code', ''), $uid);

		if ($error)
		{
			return $error;
		}

		$kind = (string) Arr::get($args, 'kind', 'plan') === 'fact' ? 'fact' : 'plan';
		$graph = self::_graph_arg($args);

		if (empty($graph['nodes']) OR ! is_array($graph['nodes']))
		{
			return ['error' => 'В графе нет шагов (nodes). Передайте nodes/edges или объект graph.'];
		}

		$status = (string) Arr::get($args, 'status', Service_Nj670_Process::S_DRAFT);
		$allowed = [Service_Nj670_Process::S_DRAFT, Service_Nj670_Process::S_IN_REVIEW];

		if ( ! in_array($status, $allowed, TRUE))
		{
			$status = Service_Nj670_Process::S_DRAFT;
		}

		$created = Service_Nj670_Process::create_version((int) $process['id'], $kind, [
			'status'      => $status,
			'graph'       => $graph,
			'version_tag' => (string) Arr::get($args, 'version_tag', ''),
			'source_kind' => 'mcp',
			'created_by'  => $uid,
			'model'       => 'mcp-agent',
			'activate'    => (bool) Arr::get($args, 'activate', FALSE),
		]);

		if ( ! Arr::get($created, 'ok'))
		{
			return ['error' => (string) Arr::get($created, 'error', 'Не удалось сохранить версию.')];
		}

		$version = $created['version'];
		$saved = Service_Nj670_Process::graph_of($version);

		return [
			'ok'         => TRUE,
			'code'       => (string) $process['code'],
			'kind'       => $kind,
			'version_id' => (int) $created['id'],
			'status'     => (string) $version['status'],
			'nodes'      => count((array) $saved['nodes']),
			'edges'      => count((array) $saved['edges']),
			'metrics'    => Service_Nj670_Graph::metrics($saved, Service_Nj670_Ontology::index((int) $process['workspace_id'])),
			'graph'      => $saved,
		];
	}

	public static function mcp_ontology_upsert(array $args)
	{
		list($uid, $workspace, $error) = self::_context($args, TRUE, TRUE);

		if ($error)
		{
			return $error;
		}

		$kind = (string) Arr::get($args, 'kind', '');
		$types = Service_Nj670_Ontology::entity_types();

		if ( ! isset($types[$kind]))
		{
			return ['error' => 'kind должен быть одним из: '.implode(', ', array_keys($types))];
		}

		$name = trim((string) Arr::get($args, 'name', ''));
		$code = trim((string) Arr::get($args, 'code', ''));
		$id = (int) Arr::get($args, 'id', 0);

		if ($name === '' AND $id <= 0)
		{
			return ['error' => 'Укажите name (и при необходимости code).'];
		}

		if ($name !== '')
		{
			$args['name'] = $name;
		}
		else
		{
			unset($args['name']);
		}

		$done = Service_Nj670_Ontology::upsert((int) $workspace['id'], $args + ['class_type' => $kind]);

		if ( ! Arr::get($done, 'ok'))
		{
			return ['error' => (string) Arr::get($done, 'error', 'Не удалось сохранить сущность.')];
		}

		return [
			'ok'      => TRUE,
			'id'      => (int) $done['id'],
			'updated' => ! empty($done['updated']),
			'kind'    => $kind,
			'code'    => (string) Arr::get($done, 'code', $code),
		];
	}

	public static function mcp_synthesize(array $args)
	{
		list($uid, $workspace, $error) = self::_context($args, TRUE, TRUE);

		if ($error)
		{
			return $error;
		}

		list($process, $error) = self::_process_or_error((string) Arr::get($args, 'code', ''), $uid);

		if ($error)
		{
			return $error;
		}

		$text = trim((string) Arr::get($args, 'text', ''));

		if ($text === '')
		{
			return ['error' => 'Вставьте текст регламента в text.'];
		}

		if ( ! Service_Nj670_Process::worker_online())
		{
			$worker = Service_Nj670_Process::worker_status();

			return ['error' => 'Воркер не в сети: очередь синтеза недоступна. '.$worker['hint']
				.' Передайте готовую схему через nj670_save_graph.'];
		}

		$queued = Service_Nj670_Process::queue_synthesize((int) $process['id'], $uid, [
			'text' => $text,
			'mode' => (string) Arr::get($args, 'mode', 'full') === 'copilot' ? 'copilot' : 'full',
			'instruction' => (string) Arr::get($args, 'instruction', ''),
			'node_id' => (string) Arr::get($args, 'node_id', ''),
		]);

		if ( ! Arr::get($queued, 'ok'))
		{
			return ['error' => (string) Arr::get($queued, 'error', 'Не удалось поставить задачу в очередь.')];
		}

		return [
			'ok'     => TRUE,
			'queued' => TRUE,
			'job_id' => (int) Arr::get($queued, 'job_id', 0),
			'code'   => (string) $process['code'],
			'next'   => 'Статус: nj670_status. Схема появится автоматически после ответа воркера.',
		];
	}
}
