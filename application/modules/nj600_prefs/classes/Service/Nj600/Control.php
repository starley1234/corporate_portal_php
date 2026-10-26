<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Центр контроля администратора: всё о человеке на одной странице.
 *
 * Зачем: у сайта были карточка владельца (nj600), монитор задач (nj120),
 * учёт токенов, модульные админки (graph3d, draw2d…) — и каждый вопрос
 * «что у него произошло» требовал пяти URL. Случай 25.10.2026: админ не мог
 * открыть чужой проект graph3d и не видел, на что ушли токены.
 *
 * Правило: сервис ТОЛЬКО читает и ТОЛЬКО через контракты других модулей
 * (манифест `recent` для объектов, nj_120_usage для токенов, nj_120_jobs для
 * сбоев). Своих таблиц нет. Любой источник может отсутствовать — тогда
 * секция пустая, а не 500.
 */
class Service_Nj600_Control
{
	/** Сколько объектов показывать на модуль. */
	const PER_MODULE = 8;

	protected static function _db()
	{
		return Database::instance();
	}

	/** Ключ владельца `u:<id>` → id, иначе 0 (гость без аккаунта). */
	public static function user_id_of($key)
	{
		return preg_match('/^u:(\d+)$/', (string) $key, $m) ? (int) $m[1] : 0;
	}

	/**
	 * Объекты человека по всем модулям — из контракта `recent` манифестов.
	 *
	 * @return array [['module' => …, 'label' => …, 'icon' => …, 'total' => N,
	 *                 'rows' => [['code','t','status','created_at','href']]], …]
	 */
	public static function objects($key)
	{
		$uid = self::user_id_of($key);

		if ($uid <= 0 OR ! class_exists('Manifest'))
		{
			return [];
		}

		$out = [];

		foreach (Manifest::recent() as $table => $spec)
		{
			$module = (string) Arr::get($spec, 'module', '');
			$status = empty($spec['status']) ? 'NULL AS status' : (string) $spec['status'].' AS status';
			$where  = 'user_id = '.$uid.(empty($spec['where']) ? '' : ' AND ('.$spec['where'].')');

			try
			{
				$total = (int) self::_db()->query(Database::SELECT,
					"SELECT count(*) AS n FROM {$table} WHERE {$where}", FALSE)->get('n', 0);

				if ($total <= 0)
				{
					continue;
				}

				$rows = self::_db()->query(Database::SELECT,
					"SELECT code, {$spec['title']} AS t, {$status}, created_at
					   FROM {$table} WHERE {$where}
					  ORDER BY id DESC LIMIT ".self::PER_MODULE, FALSE)->as_array();
			}
			catch (Throwable $e)
			{
				Kohana::$log->add(Log::WARNING, 'nj600 control: :t: :e', [':t' => $table, ':e' => $e->getMessage()]);

				continue;
			}

			$manifest = Manifest::get($module);
			$list     = [];

			foreach ($rows as $r)
			{
				$r         = (array) $r;
				$r['href'] = (string) $spec['base'].rawurlencode((string) $r['code']);
				$list[]    = $r;
			}

			$out[] = [
				'module' => $module,
				'label'  => (string) Arr::get($manifest, 'title', $module),
				'icon'   => (string) Arr::get($spec, 'icon', 'fas fa-folder'),
				'total'  => $total,
				'rows'   => $list,
			];
		}

		usort($out, function ($a, $b) {
			return $b['total'] <=> $a['total'];
		});

		return $out;
	}

	/**
	 * Токены человека: по каналам (модулям) за всё время и по дням за $days.
	 * Источник — nj_120_usage (с nj120/005 живёт дольше задач).
	 */
	public static function tokens($key, $days = 30)
	{
		$key  = trim((string) $key);
		$days = max(1, min(366, (int) $days));
		$out  = ['channels' => [], 'days' => [], 'total' => 0, 'jobs' => 0, 'errors' => 0];

		if ($key === '')
		{
			return $out;
		}

		$q = self::_db()->quote($key);

		try
		{
			$rows = self::_db()->query(Database::SELECT,
				"SELECT channel, count(*) AS jobs,
				        COALESCE(sum(prompt_tokens), 0) AS prompt_tokens,
				        COALESCE(sum(completion_tokens), 0) AS completion_tokens,
				        COALESCE(sum(had_error), 0) AS errors,
				        max(created_at) AS last_at
				   FROM nj_120_usage WHERE visitor_id = {$q}
				  GROUP BY channel
				  ORDER BY (COALESCE(sum(prompt_tokens), 0) + COALESCE(sum(completion_tokens), 0)) DESC
				  LIMIT 30", FALSE)->as_array();

			foreach ($rows as $r)
			{
				$r          = (array) $r;
				$r['total'] = (int) $r['prompt_tokens'] + (int) $r['completion_tokens'];
				$r['title'] = class_exists('Service_Nj120_JobQueue')
					? (string) Service_Nj120_JobQueue::channel_label((string) $r['channel'])
					: (string) $r['channel'];
				$out['channels'][] = $r;
				$out['total']  += $r['total'];
				$out['jobs']   += (int) $r['jobs'];
				$out['errors'] += (int) $r['errors'];
			}

			$rows = self::_db()->query(Database::SELECT,
				"SELECT to_char(created_at, 'YYYY-MM-DD') AS day, count(*) AS jobs,
				        COALESCE(sum(prompt_tokens + completion_tokens), 0) AS tokens,
				        COALESCE(sum(had_error), 0) AS errors
				   FROM nj_120_usage
				  WHERE visitor_id = {$q} AND created_at >= now() - interval '{$days} days'
				  GROUP BY 1 ORDER BY 1 DESC", FALSE)->as_array();

			foreach ($rows as $r)
			{
				$out['days'][] = (array) $r;
			}
		}
		catch (Throwable $e)
		{
			Kohana::$log->add(Log::WARNING, 'nj600 control tokens: :e', [':e' => $e->getMessage()]);
		}

		return $out;
	}

	/**
	 * Сбои человека: упавшие задачи воркеров с текстом ошибки (последние $limit).
	 * «Жалобы» в явном виде на платформе нет — ближайший сигнал именно этот.
	 */
	public static function failures($key, $limit = 15)
	{
		$key = trim((string) $key);

		if ($key === '')
		{
			return [];
		}

		try
		{
			$rows = self::_db()->query(Database::SELECT,
				'SELECT id, channel, status, error, created_at, finished_at
				   FROM nj_120_jobs
				  WHERE visitor_id = '.self::_db()->quote($key)."
				    AND status IN ('failed', 'cancelled')
				  ORDER BY id DESC LIMIT ".max(1, min(100, (int) $limit)), FALSE)->as_array();
		}
		catch (Throwable $e)
		{
			return [];
		}

		return array_map(function ($r) { return (array) $r; }, $rows);
	}

	/**
	 * Куда ещё смотреть про этого человека: ссылки на модульные админки,
	 * которые существуют на сайте (маршрут проверяем по классу контроллера).
	 */
	public static function links($key)
	{
		$uid   = self::user_id_of($key);
		$links = [];

		if ($uid > 0 AND class_exists('Controller_Nj595_Admin'))
		{
			$links[] = ['href' => '/nj595/admin/user/'.$uid, 'icon' => 'fas fa-id-card', 'label' => 'Аккаунт и роли'];
		}

		if (class_exists('Controller_Nj120_Monitor'))
		{
			$links[] = ['href' => '/nj120/monitor', 'icon' => 'fas fa-list-check', 'label' => 'Монитор задач'];
		}

		if (class_exists('Controller_Nj120_Usage'))
		{
			$links[] = ['href' => '/nj120/usage', 'icon' => 'fas fa-coins', 'label' => 'Токены по платформе'];
		}

		if (class_exists('Controller_Nj667_Graph3d'))
		{
			$links[] = ['href' => '/graph3d/usage?q='.rawurlencode($key), 'icon' => 'fas fa-cubes', 'label' => 'Генерации graph3d'];
		}

		if (class_exists('Controller_Nj100_Errors'))
		{
			$links[] = ['href' => '/nj100/errors', 'icon' => 'fas fa-triangle-exclamation', 'label' => 'Хвост ошибок'];
		}

		return $links;
	}
}
