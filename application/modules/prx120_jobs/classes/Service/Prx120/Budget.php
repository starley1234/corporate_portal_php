<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Бюджеты токенов: сколько человек может потратить за день и месяц.
 *
 * Зачем (25.10.2026): учёт prx_120_usage стал долговечным (prx120/005) — на нём
 * можно держать лимиты по владельцу. Это первый кирпич «тарифов»: роль даёт
 * умолчание (config/prx120_budget.php), администратор может выдать
 * индивидуальный бюджет (/prx120/budgets), при 90 % владелец получает
 * предупреждение, при 100 % очередь не принимает новые задачи.
 *
 * Ключ владельца — `visitor_id` очереди: `u:<id>` для аккаунта, иначе кука
 * гостя. Все методы читают только prx_120_usage / prx_120_budgets; при
 * отсутствии таблиц (миграция не применена) бюджет считается безлимитным —
 * учёт не должен ронять постановку задач.
 */
class Service_Prx120_Budget
{
	/** Значение «без лимита» в prx_120_budgets и в ответах. */
	const UNLIMITED = 0;

	/** Индивидуальная строка «безлимит» (0 означало бы «по роли»). */
	const OVERRIDE_UNLIMITED = -1;

	protected static $_cfg = NULL;

	/** Кэш состояния на запрос: push() и виджеты зовут по нескольку раз. */
	protected static $_state = [];

	protected static function _db()
	{
		return Database::instance();
	}

	public static function config($key = NULL, $default = NULL)
	{
		if (self::$_cfg === NULL)
		{
			$cfg = Kohana::$config->load('prx120_budget');
			self::$_cfg = $cfg ? $cfg->as_array() : [];
		}

		return $key === NULL ? self::$_cfg : Arr::get(self::$_cfg, $key, $default);
	}

	/** `u:<id>` → id, иначе 0. */
	public static function user_id_of($key)
	{
		return preg_match('/^u:(\d+)$/', (string) $key, $m) ? (int) $m[1] : 0;
	}

	/**
	 * Роль владельца для умолчаний: самая щедрая из его ролей по `rank`.
	 */
	public static function role_of($key)
	{
		// `s:<модуль>` — служебные задачи сайта (контент-конвейер prx602, сжатие
		// профилей prx600, пиналка prx603): у них нет человека-владельца, и считать
		// их гостем с 30 тыс./день нельзя — так 25.10 встал весь /content.
		// Роль `system`: по умолчанию без лимита, правится в /prx120/budgets.
		if (strpos((string) $key, 's:') === 0)
		{
			return 'system';
		}

		$uid = self::user_id_of($key);

		if ($uid <= 0)
		{
			return 'anon';
		}

		$rank  = (array) self::config('rank', ['anon', 'login', 'vip', 'admin']);
		$best  = 'login';
		$roles = [];

		try
		{
			$rows = self::_db()->query(Database::SELECT,
				'SELECT r.name FROM roles_users ru JOIN roles r ON r.id = ru.role_id WHERE ru.user_id = '.$uid, FALSE)->as_array();

			foreach ($rows as $r)
			{
				$roles[] = (string) Arr::get((array) $r, 'name');
			}
		}
		catch (Throwable $e)
		{
			return $best;
		}

		foreach ($rank as $role)
		{
			if (in_array($role, $roles, TRUE))
			{
				$best = $role;
			}
		}

		return $best;
	}

	/**
	 * Лимиты владельца: ['daily' => N, 'monthly' => N, 'source' => 'role:vip'|'override', 'role' => …].
	 * 0 — без лимита.
	 */
	public static function limits($key)
	{
		$key  = trim((string) $key);
		$role = self::role_of($key);
		$out  = [
			'daily'   => (int) Arr::get((array) self::config('daily', []), $role, 0),
			'monthly' => (int) Arr::get((array) self::config('monthly', []), $role, 0),
			'source'  => 'role:'.$role,
			'role'    => $role,
			'note'    => '',
		];

		if ($key === '')
		{
			return $out;
		}

		try
		{
			$row = self::_db()->query(Database::SELECT,
				'SELECT daily_tokens, monthly_tokens, note FROM prx_120_budgets WHERE visitor_id = '.self::_db()->quote($key), FALSE)->current();
		}
		catch (Throwable $e)
		{
			$row = NULL;
		}

		if ($row)
		{
			$row = (array) $row;

			foreach (['daily' => 'daily_tokens', 'monthly' => 'monthly_tokens'] as $k => $col)
			{
				$v = (int) $row[$col];

				if ($v === self::OVERRIDE_UNLIMITED)
				{
					$out[$k] = self::UNLIMITED;
					$out['source'] = 'override';
				}
				elseif ($v > 0)
				{
					$out[$k] = $v;
					$out['source'] = 'override';
				}
			}

			$out['note'] = (string) $row['note'];
		}

		return $out;
	}

	/** Потрачено: ['day' => N, 'month' => N] токенов по prx_120_usage. */
	public static function spent($key)
	{
		$key = trim((string) $key);

		if ($key === '')
		{
			return ['day' => 0, 'month' => 0];
		}

		try
		{
			$row = self::_db()->query(Database::SELECT,
				"SELECT COALESCE(sum(CASE WHEN created_at >= date_trunc('day', now()) THEN prompt_tokens + completion_tokens END), 0) AS day,
				        COALESCE(sum(prompt_tokens + completion_tokens), 0) AS month
				   FROM prx_120_usage
				  WHERE visitor_id = ".self::_db()->quote($key)."
				    AND created_at >= date_trunc('month', now())", FALSE)->current();
		}
		catch (Throwable $e)
		{
			return ['day' => 0, 'month' => 0];
		}

		return ['day' => (int) Arr::get((array) $row, 'day', 0), 'month' => (int) Arr::get((array) $row, 'month', 0)];
	}

	/**
	 * Полное состояние для интерфейса и проверок.
	 *
	 * @return array ['key','role','source','note','daily'=>['limit','spent','pct','left'],
	 *                'monthly'=>[…],'over'=>bool,'warn'=>bool,'pct'=>max pct]
	 */
	public static function state($key, $fresh = FALSE)
	{
		$key = trim((string) $key);

		if ( ! $fresh AND isset(self::$_state[$key]))
		{
			return self::$_state[$key];
		}

		$limits = self::limits($key);
		$spent  = self::spent($key);
		$warn   = max(1, (int) self::config('warn_pct', 90));
		$out    = [
			'key'    => $key,
			'role'   => $limits['role'],
			'source' => $limits['source'],
			'note'   => $limits['note'],
			'over'   => FALSE,
			'warn'   => FALSE,
			'pct'    => 0,
		];

		foreach (['daily' => 'day', 'monthly' => 'month'] as $k => $sk)
		{
			$limit = (int) $limits[$k];
			$used  = (int) $spent[$sk];
			$pct   = $limit > 0 ? (int) floor(100 * $used / $limit) : 0;
			$out[$k] = [
				'limit' => $limit,
				'spent' => $used,
				'pct'   => $pct,
				'left'  => $limit > 0 ? max(0, $limit - $used) : -1,
			];

			if ($limit > 0)
			{
				$out['pct']  = max($out['pct'], $pct);
				$out['over'] = ($out['over'] OR $used >= $limit);
				$out['warn'] = ($out['warn'] OR $pct >= $warn);
			}
		}

		return self::$_state[$key] = $out;
	}

	/**
	 * Можно ли ставить задачу. ['ok' => bool, 'message' => …, 'state' => …].
	 * Учитывает выключатель `enforce`.
	 */
	public static function check($key)
	{
		$state = self::state($key);

		if ( ! $state['over'] OR ! self::config('enforce', TRUE))
		{
			return ['ok' => TRUE, 'message' => '', 'state' => $state];
		}

		$which = ($state['daily']['limit'] > 0 AND $state['daily']['spent'] >= $state['daily']['limit'])
			? 'Дневной' : 'Месячный';

		return [
			'ok'      => FALSE,
			'message' => $which.' бюджет токенов исчерпан ('.self::fmt($which === 'Дневной' ? $state['daily']['spent'] : $state['monthly']['spent'])
				.' из '.self::fmt($which === 'Дневной' ? $state['daily']['limit'] : $state['monthly']['limit']).'). '
				.($which === 'Дневной' ? 'Новые задачи — завтра' : 'Новые задачи — со следующего месяца')
				.($state['role'] === 'anon' ? '; войдите в аккаунт, чтобы получить больший бюджет.'
					: ' или расширьте бюджет ключа «'.$key.'» в /prx120/budgets.'),
			'state'   => $state,
		];
	}

	/**
	 * Хук после записи учёта задачи: пересчитать и предупредить владельца
	 * (колокольчик prx597 — только для аккаунтов), по одному разу на порог и период.
	 */
	public static function after_usage($key)
	{
		$key = trim((string) $key);

		if ($key === '')
		{
			return;
		}

		$state = self::state($key, TRUE);

		if ( ! $state['warn'])
		{
			return;
		}

		$level  = $state['over'] ? 100 : (int) self::config('warn_pct', 90);
		$period = ($state['daily']['limit'] > 0 AND $state['daily']['pct'] >= (int) self::config('warn_pct', 90))
			? date('Y-m-d') : date('Y-m');

		if ( ! self::_mark_alert($key, $period, $level))
		{
			return;
		}

		$uid = self::user_id_of($key);
		$msg = $state['over']
			? 'Бюджет токенов исчерпан: '.self::fmt($state['daily']['spent']).' за сегодня'
				.($state['monthly']['limit'] > 0 ? ', '.self::fmt($state['monthly']['spent']).' за месяц' : '').'. Новые задачи очередь не примет.'
			: 'Израсходовано '.$state['pct'].' % бюджета токенов ('.self::fmt($state['daily']['spent']).' из '.self::fmt($state['daily']['limit']).' за день). '
				.'Осталось '.self::fmt(max($state['daily']['left'], 0)).'.';

		Kohana::$log->add(Log::NOTICE, 'prx120 budget: :k :m', [':k' => $key, ':m' => $msg]);

		if ($uid > 0 AND class_exists('Service_Prx597_Notify'))
		{
			try
			{
				Service_Prx597_Notify::notify($uid, 'system',
					$state['over'] ? 'Бюджет токенов исчерпан' : 'Бюджет токенов почти исчерпан', $msg, '/tasks');
			}
			catch (Throwable $e)
			{
				Kohana::$log->add(Log::WARNING, 'prx120 budget notify: :e', [':e' => $e->getMessage()]);
			}
		}
	}

	/** TRUE — отметка поставлена впервые (надо уведомлять). */
	protected static function _mark_alert($key, $period, $level)
	{
		try
		{
			// SELECT + RETURNING: драйвер отдаёт строку только если вставка была
			$row = self::_db()->query(Database::SELECT,
				'INSERT INTO prx_120_budget_alerts (visitor_id, period, level) VALUES ('
					.self::_db()->quote($key).', '.self::_db()->quote($period).', '.(int) $level.')
				 ON CONFLICT DO NOTHING RETURNING visitor_id', FALSE)->current();

			return (bool) $row;
		}
		catch (Throwable $e)
		{
			return FALSE;
		}
	}

	// ------------------------------------------------------------- админ

	/** Сырая индивидуальная строка владельца (для формы) или NULL. */
	public static function override($key)
	{
		try
		{
			$row = self::_db()->query(Database::SELECT,
				'SELECT visitor_id, daily_tokens, monthly_tokens, note FROM prx_120_budgets WHERE visitor_id = '
					.self::_db()->quote(trim((string) $key)), FALSE)->current();
		}
		catch (Throwable $e)
		{
			return NULL;
		}

		return $row ? (array) $row : NULL;
	}

	/** Индивидуальные бюджеты с текущим расходом (для /prx120/budgets). */
	public static function overrides($limit = 200)
	{
		try
		{
			$rows = self::_db()->query(Database::SELECT,
				'SELECT b.visitor_id, b.daily_tokens, b.monthly_tokens, b.note, b.updated_at,
				        COALESCE(us.username, b.visitor_id) AS who
				   FROM prx_120_budgets b
				   LEFT JOIN users us ON b.visitor_id = \'u:\' || us.id::text
				  ORDER BY b.updated_at DESC LIMIT '.max(1, min(1000, (int) $limit)), FALSE)->as_array();
		}
		catch (Throwable $e)
		{
			return [];
		}

		$out = [];

		foreach ($rows as $r)
		{
			$r          = (array) $r;
			$r['state'] = self::state((string) $r['visitor_id']);
			$out[]      = $r;
		}

		return $out;
	}

	/**
	 * Кто сегодня ближе всех к потолку (по умолчаниям ролей и индивидуальным).
	 * Берём топ трат за день и считаем состояние каждому.
	 */
	public static function top_today($limit = 20)
	{
		try
		{
			$rows = self::_db()->query(Database::SELECT,
				"SELECT u.visitor_id, COALESCE(us.username, u.visitor_id) AS who,
				        sum(u.prompt_tokens + u.completion_tokens) AS tokens, count(*) AS jobs
				   FROM prx_120_usage u
				   LEFT JOIN users us ON u.visitor_id = 'u:' || us.id::text
				  WHERE u.created_at >= date_trunc('day', now()) AND u.visitor_id <> ''
				  GROUP BY 1, 2 ORDER BY 3 DESC LIMIT ".max(1, min(200, (int) $limit)), FALSE)->as_array();
		}
		catch (Throwable $e)
		{
			return [];
		}

		$out = [];

		foreach ($rows as $r)
		{
			$r          = (array) $r;
			$r['state'] = self::state((string) $r['visitor_id']);
			$out[]      = $r;
		}

		usort($out, function ($a, $b) { return $b['state']['pct'] <=> $a['state']['pct']; });

		return $out;
	}

	/**
	 * Сохранить индивидуальный бюджет. daily/monthly: 0 — по роли, -1 — без
	 * лимита, N — токенов. Обе по роли ⇒ строка удаляется.
	 */
	public static function save($key, $daily, $monthly, $note, $admin_id)
	{
		$key = trim((string) $key);

		if ($key === '' OR UTF8::strlen($key) > 64)
		{
			return ['ok' => FALSE, 'error' => 'Ключ владельца — u:<id> или кука гостя (до 64 символов)'];
		}

		$daily   = max(-1, (int) $daily);
		$monthly = max(-1, (int) $monthly);
		$note    = UTF8::substr(trim((string) $note), 0, 200);
		$db      = self::_db();

		try
		{
			if ($daily === 0 AND $monthly === 0)
			{
				$db->query(Database::DELETE, 'DELETE FROM prx_120_budgets WHERE visitor_id = '.$db->quote($key), FALSE);
			}
			else
			{
				$db->query(Database::INSERT,
					'INSERT INTO prx_120_budgets (visitor_id, daily_tokens, monthly_tokens, note, updated_by, updated_at)
					 VALUES ('.$db->quote($key).', '.$daily.', '.$monthly.', '.$db->quote($note).', '.((int) $admin_id ?: 'NULL').', now())
					 ON CONFLICT (visitor_id) DO UPDATE SET daily_tokens = EXCLUDED.daily_tokens,
					     monthly_tokens = EXCLUDED.monthly_tokens, note = EXCLUDED.note,
					     updated_by = EXCLUDED.updated_by, updated_at = now()', FALSE);
			}
		}
		catch (Throwable $e)
		{
			return ['ok' => FALSE, 'error' => 'Не сохранилось: '.$e->getMessage()];
		}

		unset(self::$_state[$key]);

		return ['ok' => TRUE, 'error' => ''];
	}

	/** 1 234 567 → «1,2 млн», 45 300 → «45 тыс.», меньше — число. */
	public static function fmt($n)
	{
		$n = (int) $n;

		if ($n >= 1000000)
		{
			return str_replace('.', ',', (string) round($n / 1000000, 1)).' млн';
		}

		if ($n >= 10000)
		{
			return (string) round($n / 1000).' тыс.';
		}

		return number_format($n, 0, ',', ' ');
	}
}
