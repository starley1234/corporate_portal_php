<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-667 «Графовый 3D-конструктор»: единственная дверь в модуль.
 *
 * Модель данных — граф: узлы (nodes) и ребра (edges) в jsonb. Модель строит
 * его по описанию на естественном языке, браузер рисует на WebGL и даёт
 * править руками. История правок — патчами RFC 6902, поэтому undo/redo и
 * ветвление не стоят копии графа на каждый чих.
 *
 * Чистый граф (без БД) живёт в Service_Nj667_Graph, чертёж — в
 * Service_Nj667_Drawing. Здесь — дверь: те же имена методов, плюс проекты,
 * очередь, фото, доступ. Стенд зовёт дверь, не внутренности.
 *
 * Синтез и разбор: граф приходит от воркера (каналы nj667.*), сайт его
 * чистит, проверяет (AI-Guard) и складывает в историю.
 */
class Service_Nj667_Graph3d
{
	const S_DRAFT     = 'draft';
	const S_REVIEW    = 'review';
	const S_PUBLISHED = 'published';
	const S_REJECTED  = 'rejected';

	const CH_GENERATE = 'nj667.generate';
	const CH_INPAINT  = 'nj667.inpaint';
	const CH_SUMMARY  = 'nj667.summary';
	const CH_EMBED    = 'nj667.embed';
	const CH_MODERATE = 'nj667.moderate';
	const CH_IDEAS    = 'nj667.ideas';
	const CH_CAPTION  = 'nj667.caption';
	// Рендер STL: не LLM, а OpenSCAD в воркере. Модель для канала не выбираем.
	const CH_BUILD    = 'nj667.build';

	const QUOTA_LLM_OPERATION = 'graph_llm';

	const PHOTO_MAX       = 4;
	const PHOTO_MAX_BYTES = 5242880;
	const PHOTO_MAX_SIDE  = 1280;

	// ---------------------------------------------------------------- конфиг

	public static function config()
	{
		return (array) Kohana::$config->load('nj667_graph3d')->as_array();
	}

	public static function branch($name, $default = [])
	{
		return (array) Arr::get(self::config(), $name, $default);
	}

	public static function limits($key = NULL, $default = NULL)
	{
		$limits = self::branch('limits');

		return $key === NULL ? $limits : Arr::get($limits, $key, $default);
	}

	/** Кэш пользовательских overrides на время запроса. */
	protected static $_quota_override_cache = [];
	protected static $_quota_override_table = NULL;

	/**
	 * Единый резолвер пользовательских квот. План задаёт базовые лимиты,
	 * сохранённые overrides точечно заменяют активные проекты и LLM/сутки.
	 */
	public static function quota_limits($user_id = 0)
	{
		return self::quota_limits_resolve($user_id, self::quota_overrides($user_id));
	}

	/** Чистый резолвер: нужен странице лимитов и проверкам без подключения к БД. */
	public static function quota_limits_resolve($user_id = 0, array $overrides = [])
	{
		$cfg          = self::branch('quotas', []);
		$default_plan = trim((string) Arr::get($cfg, 'default_plan', 'registered'));

		if ($default_plan === '')
		{
			$default_plan = 'registered';
		}

		$plans      = (array) Arr::get($cfg, 'plans', []);
		$default    = (array) Arr::get($plans, $default_plan, []);
		$user_plans = (array) Arr::get($cfg, 'user_plans', []);
		$plan       = (string) Arr::get($user_plans, (string) max(0, (int) $user_id), $default_plan);
		$selected   = (array) Arr::get($plans, $plan, $default);
		$resolved   = array_merge($default, $selected);
		$limits     = [
			'llm_operations_per_day' => max(0, (int) Arr::get($resolved, 'llm_operations_per_day', 5)),
			'active_projects'        => max(0, (int) Arr::get($resolved, 'active_projects', 5)),
			'historical_revisions'   => max(0, (int) Arr::get($resolved, 'historical_revisions', 3)),
			'build_parts_per_run'    => max(1, (int) Arr::get($resolved, 'build_parts_per_run', 30)),
		];

		foreach (['active_projects', 'llm_operations_per_day'] as $key)
		{
			if (array_key_exists($key, $overrides) AND $overrides[$key] !== NULL AND $overrides[$key] !== '')
			{
				$limits[$key] = max(0, (int) $overrides[$key]);
			}
		}

		return $limits;
	}

	/** Индивидуальные лимиты пользователя; при отсутствии миграции работает профильный default. */
	public static function quota_overrides($user_id)
	{
		$user_id = (int) $user_id;

		if ($user_id <= 0)
		{
			return [];
		}

		if (array_key_exists($user_id, self::$_quota_override_cache))
		{
			return self::$_quota_override_cache[$user_id];
		}

		if (self::$_quota_override_table === FALSE)
		{
			return [];
		}

		try
		{
			$row = self::_one('SELECT active_projects, llm_operations_per_day
				FROM nj_667_quota_overrides WHERE user_id = '.$user_id.' LIMIT 1');
			self::$_quota_override_table = TRUE;
		}
		catch (Throwable $e)
		{
			self::$_quota_override_table = FALSE;
			self::_log(Log::WARNING, 'индивидуальные квоты не читаются: :e', [':e' => $e->getMessage()]);
			self::$_quota_override_cache[$user_id] = [];

			return [];
		}

		$overrides = [];

		foreach (['active_projects', 'llm_operations_per_day'] as $key)
		{
			if (is_array($row) AND Arr::get($row, $key) !== NULL)
			{
				$overrides[$key] = max(0, (int) $row[$key]);
			}
		}

		self::$_quota_override_cache[$user_id] = $overrides;

		return $overrides;
	}

	/**
	 * Порядок списка лимитов: ключ → ORDER BY. Белый список, а не склейка
	 * строки из запроса: страница открыта модератору, но SQL собирать
	 * из параметра нельзя даже ему.
	 */
	public static function quota_sorts()
	{
		return [
			'new'     => 'Сначала новые (ID)',
			'old'     => 'Сначала старые (ID)',
			'email'   => 'По имени и почте',
			'changed' => 'Кто менялся последним',
		];
	}

	/** Список пользователей для модераторской страницы индивидуальных квот. */
	public static function quota_override_users($query = '', $limit = 30, $offset = 0, $sort = 'new')
	{
		$query  = UTF8::substr(trim((string) $query), 0, 120);
		$limit  = max(1, min(200, (int) $limit));
		$offset = max(0, (int) $offset);
		$where  = '';
		$order  = [
			'new'     => 'u.id DESC',
			'old'     => 'u.id ASC',
			'email'   => 'COALESCE(u.username, \'\') ASC, u.email ASC',
			'changed' => 'q.updated_at DESC NULLS LAST, u.id DESC',
		];
		$sort_key = isset($order[$sort]) ? (string) $sort : 'new';
		$sort_sql = $order[$sort_key];

		if ($query !== '')
		{
			$like = self::_q('%'.$query.'%');
			$where = ' WHERE (u.email ILIKE '.$like.' OR u.username ILIKE '.$like
				.' OR CAST(u.id AS text) ILIKE '.$like.')';
		}

		try
		{
			$total = self::_value('SELECT count(*) FROM users u'.$where);
			$rows  = self::_all('SELECT u.id, u.email, u.username,
				q.active_projects, q.llm_operations_per_day, q.updated_at, q.updated_by
				FROM users u LEFT JOIN nj_667_quota_overrides q ON q.user_id = u.id'
				.$where.' ORDER BY '.$sort_sql.' LIMIT '.$limit.' OFFSET '.$offset);

			return ['ok' => TRUE, 'total' => $total, 'rows' => $rows, 'sort' => $sort_key];
		}
		catch (Throwable $e)
		{
			self::_log(Log::WARNING, 'список индивидуальных квот не читается: :e', [':e' => $e->getMessage()]);

			return ['ok' => FALSE, 'total' => 0, 'rows' => [],
				'error' => 'Не удалось прочитать пользователей или лимиты. Примените миграцию NJ-667/014.'];
		}
	}

	/** Сохранить индивидуальные overrides; пустое поле возвращает лимит к плану. */
	public static function quota_override_set($user_id, array $values, $updated_by = 0)
	{
		$user_id = (int) $user_id;

		if ($user_id <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Пользователь не найден.'];
		}

		$clean = [];

		foreach (['active_projects', 'llm_operations_per_day'] as $key)
		{
			$value = Arr::get($values, $key, NULL);

			if ($value === NULL OR $value === '')
			{
				$clean[$key] = NULL;
				continue;
			}

			if ( ! is_numeric($value) OR (string) (int) $value !== (string) $value
				OR (int) $value < 0 OR (int) $value > 100000)
			{
				return ['ok' => FALSE, 'error' => 'Лимиты задаются целыми числами от 0 до 100 000 или пустым значением.'];
			}

			$clean[$key] = (int) $value;
		}

		try
		{
			if ($clean['active_projects'] === NULL AND $clean['llm_operations_per_day'] === NULL)
			{
				self::_exec('DELETE FROM nj_667_quota_overrides WHERE user_id = '.$user_id);
				$clean = [];
			}
			else
			{
				self::_exec('INSERT INTO nj_667_quota_overrides
					(user_id, active_projects, llm_operations_per_day, updated_by, updated_at)
					VALUES ('.$user_id.', '
					.($clean['active_projects'] === NULL ? 'NULL' : (int) $clean['active_projects']).', '
					.($clean['llm_operations_per_day'] === NULL ? 'NULL' : (int) $clean['llm_operations_per_day']).', '
					.max(0, (int) $updated_by).', now())
					ON CONFLICT (user_id) DO UPDATE SET
					active_projects = EXCLUDED.active_projects,
					llm_operations_per_day = EXCLUDED.llm_operations_per_day,
					updated_by = EXCLUDED.updated_by, updated_at = now()');
			}

			self::$_quota_override_table = TRUE;
			self::$_quota_override_cache[$user_id] = $clean;

			return ['ok' => TRUE, 'overrides' => $clean];
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'индивидуальные квоты пользователя :id не сохранены: :e',
				[':id' => $user_id, ':e' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Не удалось сохранить лимиты. Примените миграцию NJ-667/014.'];
		}
	}

	/** Счётчик дневных платных операций для страницы и API. Дата — календарный UTC-день. */
	public static function llm_quota_status($user_id)
	{
		$user_id = (int) $user_id;
		$limit   = (int) Arr::get(self::quota_limits($user_id), 'llm_operations_per_day', 5);
		$status  = self::llm_quota_state(0, $limit);

		if ($user_id <= 0)
		{
			$status['ready']    = FALSE;
			$status['allowed']  = FALSE;
			$status['remaining'] = 0;
			$status['error']    = 'Для дневной квоты нужен аккаунт.';

			return $status;
		}

		try
		{
			$row = self::_one('SELECT COALESCE((
					SELECT used FROM nj_667_daily_usage
					WHERE user_id = '.$user_id.'
						AND usage_day = (now() AT TIME ZONE \'UTC\')::date
						AND operation = '.self::_q(self::QUOTA_LLM_OPERATION).'
				), 0) AS used,
				to_char((now() AT TIME ZONE \'UTC\')::date, \'YYYY-MM-DD\') AS usage_day');

			$status = self::llm_quota_state((int) Arr::get($row, 'used', 0), $limit);
			$status['day'] = (string) Arr::get($row, 'usage_day', gmdate('Y-m-d'));

			return $status;
		}
		catch (Throwable $e)
		{
			$status['ready']     = FALSE;
			$status['allowed']   = FALSE;
			$status['remaining'] = 0;
			$status['error']     = 'Дневной учёт квоты недоступен. Нужна миграция NJ-667/012.';
			self::_log(Log::WARNING, 'дневная квота пользователя :id не читается: :e',
				[':id' => $user_id, ':e' => $e->getMessage()]);

			return $status;
		}
	}

	/** Чистая часть статуса квоты — используется стендом без БД. */
	public static function llm_quota_state($used, $limit)
	{
		$used  = max(0, (int) $used);
		$limit = max(0, (int) $limit);

		return [
			'ready'     => TRUE,
			'allowed'   => ($limit > 0 AND $used < $limit),
			'used'      => $used,
			'limit'     => $limit,
			'remaining' => max(0, $limit - $used),
			'day'       => gmdate('Y-m-d'),
			'timezone'  => 'UTC',
		];
	}

	/** Только генерация и LLM-достройка графа занимают пользовательскую квоту. */
	public static function counts_toward_llm_quota($channel)
	{
		return in_array((string) $channel, [self::CH_GENERATE, self::CH_INPAINT], TRUE);
	}

	/** Сколько активных (не удалённых) проектов у пользователя сейчас. */
	public static function active_project_count($user_id)
	{
		return (int) self::_value('SELECT count(*) FROM nj_667_projects
			WHERE deleted_at IS NULL AND user_id = '.max(0, (int) $user_id));
	}

	/**
	 * Те же числа, но одной выборкой на всю страницу: страница лимитов
	 * рассчитана на сотни людей, и запрос на строку — это сотни запросов
	 * ради таблицы. FALSE — «прочитать не удалось» (тогда вызывающий берёт
	 * поштучный путь и не врёт нулями).
	 */
	public static function active_project_counts(array $user_ids)
	{
		$ids = self::_clean_ids($user_ids);

		if ( ! $ids)
		{
			return [];
		}

		try
		{
			$counts = [];

			foreach (self::_all('SELECT user_id, count(*) AS c FROM nj_667_projects
				WHERE deleted_at IS NULL AND user_id IN ('.implode(',', $ids).')
				GROUP BY user_id') as $row)
			{
				$counts[(int) $row['user_id']] = (int) $row['c'];
			}

			return $counts;
		}
		catch (Throwable $e)
		{
			self::_log(Log::WARNING, 'счётчики проектов страницы лимитов не читаются: :e', [':e' => $e->getMessage()]);

			return FALSE;
		}
	}

	/**
	 * Сколько LLM-операций потратил каждый из названных людей за сегодня (UTC).
	 * FALSE — ошибка чтения, см. active_project_counts().
	 */
	public static function llm_usage_counts(array $user_ids)
	{
		$ids = self::_clean_ids($user_ids);

		if ( ! $ids)
		{
			return [];
		}

		try
		{
			$used = [];

			foreach (self::_all('SELECT u.user_id, COALESCE(d.used, 0) AS used
				FROM unnest(ARRAY['.implode(',', $ids).']) AS u(user_id)
				LEFT JOIN nj_667_daily_usage d
					ON d.user_id = u.user_id
					AND d.usage_day = (now() AT TIME ZONE \'UTC\')::date
					AND d.operation = '.self::_q(self::QUOTA_LLM_OPERATION).'
				') as $row)
			{
				$used[(int) $row['user_id']] = (int) $row['used'];
			}

			return $used;
		}
		catch (Throwable $e)
		{
			self::_log(Log::WARNING, 'расход LLM страницы лимитов не читается: :e', [':e' => $e->getMessage()]);

			return FALSE;
		}
	}

	/** Числа из списка id: без нулей, дублей и мусора (для IN (...)). */
	protected static function _clean_ids(array $user_ids)
	{
		$ids = [];

		foreach ($user_ids as $id)
		{
			$id = (int) $id;

			if ($id > 0)
			{
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	/** Верхняя граница списка типов на одну задачу подготовки деталей. */
	public static function build_parts_run_limit($user_id = 0)
	{
		$quota = (int) Arr::get(self::quota_limits($user_id), 'build_parts_per_run', 30);
		$config = class_exists('Service_Nj667_Build')
			? (int) Service_Nj667_Build::limit('parts_max', $quota)
			: $quota;

		if ($config <= 0)
		{
			$config = $quota;
		}

		return max(1, min($quota, $config));
	}

	public static function node_types() { return (array) Arr::get(self::config(), 'node_types', []); }
	public static function edge_types() { return (array) Arr::get(self::config(), 'edge_types', []); }
	public static function statuses()   { return (array) Arr::get(self::config(), 'statuses', []); }
	public static function defaults($key = NULL, $default = NULL)
	{
		$defaults = self::branch('defaults');

		return $key === NULL ? $defaults : Arr::get($defaults, $key, $default);
	}

	public static function canvas($key = NULL, $default = NULL)
	{
		$canvas = self::branch('canvas');

		return $key === NULL ? $canvas : Arr::get($canvas, $key, $default);
	}

	public static function timeout($channel_key)
	{
		return (int) Arr::get(self::branch('timeout'), $channel_key, 300);
	}

	/**
	 * Пре-модерация перед каталогом. По умолчанию выкл (владелец, 11.10.2026).
	 * Включается только явно: NJ667_PREMODERATE=1 (true/on/yes).
	 */
	public static function premoderate()
	{
		$raw = strtolower(trim((string) getenv('NJ667_PREMODERATE')));

		if ($raw !== '')
		{
			return in_array($raw, ['1', 'true', 'on', 'yes'], TRUE);
		}

		return (bool) Arr::get(self::config(), 'premoderate', FALSE);
	}

	/**
	 * Список моделей для админки: «провайдер::имя» из config('models').
	 *
	 * Разделители — «;», запятая, перевод строки и «|», как в nj662: владелец
	 * может оформить строку как угодно, лишь бы записи читались.
	 *
	 * @return array [['spec','provider','model','label'], …]
	 */
	public static function models()
	{
		$offers = [];
		$seen   = [];

		foreach (preg_split('/[;,\n|]+/u', (string) Arr::get(self::config(), 'models', '')) as $part)
		{
			$spec = trim((string) $part);

			if ($spec === '' OR strpos($spec, '::') === FALSE)
			{
				continue;
			}

			list($provider, $model) = explode('::', $spec, 2);
			$provider = trim($provider);
			$model    = trim($model);
			$spec     = $provider.'::'.$model;

			if ($provider === '' OR $model === '' OR isset($seen[$spec]))
			{
				continue;
			}

			$seen[$spec] = TRUE;
			$offers[] = [
				'spec'     => $spec,
				'provider' => $provider,
				'model'    => $model,
				'label'    => $model.' ('.$provider.')',
			];
		}

		return $offers;
	}

	/**
	 * Выбранная модель генерации.
	 *
	 * Сначала строка из nj_667_settings (админка), иначе NJ667_MODEL из
	 * окружения сайта. Пусто — воркер возьмёт свою модель по умолчанию.
	 * Стенд и сайт без таблицы это переживают: ловим исключение и идём дальше.
	 */
	public static function model()
	{
		try
		{
			$row   = self::_one("SELECT value FROM nj_667_settings WHERE key = 'model' LIMIT 1");
			$value = UTF8::substr(trim((string) Arr::get($row ?: [], 'value', '')), 0, 160);

			if ($value !== '')
			{
				return $value;
			}
		}
		catch (Throwable $e)
		{
			// Таблица ещё не накатана или стенд без БД — не повод падать.
		}

		return UTF8::substr(trim((string) (getenv('NJ667_MODEL') ?: '')), 0, 160);
	}

	/**
	 * Дешёвая модель для названия, описания, тегов и прочих второстепенных
	 * вызовов. NJ667_LIGHT_MODEL из .env, иначе та же, что model()
	 * (владелец, 11.10.2026).
	 */
	public static function light_model()
	{
		$own = UTF8::substr(trim((string) (getenv('NJ667_LIGHT_MODEL') ?: '')), 0, 160);

		if ($own !== '')
		{
			return $own;
		}

		return self::model();
	}

	/**
	 * Записать выбранную модель генерации в nj_667_settings.
	 *
	 * Не путать с set_model($project_id, $model) ниже: тот пишет в проект,
	 * какая модель его собрала (журнал/каталог). Здесь — настройка админки.
	 * Пустая строка — «модель воркера по умолчанию».
	 */
	public static function set_generation_model($spec)
	{
		$spec = UTF8::substr(trim((string) $spec), 0, 160);

		if ($spec !== '')
		{
			$known = FALSE;

			foreach (self::models() as $row)
			{
				if ((string) Arr::get($row, 'spec') === $spec)
				{
					$known = TRUE;
					break;
				}
			}

			if ( ! $known AND ! preg_match('~^[A-Za-z0-9_.-]+::[A-Za-z0-9_./+-]+$~', $spec))
			{
				return ['ok' => FALSE, 'error' => 'Модель — строка «провайдер::имя», например local::qwen3-coder-30b-a3b-instruct.'];
			}
		}

		try
		{
			self::_exec("INSERT INTO nj_667_settings (key, value, updated_at)
				VALUES ('model', ".self::_q($spec).", now())
				ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()");
		}
		catch (Throwable $e)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось сохранить модель: '.$e->getMessage()];
		}

		return ['ok' => TRUE, 'model' => $spec];
	}

	/**
	 * Строка из nj_667_settings. Нет таблицы или ключа — $default.
	 * Стенд без БД это переживает.
	 */
	public static function setting($key, $default = '')
	{
		$key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));

		if ($key === '')
		{
			return (string) $default;
		}

		try
		{
			$row = self::_one('SELECT value FROM nj_667_settings WHERE key = '.self::_q($key).' LIMIT 1');

			if ($row !== NULL AND array_key_exists('value', $row))
			{
				return (string) $row['value'];
			}
		}
		catch (Throwable $e)
		{
			// Таблица ещё не накатана или стенд без БД.
		}

		return (string) $default;
	}

	/** Записать ключ админки. Не путать с set_model() проекта. */
	public static function set_setting($key, $value)
	{
		$key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));

		if ($key === '' OR $key === 'model')
		{
			return ['ok' => FALSE, 'error' => 'Неизвестный ключ настройки.'];
		}

		$value = UTF8::substr(trim((string) $value), 0, 160);

		try
		{
			self::_exec('INSERT INTO nj_667_settings (key, value, updated_at)
				VALUES ('.self::_q($key).', '.self::_q($value).', now())
				ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()');
		}
		catch (Throwable $e)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось сохранить настройку: '.$e->getMessage()];
		}

		return ['ok' => TRUE, 'key' => $key, 'value' => $value];
	}

	/**
	 * До какой длинной стороны уменьшать кадр для модели, px.
	 *
	 * Админка /graph3d/settings, иначе NJ667_PHOTO_MAX_PX, иначе 512
	 * (владелец, 11.10.2026). На диск по-прежнему до PHOTO_MAX_SIDE.
	 */
	public static function photo_max_px()
	{
		$raw = trim(self::setting('photo_max_px', ''));

		if ($raw === '')
		{
			$raw = (string) (getenv('NJ667_PHOTO_MAX_PX') ?: '512');
		}

		$n = (int) $raw;

		return max(128, min(2048, $n > 0 ? $n : 512));
	}

	/** RAG по умолчанию: админка, иначе config rag.enabled (вкл.). */
	public static function rag_default()
	{
		$raw = strtolower(trim(self::setting('rag', '')));

		if ($raw === '')
		{
			return (int) Arr::get(self::branch('rag'), 'enabled', 1) ? TRUE : FALSE;
		}

		return self::_flag($raw);
	}

	/**
	 * RAG для этой сборки: явное поле запроса, иначе дефолт админки.
	 *
	 * Чекбокс формы шлёт 0/1; JSON API — true/false. Нет поля — берём
	 * rag_default(), чтобы старые клиенты не теряли поведение.
	 */
	public static function rag_from($src)
	{
		if ( ! is_array($src) OR ! array_key_exists('rag', $src))
		{
			return self::rag_default();
		}

		return self::_flag($src['rag']);
	}

	protected static function _flag($value)
	{
		if (is_array($value))
		{
			$value = end($value);
		}

		if (is_bool($value))
		{
			return $value;
		}

		if (is_int($value) OR is_float($value))
		{
			return (int) $value !== 0;
		}

		$s = strtolower(trim((string) $value));

		return ! in_array($s, ['0', 'off', 'false', 'no', ''], TRUE);
	}

	// -------------------------------------------- чистые: граф (дверь в Graph)

	public static function graph_clean($graph)
	{
		return Service_Nj667_Graph::graph_clean($graph);
	}

	public static function graph_validate(array $graph, $auto_clean = FALSE)
	{
		return Service_Nj667_Graph::graph_validate($graph, $auto_clean);
	}

	public static function graph_neighborhood(array $graph, array $ids, $depth = 1)
	{
		return Service_Nj667_Graph::graph_neighborhood($graph, $ids, $depth);
	}

	public static function graph_new($title = '')
	{
		return Service_Nj667_Graph::graph_new($title);
	}

	/** Локальные шаблоны графа для режима без LLM. */
	public static function simple_templates()
	{
		return Service_Nj667_Graph::simple_templates();
	}

	/** Детерминированный каркас из шаблона; NULL — неизвестный ключ. */
	public static function simple_graph($template, $title = '')
	{
		return Service_Nj667_Graph::simple_graph($template, $title);
	}

	public static function graph_text(array $graph)
	{
		return Service_Nj667_Graph::graph_text($graph);
	}

	public static function topology_hash(array $graph)
	{
		return Service_Nj667_Graph::topology_hash($graph);
	}

	public static function few_shot_merge(array $by_topo, array $by_embed, array $by_text, $limit, $exclude_id = 0)
	{
		return Service_Nj667_Graph::few_shot_merge($by_topo, $by_embed, $by_text, $limit, $exclude_id);
	}

	public static function compact_plan(array $revisions, $keep = 2000)
	{
		return Service_Nj667_Graph::compact_plan($revisions, $keep);
	}

	public static function history_window_plan(array $revisions, $keep = 4)
	{
		return Service_Nj667_Graph::history_window_plan($revisions, $keep);
	}

	public static function replay_revisions(array $revisions, $target_no, array $start = NULL)
	{
		return Service_Nj667_Graph::replay_revisions($revisions, $target_no, $start);
	}

	public static function patch_diff($before, $after)
	{
		return Service_Nj667_Graph::patch_diff($before, $after);
	}

	public static function patch_apply($graph, $ops)
	{
		return Service_Nj667_Graph::patch_apply($graph, $ops);
	}

	public static function pointer_parts($path)
	{
		return Service_Nj667_Graph::pointer_parts($path);
	}

	// ------------------------------------------------------------------ коды

	public static function code_new($len = 12)
	{
		$alphabet = '23456789abcdefghijkmnpqrstuvwxyz';
		$code     = '';

		for ($i = 0; $i < (int) $len; $i++)
		{
			$code .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
		}

		return $code;
	}

	// -------------------------------------------------------------- проекты

	/**
	 * Новый проект: пустой граф, если не дан, иначе очищенный.
	 * Всё, что пришло от модели, проходит graph_clean — модель не обязана
	 * соблюдать наши правила, а холст обязан.
	 */
	public static function create($user_id, array $opt)
	{
		$user_id = max(0, (int) $user_id);
		$limit   = (int) Arr::get(self::quota_limits($user_id), 'active_projects', 5);
		$graph   = self::graph_clean(Arr::get($opt, 'graph', self::graph_new('')));
		$title   = UTF8::substr(trim((string) Arr::get($opt, 'title', '')), 0, 200);

		if ($title === '')
		{
			$title = UTF8::substr(trim((string) Arr::get($opt, 'prompt', '')), 0, 200);
		}

		if ($title === '' AND ! empty($opt['has_photos']))
		{
			$title = 'Граф по фото';
		}

		if ($title === '')
		{
			$title = 'Новый граф';
		}

		$code = self::code_new();
		$uid  = class_exists('Visitor') ? (string) Visitor::uid() : '';

		$row = [
			'code'        => $code,
			'user_id'     => $user_id,
			'visitor_uid' => mb_substr($uid, 0, 40),
			'title'       => $title,
			'tags'        => UTF8::substr(trim((string) Arr::get($opt, 'tags', '')), 0, 300),
			'kind'        => 'private',
			'status'      => self::S_DRAFT,
			'graph_json'  => json_encode($graph, JSON_UNESCAPED_UNICODE),
			'nodes_count' => count(Arr::get($graph, 'nodes', [])),
			'edges_count' => count(Arr::get($graph, 'edges', [])),
			'topo_hash'   => self::topology_hash($graph),
			'remix_of'    => (int) Arr::get($opt, 'remix_of', 0),
		];

		$db = self::_db();
		$db->begin();
		$transaction = TRUE;

		try
		{
			// Сериализуем создание по владельцу: два параллельных запроса не
			// смогут одновременно пройти проверку и превысить лимит проектов.
			$db->query(Database::SELECT,
				'SELECT pg_advisory_xact_lock(667, '.$user_id.')', FALSE)->current();

			$count = self::active_project_count($user_id);

			if ($count >= $limit)
			{
				$db->rollback();
				$transaction = FALSE;

				return ['ok' => FALSE, 'error' => 'Достигнут лимит: не больше '
					.$limit.' активных проектов. Удалите ненужный проект и попробуйте снова.',
					'project_quota' => ['used' => $count, 'limit' => $limit]];
			}

			$id = self::_insert('nj_667_projects', $row);

			if ((int) Arr::get($row, 'remix_of', 0) > 0)
			{
				self::_exec('UPDATE nj_667_projects SET remixes = remixes + 1 WHERE id = '
					.(int) Arr::get($row, 'remix_of'));
			}

			$db->commit();
			$transaction = FALSE;
		}
		catch (Throwable $e)
		{
			if ($transaction)
			{
				$db->rollback();
			}

			self::_log(Log::ERROR, 'проект не создан: :error', [':error' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Не удалось создать проект: '.$e->getMessage()];
		}

		$note = '';

		if ((string) Arr::get($opt, 'prompt', '') !== '')
		{
			$note = 'из описания: '.UTF8::substr((string) Arr::get($opt, 'prompt', ''), 0, 200);
		}
		elseif ( ! empty($opt['has_photos']))
		{
			$note = 'по фото';
		}

		self::event($id, 'create', $note, $user_id);

		return ['ok' => TRUE, 'code' => $code, 'id' => (int) $id, 'graph' => $graph];
	}

	public static function project($code)
	{
		$code = trim((string) $code);

		if ($code === '' OR preg_match('~^[A-Za-z0-9]{8,24}$~', $code) !== 1)
		{
			return NULL;
		}

		return self::_one('SELECT * FROM nj_667_projects
			WHERE code = '.self::_q($code).' AND deleted_at IS NULL LIMIT 1');
	}

	public static function project_by_id($id)
	{
		return self::_one('SELECT * FROM nj_667_projects
			WHERE id = '.(int) $id.' AND deleted_at IS NULL LIMIT 1');
	}

	// -------------------------------------------------------------- фото

	/**
	 * Референс-фото сборки: диск, не jsonb очереди.
	 *
	 * Владелец, 10.10.2026: конструкцию можно собрать по фото, не только по
	 * тексту. Кадры лежат в storage/nj667/<code>/01.jpg — в задачу nj120
	 * уезжает только prompt. Воркер забирает кадры из /graph3d/w/context.
	 * Иначе каждый опрос очереди тащит мегабайты base64 (урок nj646).
	 */

	/** Каталог кадров проекта. Код — только алфавит адреса, путь пользователя сюда не попадает. */
	public static function photos_dir($code, $create = TRUE)
	{
		return self::storage_dir($code, $create);
	}

	/**
	 * Каталог файлов проекта: storage/nj667/<code>/.
	 *
	 * Здесь кадры (`NN.jpg`) и артефакты сборки (`build-<id>.scad`,
	 * `.stl`). Всё, что тяжело и бинарно, живёт на диске, а в базе — путь и
	 * контрольная сумма: base64 в jsonb раздувает очередь (урок nj646).
	 */
	public static function storage_dir($code, $create = TRUE)
	{
		$code = trim((string) $code);

		if (preg_match('~^[A-Za-z0-9]{8,24}$~', $code) !== 1 OR ! defined('APPPATH'))
		{
			return '';
		}

		$dir = APPPATH.'../storage/nj667/'.$code;

		if ($create AND ! is_dir($dir))
		{
			@mkdir($dir, 0700, TRUE);
		}

		if (is_dir($dir))
		{
			@chmod($dir, 0700);

			return $dir;
		}

		return '';
	}

	/** Сколько файлов выбрано в $_FILES (включая файлы с ошибкой загрузки). */
	public static function uploads_count($files)
	{
		$n = 0;

		foreach (self::uploads_from($files) as $file)
		{
			$name  = trim((string) Arr::get($file, 'name', ''));
			$tmp   = trim((string) Arr::get($file, 'tmp_name', ''));
			$error = (int) Arr::get($file, 'error', UPLOAD_ERR_NO_FILE);

			if ($name !== '' OR $tmp !== '' OR $error !== UPLOAD_ERR_NO_FILE)
			{
				$n++;
			}
		}

		return $n;
	}

	/** Нормализовать $_FILES['photos'] / photos[] к списку записей. */
	public static function uploads_from($files)
	{
		$files = (array) $files;

		if ( ! $files)
		{
			return [];
		}

		if (isset($files['tmp_name']) AND ! is_array($files['tmp_name']))
		{
			return [$files];
		}

		if (isset($files['tmp_name']) AND is_array($files['tmp_name']))
		{
			$out = [];

			foreach ($files['tmp_name'] as $i => $tmp)
			{
				$out[] = [
					'name'     => isset($files['name'][$i]) ? $files['name'][$i] : '',
					'type'     => isset($files['type'][$i]) ? $files['type'][$i] : '',
					'tmp_name' => $tmp,
					'error'    => isset($files['error'][$i]) ? $files['error'][$i] : UPLOAD_ERR_NO_FILE,
					'size'     => isset($files['size'][$i]) ? $files['size'][$i] : 0,
				];
			}

			return $out;
		}

		$out = [];

		foreach ($files as $row)
		{
			if (is_array($row) AND isset($row['tmp_name']))
			{
				$out[] = $row;
			}
		}

		return $out;
	}

	/** Сжать загруженные файлы в JPEG. Негодные кадры пропускаем. */
	public static function prepare_uploads($files, $max = self::PHOTO_MAX)
	{
		$max = max(1, min(self::PHOTO_MAX, (int) $max));
		$out = [];

		foreach (self::uploads_from($files) as $file)
		{
			if ((int) Arr::get($file, 'error', UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
			{
				continue;
			}

			$tmp = (string) Arr::get($file, 'tmp_name', '');

			if ($tmp === '' OR ! is_file($tmp) OR ! is_readable($tmp))
			{
				continue;
			}

			if ((int) Arr::get($file, 'size', 0) > self::PHOTO_MAX_BYTES)
			{
				continue;
			}

			$bytes = @file_get_contents($tmp);
			$jpeg  = self::photo_jpeg($bytes === FALSE ? '' : $bytes);

			if ($jpeg !== '')
			{
				$out[] = $jpeg;
			}

			if (count($out) >= $max)
			{
				break;
			}
		}

		return $out;
	}

	/** Сжать кадры из JSON API ({b64, media_type} или data-URL). */
	public static function prepare_payload(array $images, $max = self::PHOTO_MAX)
	{
		$max = max(1, min(self::PHOTO_MAX, (int) $max));
		$out = [];

		foreach (array_slice($images, 0, $max) as $row)
		{
			if ( ! is_array($row))
			{
				continue;
			}

			$raw = trim((string) Arr::get($row, 'b64', Arr::get($row, 'image_b64', '')));

			if ($raw === '')
			{
				continue;
			}

			if (strpos($raw, 'base64,') !== FALSE)
			{
				$parts = explode('base64,', $raw, 2);
				$raw   = isset($parts[1]) ? $parts[1] : '';
			}

			$raw   = preg_replace('~\s+~', '', $raw);
			$bytes = base64_decode($raw, TRUE);
			$jpeg  = self::photo_jpeg($bytes === FALSE ? '' : $bytes);

			if ($jpeg !== '')
			{
				$out[] = $jpeg;
			}
		}

		return $out;
	}

	/** Заменить кадры проекта. Пустой список — стереть старые. */
	public static function write_photos($code, array $jpegs)
	{
		$dir = self::photos_dir($code, TRUE);

		if ($dir === '')
		{
			return 0;
		}

		self::photos_wipe($dir);
		$n = 0;

		foreach (array_values($jpegs) as $i => $bytes)
		{
			if ($i >= self::PHOTO_MAX)
			{
				break;
			}

			$bytes = (string) $bytes;

			if ($bytes === '')
			{
				continue;
			}

			$path = $dir.DIRECTORY_SEPARATOR.sprintf('%02d.jpg', $i + 1);

			if (@file_put_contents($path, $bytes) !== FALSE)
			{
				@chmod($path, 0600);
				$n++;
			}
		}

		return $n;
	}

	public static function save_uploads($code, $files)
	{
		return self::write_photos($code, self::prepare_uploads($files));
	}

	public static function replace_photos_from_payload($code, array $images, $max = self::PHOTO_MAX)
	{
		return self::write_photos($code, self::prepare_payload($images, $max));
	}

	public static function photos_count($code)
	{
		return count(self::photos_files($code));
	}

	/** Кадры для воркера: base64 JPEG, уже ужатые до photo_max_px(). */
	public static function photos_payload($code)
	{
		$out = [];
		$max = self::photo_max_px();

		foreach (self::photos_files($code) as $path)
		{
			$bytes = @file_get_contents($path);

			if ($bytes === FALSE OR $bytes === '')
			{
				continue;
			}

			$jpeg = self::photo_jpeg($bytes, $max);

			if ($jpeg === '')
			{
				$jpeg = $bytes;
			}

			$out[] = [
				'name'       => basename($path),
				'media_type' => 'image/jpeg',
				'b64'        => base64_encode($jpeg),
				'kb'         => (int) ceil(strlen($jpeg) / 1024),
			];

			if (count($out) >= self::PHOTO_MAX)
			{
				break;
			}
		}

		return $out;
	}

	/**
	 * Сжать кадр в JPEG по большей стороне.
	 *
	 * Без $max_side — до PHOTO_MAX_SIDE (диск). Для модели передаём
	 * photo_max_px() (по умолчанию 512). Только уменьшаем: апскейл
	 * деталей не создаёт, а vision ест патчи вчетверо дороже (урок nj646).
	 * Без GD принимаем уже-jpeg до 800 КБ.
	 */
	public static function photo_jpeg($bytes, $max_side = NULL)
	{
		$bytes = (string) $bytes;

		if ($bytes === '' OR strlen($bytes) > self::PHOTO_MAX_BYTES)
		{
			return '';
		}

		if (function_exists('getimagesizefromstring'))
		{
			$meta = @getimagesizefromstring($bytes);

			if ( ! is_array($meta) OR empty($meta[0]) OR empty($meta[1]))
			{
				return '';
			}

			if ((int) $meta[0] * (int) $meta[1] > 12000000)
			{
				return '';
			}

			$mime = strtolower((string) Arr::get($meta, 'mime', ''));

			if ($mime !== '' AND strpos($mime, 'image/') !== 0)
			{
				return '';
			}
		}

		if (function_exists('imagecreatefromstring') AND function_exists('imagejpeg'))
		{
			$src = @imagecreatefromstring($bytes);

			if ( ! $src)
			{
				return '';
			}

			$width  = imagesx($src);
			$height = imagesy($src);

			if ($width < 1 OR $height < 1)
			{
				imagedestroy($src);

				return '';
			}

			$limit = $max_side === NULL
			? self::PHOTO_MAX_SIDE
			: max(128, min(2048, (int) $max_side));
		$limit = $limit > 0 ? $limit : self::PHOTO_MAX_SIDE;
		$scale = min(1.0, $limit / max($width, $height));
			$to_w  = max(1, (int) round($width * $scale));
			$to_h  = max(1, (int) round($height * $scale));
			$dst   = imagecreatetruecolor($to_w, $to_h);

			if ( ! $dst)
			{
				imagedestroy($src);

				return '';
			}

			$white = imagecolorallocate($dst, 255, 255, 255);
			imagefilledrectangle($dst, 0, 0, $to_w, $to_h, $white);
			imagecopyresampled($dst, $src, 0, 0, 0, 0, $to_w, $to_h, $width, $height);
			imagedestroy($src);
			ob_start();
			imagejpeg($dst, NULL, 82);
			imagedestroy($dst);
			$jpeg = (string) ob_get_clean();

			return $jpeg !== '' ? $jpeg : '';
		}

		if (strlen($bytes) <= 800000 AND strncmp($bytes, "\xFF\xD8\xFF", 3) === 0)
		{
			return $bytes;
		}

		return '';
	}

	protected static function photos_files($code)
	{
		$dir = self::photos_dir($code, FALSE);

		if ($dir === '' OR ! is_dir($dir))
		{
			return [];
		}

		$list = @scandir($dir);

		if ( ! is_array($list))
		{
			return [];
		}

		$out = [];

		foreach ($list as $name)
		{
			if (preg_match('~^[0-9]{2}\.jpg$~', $name))
			{
				$out[] = $dir.DIRECTORY_SEPARATOR.$name;
			}
		}

		sort($out);

		return $out;
	}

	protected static function photos_wipe($dir)
	{
		if ($dir === '' OR ! is_dir($dir))
		{
			return;
		}

		$list = @scandir($dir);

		if ( ! is_array($list))
		{
			return;
		}

		foreach ($list as $name)
		{
			if (preg_match('~^[0-9]{2}\.(jpe?g|png|webp|gif)$~i', $name))
			{
				@unlink($dir.DIRECTORY_SEPARATOR.$name);
			}
		}
	}

	/** Граф проекта (jsonb → массив). Пустой граф, если jsonb пуст. */
	public static function graph_of(array $project)
	{
		$raw = Arr::get($project, 'graph_json', '');

		if (is_array($raw))
		{
			return self::graph_clean($raw);
		}

		$graph = (string) $raw !== '' ? json_decode((string) $raw, TRUE) : NULL;

		return is_array($graph) ? self::graph_clean($graph) : self::graph_new((string) Arr::get($project, 'title', ''));
	}

	public static function for_user($user_id, $uid = '', $limit = 24, $query = '')
	{
		$limit = min(200, max(1, (int) $limit));
		$where = [];
		$query = trim((string) $query);

		if ((int) $user_id > 0)
		{
			$where[] = 'user_id = '.(int) $user_id;
		}

		if ($uid !== '')
		{
			$where[] = 'visitor_uid = '.self::_q($uid);
		}

		if ( ! $where)
		{
			return [];
		}

		// Поиск: название важнее тегов, теги важнее описания; внутри — свежие выше.
		if ($query === '')
		{
			return self::_all('SELECT * FROM nj_667_projects
				WHERE ('.implode(' OR ', $where).') AND deleted_at IS NULL
				ORDER BY updated_at DESC LIMIT '.$limit);
		}

		$like = self::_q('%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%');

		return self::_all('SELECT * FROM nj_667_projects
			WHERE ('.implode(' OR ', $where).') AND deleted_at IS NULL
				AND (title ILIKE '.$like.' OR tags ILIKE '.$like.' OR summary ILIKE '.$like.')
			ORDER BY (title ILIKE '.$like.') DESC, (tags ILIKE '.$like.') DESC,
				(summary ILIKE '.$like.') DESC, updated_at DESC
			LIMIT '.$limit);
	}

	/** Публичный каталог: опубликованные проекты, сначала отмеченные редакцией. */
	/** Подзапрос: id последней опубликованной сборки проекта (NULL — такой нет). */
	protected static function _published_build_sql()
	{
		return '(SELECT b.id FROM nj_667_builds b
			WHERE b.project_id = nj_667_projects.id AND b.published_at IS NOT NULL
			ORDER BY b.published_at DESC LIMIT 1)';
	}

	public static function catalog($limit = 24, $offset = 0, $tag = '')
	{
		$limit  = min(60, max(1, (int) $limit));
		$offset = max(0, (int) $offset);
		$where  = 'status = '.self::_q(self::S_PUBLISHED).' AND deleted_at IS NULL';

		if ($tag !== '')
		{
			$where .= ' AND tags ILIKE '.self::_q('%'.str_replace(['%', '_'], ['\\%', '\\_'], $tag).'%');
		}

		return self::_all('SELECT *, '.self::_published_build_sql().' AS published_build_id FROM nj_667_projects
			WHERE '.$where.'
			ORDER BY featured DESC, updated_at DESC
			LIMIT '.$limit.' OFFSET '.$offset);
	}

	/**
	 * Витрина /graph3d: лучшие опубликованные.
	 *
	 * Сначала отмеченные редакцией, затем по просмотрам. Каталог (/graph3d/catalog)
	 * остаётся полным списком — сюда только топ. Владелец, 11.10.2026.
	 */
	public static function showcase($limit = 9)
	{
		$limit = min(24, max(1, (int) $limit));

		return self::_all('SELECT * FROM nj_667_projects
			WHERE status = '.self::_q(self::S_PUBLISHED).' AND deleted_at IS NULL
			ORDER BY featured DESC, views DESC, updated_at DESC
			LIMIT '.$limit);
	}

	/** Заявки на модерацию: статус проекта (и уже решённые — по $status). */
	public static function moderation_queue($status = NULL, $limit = 30)
	{
		$limit = min(100, max(1, (int) $limit));
		$where = 'deleted_at IS NULL';

		if ($status !== NULL AND $status !== '')
		{
			$where .= ' AND status = '.self::_q((string) $status);
		}
		else
		{
			$where .= " AND status IN ('".self::S_REVIEW."', '".self::S_PUBLISHED."')";
		}

		return self::_all('SELECT * FROM nj_667_projects
			WHERE '.$where.'
			ORDER BY (status = \''.self::S_REVIEW.'\') DESC, updated_at DESC
			LIMIT '.$limit);
	}

	// -------------------------------------------------------------- правка

	/**
	 * Сохранить новый граф: как патч в историю и как снимок в проект.
	 *
	 * Снимок нужен потому же, почему Git хранит дерево целиком: достать
	 * текущее состояние — один запрос, а не накатка тысячи патчей.
	 */
	public static function save_graph($project_id, array $graph, $author_kind = 'user', $label = '')
	{
		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Проект не найден.'];
		}

		$before = self::graph_of($project);
		$after  = self::graph_clean($graph);
		$ops    = self::patch_diff($before, $after);

		if ( ! $ops)
		{
			return ['ok' => TRUE, 'graph' => $after, 'ops' => 0];
		}

		$existing = (int) self::_value('SELECT count(*) FROM nj_667_revisions
			WHERE project_id = '.(int) $project_id);
		$last_no = (int) self::_value('SELECT COALESCE(max(no), 0) FROM nj_667_revisions
			WHERE project_id = '.(int) $project_id);

		// Remix-проект уже содержит граф при создании, но ещё не имеет истории.
		// Снимок №0 становится базой для последующего replay/сжатия патчей.
		if ($existing === 0 AND (Arr::get($before, 'nodes') OR Arr::get($before, 'edges')))
		{
			self::_insert('nj_667_revisions', [
				'project_id'  => (int) $project_id,
				'no'          => 0,
				'kind'        => 'milestone',
				'label'       => 'Исходная версия',
				'graph_json'  => json_encode($before, JSON_UNESCAPED_UNICODE),
				'patch_json'  => NULL,
				'author_kind' => 'user',
			]);
		}

		$no = $last_no + 1;

		self::_insert('nj_667_revisions', [
			'project_id'  => (int) $project_id,
			'no'          => $no,
			'kind'        => 'commit',
			'label'       => UTF8::substr(trim((string) $label), 0, 200),
			'patch_json'  => json_encode($ops, JSON_UNESCAPED_UNICODE),
			'author_kind' => $author_kind === 'ai' ? 'ai' : 'user',
		]);

		self::_store_graph($project_id, $after);
		self::_trim_revisions($project_id);

		return ['ok' => TRUE, 'graph' => $after, 'ops' => count($ops), 'no' => $no];
	}

	/** Именованная контрольная точка: снимок, к которому можно вернуться. */
	public static function milestone($project_id, $label = '')
	{
		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Проект не найден.'];
		}

		$no = 1 + (int) self::_value('SELECT COALESCE(max(no), 0) FROM nj_667_revisions
			WHERE project_id = '.(int) $project_id);

		$id = self::_insert('nj_667_revisions', [
			'project_id'  => (int) $project_id,
			'no'          => $no,
			'kind'        => 'milestone',
			'label'       => UTF8::substr(trim((string) $label) ?: 'Контрольная точка '.$no, 0, 200),
			'graph_json'  => (string) Arr::get($project, 'graph_json', ''),
			'patch_json'  => NULL,
			'author_kind' => 'user',
		]);

		self::_trim_revisions($project_id);

		return ['ok' => TRUE, 'id' => (int) $id, 'no' => $no];
	}

	/**
	 * Откат к ревизии: накатываем историю до выбранного номера
	 * и кладём граф в проект. Новый коммит не пишем (владелец, 10.10.2026):
	 * откат — это возврат к уже существующей точке, а не ещё одна правка.
	 * Поздние ревизии остаются в списке: к ним тоже можно откатиться.
	 */
	public static function restore($project_id, $revision_id)
	{
		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Проект не найден.'];
		}

		// Прямой API не должен позволять откатываться к ревизии за пределами окна.
		self::_trim_revisions((int) $project_id);

		$rev = self::_one('SELECT * FROM nj_667_revisions
			WHERE id = '.(int) $revision_id.' AND project_id = '.(int) $project_id.' LIMIT 1');

		if ($rev === NULL OR Arr::get($rev, 'deleted_at'))
		{
			return ['ok' => FALSE, 'error' => 'Ревизия не найдена.'];
		}

		// В накатку входят и мягко удалённые: иначе дыра в цепочке патчей.
		$history = self::_all('SELECT * FROM nj_667_revisions
			WHERE project_id = '.(int) $project_id.'
			ORDER BY no ASC');

		$replay = self::replay_revisions(
			$history,
			(int) Arr::get($rev, 'no', 0),
			self::graph_new((string) Arr::get($project, 'title', ''))
		);

		if ( ! Arr::get($replay, 'ok'))
		{
			return ['ok' => FALSE, 'error' => Arr::get($replay, 'error', 'История не накатывается.')];
		}

		$graph = self::_store_graph($project_id, (array) Arr::get($replay, 'graph', []));

		return ['ok' => TRUE, 'graph' => $graph, 'ops' => 0, 'no' => (int) Arr::get($rev, 'no', 0)];
	}

	/**
	 * Мягкое удаление ревизии: строка остаётся для накатки истории,
	 * из списка на странице пропадает. Владелец, 10.10.2026.
	 */
	public static function delete_revision($project_id, $revision_id)
	{
		self::_trim_revisions((int) $project_id);

		try
		{
			$rev = self::_one('SELECT id FROM nj_667_revisions
				WHERE id = '.(int) $revision_id.' AND project_id = '.(int) $project_id.'
				AND deleted_at IS NULL LIMIT 1');
		}
		catch (Throwable $e)
		{
			return ['ok' => FALSE, 'error' => 'Мягкое удаление ревизий ещё не включено.'];
		}

		if ($rev === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Ревизия не найдена.'];
		}

		self::_exec('UPDATE nj_667_revisions SET deleted_at = now()
			WHERE id = '.(int) $revision_id.' AND project_id = '.(int) $project_id);

		return ['ok' => TRUE];
	}

	/** История ревизий для экрана (новые сверху, без мягко удалённых). */
	public static function revisions($project_id, $limit = 50)
	{
		$pid     = (int) $project_id;
		$project = self::project_by_id($pid);

		if ($project === NULL)
		{
			return [];
		}

		$history_limit = (int) Arr::get(self::quota_limits((int) Arr::get($project, 'user_id')),
			'historical_revisions', 3);
		$limit = min(200, max(1, min((int) $limit, $history_limit + 1)));

		self::_trim_revisions($pid);

		try
		{
			return self::_all('SELECT * FROM nj_667_revisions
				WHERE project_id = '.$pid.' AND deleted_at IS NULL
				ORDER BY no DESC LIMIT '.$limit);
		}
		catch (Throwable $e)
		{
			// Колонки deleted_at ещё нет (миграция 003 не накатана) — покажем все.
			return self::_all('SELECT * FROM nj_667_revisions
				WHERE project_id = '.$pid.'
				ORDER BY no DESC LIMIT '.$limit);
		}
	}

	/** Заявки на модерации по проекту (может быть несколько снимков). */
	public static function pending_revisions($project_id)
	{
		try
		{
			return self::_all('SELECT * FROM nj_667_revisions
				WHERE project_id = '.(int) $project_id.'
					AND status = '.self::_q(self::S_REVIEW).'
					AND deleted_at IS NULL
				ORDER BY no DESC');
		}
		catch (Throwable $e)
		{
			try
			{
				return self::_all('SELECT * FROM nj_667_revisions
					WHERE project_id = '.(int) $project_id.'
						AND status = '.self::_q(self::S_REVIEW).'
					ORDER BY no DESC');
			}
			catch (Throwable $e2)
			{
				return [];
			}
		}
	}

	/** Последняя заявка проекта. Нет колонки status — NULL. */
	public static function pending_revision($project_id)
	{
		$rows = self::pending_revisions($project_id);

		return $rows ? $rows[0] : NULL;
	}

	/** Заявка проекта: ревизия со status=review или сам проект на модерации. */
	public static function pending_for_graph($project_id, array $graph)
	{
		$sig = json_encode(self::graph_clean($graph), JSON_UNESCAPED_UNICODE);

		foreach (self::pending_revisions($project_id) as $row)
		{
			if (json_encode(self::graph_of($row), JSON_UNESCAPED_UNICODE) === $sig)
			{
				return $row;
			}
		}

		$project = self::project_by_id($project_id);

		if ($project !== NULL AND (string) Arr::get($project, 'status') === self::S_REVIEW)
		{
			return $project;
		}

		return NULL;
	}

	/** Опубликованная ревизия: конкретный номер или последняя. */
	public static function published_revision($project_id, $no = 0)
	{
		$extra = ((int) $no) > 0 ? ' AND no = '.(int) $no : '';

		try
		{
			return self::_one('SELECT * FROM nj_667_revisions
				WHERE project_id = '.(int) $project_id.'
					AND status = '.self::_q(self::S_PUBLISHED).'
					AND deleted_at IS NULL
					'.$extra.'
				ORDER BY no DESC LIMIT 1');
		}
		catch (Throwable $e)
		{
			try
			{
				return self::_one('SELECT * FROM nj_667_revisions
					WHERE project_id = '.(int) $project_id.'
						AND status = '.self::_q(self::S_PUBLISHED).'
						'.$extra.'
					ORDER BY no DESC LIMIT 1');
			}
			catch (Throwable $e2)
			{
				return NULL;
			}
		}
	}

	/** Ревизия проекта по id. */
	public static function revision_of($project_id, $revision_id)
	{
		if ((int) $revision_id <= 0)
		{
			return NULL;
		}

		return self::_one('SELECT * FROM nj_667_revisions
			WHERE id = '.(int) $revision_id.' AND project_id = '.(int) $project_id.' LIMIT 1');
	}

	/** Снимок опубликованной ревизии; иначе живой граф (владелец, не витрина). */
	public static function public_graph(array $project, $no = 0)
	{
		$id  = (int) Arr::get($project, 'id', 0);
		$rev = $id > 0 ? self::published_revision($id, (int) $no) : NULL;

		return $rev !== NULL ? self::graph_of($rev) : self::graph_of($project);
	}

	/** Адрес карточки в каталоге: /graph3d/c/<code>?r=<no>. */
	public static function catalog_href(array $row)
	{
		$code = rawurlencode((string) Arr::get($row, 'code', ''));
		$no   = (int) Arr::get($row, 'revision_no', 0);

		return '/graph3d/c/'.$code.($no > 0 ? '?r='.$no : '');
	}

	/** Адрес SVG-превью той же ревизии. $theme: day|night — палитра холста. */
	public static function preview_href(array $row, $theme = '')
	{
		$code = rawurlencode((string) Arr::get($row, 'code', ''));
		$no   = (int) Arr::get($row, 'revision_no', 0);
		$url  = '/graph3d/api/preview?code='.$code.($no > 0 ? '&r='.$no : '');
		$theme = strtolower(trim((string) $theme));

		if ($theme === 'day' OR $theme === 'night')
		{
			$url .= '&theme='.$theme;
		}

		return $url;
	}

	/** Два кадра превью: день и ночь; кадр выбирает тема (dark:) — владелец, 11.10.2026. */
	public static function preview_img(array $row, $alt = '', $class = '')
	{
		$alt   = HTML::chars((string) $alt);
		$class = trim((string) $class);
		$cls   = $class === '' ? '' : ' '.$class;
		$day   = HTML::chars(self::preview_href($row, 'day'));
		$night = HTML::chars(self::preview_href($row, 'night'));

		return '<span class="nj667-preview block max-w-full leading-[0]">'
			.'<img src="'.$day.'" alt="'.$alt.'" class="nj667-preview-day max-w-full dark:hidden'.$cls.'">'
			.'<img src="'.$night.'" alt="" class="nj667-preview-night hidden max-w-full dark:block'.$cls.'" aria-hidden="true">'
			.'</span>';
	}

	/** Запасные идеи, если копилка ещё пуста. */
	public static function idea_presets_fallback()
	{
		return [
			'Трёхколёсный велосипед с корзиной сзади: два колеса по бокам, одно спереди, рама, руль и сиденье',
			'Кухонный стул со спинкой: четыре ножки, сиденье, царги и высокая спинка с перекладинами',
			'Настольная лампа на штативе: тяжёлое основание, стойка, шарнир, плафон и выключатель',
			'Садовая тачка: одно колесо спереди, два упора сзади, корыто и две ручки',
			'Офисный стол: столешница, четыре ножки, царги и выдвижной ящик слева',
			'Детские качели: две стойки-опоры, верхняя перекладина, сиденье на двух верёвках',
		];
	}

	/** Все пресеты, новые сверху. Нет таблицы — пустой список. */
	public static function idea_presets($limit = 200)
	{
		$limit = max(1, min(500, (int) $limit));

		try
		{
			return self::_all('SELECT id, theme, body, job_id, created_at
				FROM nj_667_idea_presets
				ORDER BY id DESC
				LIMIT '.$limit);
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	/** Три (или $n) случайных текста для кнопок на /graph3d/create. */
	public static function idea_presets_pick($n = 3)
	{
		$n = max(1, min(6, (int) $n));
		$pool = [];

		foreach (self::idea_presets(120) as $row)
		{
			$text = trim((string) Arr::get($row, 'body', ''));

			if ($text !== '')
			{
				$pool[] = $text;
			}
		}

		if (count($pool) < $n)
		{
			$pool = array_values(array_unique(array_merge($pool, self::idea_presets_fallback())));
		}

		shuffle($pool);

		return array_slice($pool, 0, $n);
	}

	/** Принять пачку идей от воркера. Дубли по тексту пропускаем. */
	public static function idea_presets_save($theme, array $ideas, $job_id = 0)
	{
		$theme   = UTF8::substr(trim((string) $theme), 0, 200);
		$job_id  = (int) $job_id;
		$clean   = [];
		$seen    = [];

		foreach ($ideas as $text)
		{
			$text = UTF8::substr(trim((string) $text), 0, 280);

			if ($text === '')
			{
				continue;
			}

			$key = UTF8::strtolower($text);

			if (isset($seen[$key]))
			{
				continue;
			}

			$seen[$key] = TRUE;
			$clean[]    = $text;

			if (count($clean) >= 30)
			{
				break;
			}
		}

		if (count($clean) < 3)
		{
			return ['ok' => FALSE, 'error' => 'Модель вернула слишком мало идей.'];
		}

		$added = 0;

		try
		{
			foreach ($clean as $text)
			{
				$dup = self::_one('SELECT id FROM nj_667_idea_presets WHERE body = '.self::_q($text).' LIMIT 1');

				if ($dup !== NULL)
				{
					continue;
				}

				self::_exec('INSERT INTO nj_667_idea_presets (theme, body, job_id)
					VALUES ('.self::_q($theme).', '.self::_q($text).', '.$job_id.')');
				$added++;
			}

			self::set_setting('ideas_job', '0');
		}
		catch (Throwable $e)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось сохранить идеи: '.$e->getMessage()];
		}

		return ['ok' => TRUE, 'added' => $added];
	}

	/** Удалить один пресет. */
	public static function idea_preset_delete($id)
	{
		$id = (int) $id;

		if ($id <= 0)
		{
			return FALSE;
		}

		try
		{
			self::_exec('DELETE FROM nj_667_idea_presets WHERE id = '.$id);

			return TRUE;
		}
		catch (Throwable $e)
		{
			return FALSE;
		}
	}

	/** Поставить nj667.ideas — без проекта, это копилка сервиса. */
	public static function queue_ideas($theme, $user_id = 0)
	{
		$theme = UTF8::substr(trim((string) $theme), 0, 200);

		if ($theme === '')
		{
			return ['ok' => FALSE, 'error' => 'Укажите тему пачки.'];
		}

		if ( ! class_exists('Service_Nj120_JobQueue'))
		{
			return ['ok' => FALSE, 'error' => 'Очередь недоступна (модуль nj120 не подключён).'];
		}

		$current = (int) self::setting('ideas_job', '0');

		if ($current > 0)
		{
			$job = self::job_state($current);
			$st  = strtolower((string) Arr::get($job, 'status', Arr::get($job, 'state', '')));

			if ($job AND ! self::job_failed($job)
				AND ! in_array($st, ['done', 'finished', 'complete', 'success'], TRUE))
			{
				return ['ok' => FALSE, 'error' => 'Предыдущая генерация ещё идёт.'];
			}
		}

		$payload = ['theme' => $theme, 'count' => 30];
		$chosen  = self::light_model();

		if ($chosen !== '')
		{
			$payload['model'] = $chosen;
		}

		try
		{
			$job_id = Service_Nj120_JobQueue::push(self::CH_IDEAS, $payload, [
				'module'       => 'nj667',
				'visitor_id'   => 'u:'.(int) $user_id,
				'created_by'   => (int) $user_id,
				'max_attempts' => 1,
				'timeout_sec'  => self::timeout('ideas'),
			]);
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'очередь не приняла идеи: :e', [':e' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Очередь не приняла задачу: '.$e->getMessage()];
		}

		self::set_setting('ideas_job', (string) (int) $job_id);
		self::set_setting('ideas_theme', $theme);

		return ['ok' => TRUE, 'job_id' => (int) $job_id];
	}

	/** Саммари на ревизию (после nj667.summary по снимку заявки). */
	public static function set_revision_summary($revision_id, $summary)
	{
		$revision_id = (int) $revision_id;

		if ($revision_id <= 0)
		{
			return FALSE;
		}

		try
		{
			self::_exec('UPDATE nj_667_revisions
				SET summary = '.self::_q(UTF8::substr(trim((string) $summary), 0,
					(int) self::limits('max_summary', 1000))).'
				WHERE id = '.$revision_id);

			return TRUE;
		}
		catch (Throwable $e)
		{
			return FALSE;
		}
	}

	/**
	 * Название, описание и теги карточки (дешёвая модель, канал nj667.caption).
	 *
	 * Пишем и в проект, и в ревизию заявки, если её передали: модератор
	 * смотрит снимок, каталог — текущий проект (владелец, 11.10.2026).
	 */
	public static function set_caption($project_id, $title, $summary, $tags = '', $revision_id = 0)
	{
		$project_id = (int) $project_id;
		$title      = UTF8::substr(trim((string) $title), 0, 200);
		$summary    = UTF8::substr(trim((string) $summary), 0,
			(int) self::limits('max_summary', 1000));
		$tags       = UTF8::substr(trim((string) $tags), 0, 300);

		if ($project_id <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Нет проекта.'];
		}

		if ($title === '' OR $summary === '')
		{
			return ['ok' => FALSE, 'error' => 'Пустые название или описание.'];
		}

		self::_exec('UPDATE nj_667_projects
			SET title = '.self::_q($title).',
				summary = '.self::_q($summary).',
				tags = '.self::_q($tags).',
				updated_at = now()
			WHERE id = '.$project_id);

		$revision_id = (int) $revision_id;

		if ($revision_id > 0)
		{
			try
			{
				self::_exec('UPDATE nj_667_revisions
					SET title = '.self::_q($title).',
						summary = '.self::_q($summary).',
						tags = '.self::_q($tags).'
					WHERE id = '.$revision_id.' AND project_id = '.$project_id);
			}
			catch (Throwable $e)
			{
				// Ревизия без колонок — карточку проекта уже записали.
			}
		}

		return ['ok' => TRUE, 'title' => $title, 'summary' => $summary, 'tags' => $tags];
	}

	/** Записать снимок графа в проект без новой ревизии. */
	protected static function _store_graph($project_id, array $graph)
	{
		$graph = self::graph_clean($graph);

		self::_exec('UPDATE nj_667_projects
			SET graph_json = '.self::_q(json_encode($graph, JSON_UNESCAPED_UNICODE)).',
				nodes_count = '.count(Arr::get($graph, 'nodes', [])).',
				edges_count = '.count(Arr::get($graph, 'edges', [])).',
				topo_hash = '.self::_q(self::topology_hash($graph)).',
				updated_at = now()
			WHERE id = '.(int) $project_id);

		return $graph;
	}

	/**
	 * История не должна расти бесконечно: старшие коммиты схлопываем в снимок.
	 *
	 * Раньше просто DELETE старших коммитов ломал откат к оставшимся
	 * (патчи между старым milestone и границей пропадали). Теперь граница
	 * становится контрольной точкой — см. compact_plan().
	 */
	protected static function _trim_revisions($project_id)
	{
		$project_id = (int) $project_id;
		$project    = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return FALSE;
		}

		$db          = self::_db();
		$transaction = FALSE;

		try
		{
			$db->begin();
			$transaction = TRUE;

			// Несколько параллельных API-сохранений не должны оставить больше
			// четырёх строк истории после одновременной обрезки.
			$db->query(Database::SELECT,
				'SELECT pg_advisory_xact_lock(668, '.$project_id.')', FALSE)->current();

			$history_limit = (int) Arr::get(self::quota_limits((int) Arr::get($project, 'user_id')),
				'historical_revisions', 3);
			$keep = max(1, $history_limit + 1); // три исторические версии + текущая
			$rows = self::_all('SELECT * FROM nj_667_revisions
				WHERE project_id = '.$project_id.'
				ORDER BY no ASC, id ASC');
			$plan = self::history_window_plan($rows, $keep);

			if ( ! $plan['delete_ids'] AND $plan['convert_id'] <= 0)
			{
				$db->commit();
				$transaction = FALSE;

				return TRUE;
			}

			if ($plan['convert_id'] > 0)
			{
				$replay = self::replay_revisions($rows, $plan['convert_no'],
					self::graph_new((string) Arr::get($project, 'title', '')));

				if ( ! Arr::get($replay, 'ok'))
				{
					$db->commit();
					$transaction = FALSE;
					self::_log(Log::WARNING, 'сжатие истории проекта :id не удалось: :e',
						[':id' => $project_id, ':e' => Arr::get($replay, 'error', '')]);

					return FALSE;
				}

				$label = 'сжатие истории';

				foreach ($rows as $row)
				{
					if ((int) Arr::get($row, 'id', 0) === (int) $plan['convert_id'])
					{
						$label = trim((string) Arr::get($row, 'label', '')) ?: $label;
						break;
					}
				}

				self::_exec('UPDATE nj_667_revisions
					SET kind = \'milestone\',
						graph_json = '.self::_q(json_encode(Arr::get($replay, 'graph'), JSON_UNESCAPED_UNICODE)).',
						patch_json = NULL,
						label = '.self::_q($label).'
					WHERE id = '.(int) $plan['convert_id'].'
						AND project_id = '.$project_id);
			}

			$ids = array_values(array_filter(array_map('intval', (array) $plan['delete_ids'])));

			if ($ids)
			{
				self::_exec('DELETE FROM nj_667_revisions
					WHERE project_id = '.$project_id.'
						AND id IN ('.implode(',', $ids).')');
			}

			$db->commit();
			$transaction = FALSE;

			return TRUE;
		}
		catch (Throwable $e)
		{
			if ($transaction)
			{
				$db->rollback();
			}

			self::_log(Log::WARNING, 'сжатие истории проекта :id не удалось: :e',
				[':id' => $project_id, ':e' => $e->getMessage()]);

			return FALSE;
		}
	}

	// ------------------------------------------------------- публикация

	/**
	 * Заявка в каталог: статус проекта, без новой ревизии (владелец, 11.10.2026).
	 *
	 * NJ667_PREMODERATE=1 — status=review, очередь к человеку.
	 * Иначе сразу published: в каталог без очереди и без снимка-ревизии.
	 */
	public static function submit($project_id, $user_id = 0)
	{
		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Проект не найден.'];
		}

		$graph = self::graph_of($project);
		$guard = self::graph_validate($graph);
		$note  = UTF8::substr(trim((string) Arr::get($project, 'summary', '')) === ''
			? 'Саммари не готово — поиск по каталогу найдёт граф хуже.'
			: '', 0, 300);

		if ( ! Arr::get($guard, 'ok'))
		{
			return ['ok' => FALSE, 'error' => implode(' ', (array) Arr::get($guard, 'errors', []))];
		}

		if (self::premoderate())
		{
			if ((string) Arr::get($project, 'status') === self::S_REVIEW
				OR self::pending_revision($project_id) !== NULL)
			{
				return ['ok' => FALSE, 'error' => 'Уже на модерации.'];
			}

			self::_exec('UPDATE nj_667_projects
				SET status = '.self::_q(self::S_REVIEW).',
					kind = \'public\',
					moderation_note = '.self::_q((string) $note).',
					updated_at = now()
				WHERE id = '.(int) $project_id);

			self::event($project_id, 'submit', 'отправлено на модерацию', (int) $user_id);

			return ['ok' => TRUE, 'status' => self::S_REVIEW, 'note' => $note];
		}

		self::_exec('UPDATE nj_667_projects
			SET status = '.self::_q(self::S_PUBLISHED).',
				kind = \'public\',
				moderation_note = '.self::_q((string) $note).',
				updated_at = now()
			WHERE id = '.(int) $project_id);

		self::event($project_id, 'publish', 'в каталоге без пре-модерации', (int) $user_id);

		return ['ok' => TRUE, 'status' => self::S_PUBLISHED, 'note' => $note];
	}

	/** Вердикт модератора: approve / reject / feature / unfeature. Без ревизии — статус проекта. */
	public static function moderate($project_id, $verdict, $note = '', $user_id = 0, $revision_id = 0)
	{
		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Проект не найден.'];
		}

		$note    = UTF8::substr(trim((string) $note), 0, 500);
		$verdict = (string) $verdict;

		if ( ! in_array($verdict, ['approve', 'reject', 'feature', 'unfeature'], TRUE))
		{
			return ['ok' => FALSE, 'error' => 'Неизвестное решение: '.HTML::chars($verdict)];
		}

		try
		{
			$rev = ((int) $revision_id) > 0 ? self::revision_of($project_id, $revision_id) : NULL;

			if ($rev === NULL AND $verdict !== 'unfeature')
			{
				$rev = self::pending_revision($project_id);
			}

			if ($rev === NULL AND in_array($verdict, ['approve', 'feature', 'unfeature'], TRUE))
			{
				$rev = self::published_revision($project_id);
			}

			if ($verdict === 'unfeature')
			{
				self::_exec('UPDATE nj_667_projects SET featured = 0, updated_at = now()
					WHERE id = '.(int) $project_id);
				self::event($project_id, $verdict, $note, (int) $user_id);

				return ['ok' => TRUE, 'status' => $verdict];
			}

			if ($rev === NULL)
			{
				$set = ['updated_at = now()', 'reviewed_by = '.(int) $user_id, 'reviewed_at = now()',
					'moderation_note = '.self::_q($note), 'kind = \'public\''];

				switch ($verdict)
				{
					case 'approve':
						$set[] = 'status = '.self::_q(self::S_PUBLISHED);
						break;

					case 'reject':
						$set[] = 'status = '.self::_q(self::S_REJECTED);
						break;

					case 'feature':
						$set[] = 'status = '.self::_q(self::S_PUBLISHED);
						$set[] = 'featured = 1';
						break;

					case 'unfeature':
						$set[] = 'featured = 0';
						break;
				}

				self::_exec('UPDATE nj_667_projects SET '.implode(', ', $set).'
					WHERE id = '.(int) $project_id);

				self::event($project_id, $verdict, $note, (int) $user_id);

				return ['ok' => TRUE, 'status' => $verdict];
			}

			$rid     = (int) Arr::get($rev, 'id');
			$rev_set = ['moderation_note = '.self::_q($note),
				'reviewed_by = '.(int) $user_id, 'reviewed_at = now()'];

			if ($verdict === 'reject')
			{
				$rev_set[] = 'status = '.self::_q(self::S_REJECTED);
			}
			else
			{
				$rev_set[] = 'status = '.self::_q(self::S_PUBLISHED);
			}

			self::_exec('UPDATE nj_667_revisions SET '.implode(', ', $rev_set).'
				WHERE id = '.$rid.' AND project_id = '.(int) $project_id);

			$pset = ['updated_at = now()', 'reviewed_by = '.(int) $user_id,
				'reviewed_at = now()', 'moderation_note = '.self::_q($note),
				'kind = \'public\''];

			if ($verdict === 'reject')
			{
				if (self::published_revision($project_id) === NULL)
				{
					$pset[] = 'status = '.self::_q(self::S_REJECTED);
				}
			}
			else
			{
				$pset[] = 'status = '.self::_q(self::S_PUBLISHED);

				if ($verdict === 'feature')
				{
					$pset[] = 'featured = 1';
				}
			}

			self::_exec('UPDATE nj_667_projects SET '.implode(', ', $pset).'
				WHERE id = '.(int) $project_id);

			self::event($project_id, $verdict, $note, (int) $user_id);

			return ['ok' => TRUE, 'status' => $verdict,
				'revision_id' => $rid, 'revision_no' => (int) Arr::get($rev, 'no', 0)];
		}
		catch (Throwable $e)
		{
			$set = ['updated_at = now()', 'reviewed_by = '.(int) $user_id, 'reviewed_at = now()',
				'moderation_note = '.self::_q($note)];

			switch ($verdict)
			{
				case 'approve':
					$set[] = 'status = '.self::_q(self::S_PUBLISHED);
					break;

				case 'reject':
					$set[] = 'status = '.self::_q(self::S_REJECTED);
					break;

				case 'feature':
					$set[] = 'status = '.self::_q(self::S_PUBLISHED);
					$set[] = 'featured = 1';
					break;

				case 'unfeature':
					$set[] = 'featured = 0';
					break;
			}

			self::_exec('UPDATE nj_667_projects SET '.implode(', ', $set).'
				WHERE id = '.(int) $project_id);

			self::event($project_id, $verdict, $note, (int) $user_id);

			return ['ok' => TRUE, 'status' => $verdict];
		}
	}

	/**
	 * Remix: скопировать чужой опубликованный граф себе.
	 *
	 * Берём снимок ревизии (не живой graph_json): автор мог уже собрать
	 * другое изделие. Историю не копируем.
	 */
	public static function remix($code, $user_id, $revision_no = 0)
	{
		$source = self::project($code);

		if ($source === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Этот граф нельзя копировать: он не опубликован.'];
		}

		$rev   = self::published_revision((int) Arr::get($source, 'id'), (int) $revision_no);
		$graph = NULL;
		$title = trim((string) Arr::get($source, 'title'));
		$tags  = (string) Arr::get($source, 'tags', '');

		if ($rev !== NULL)
		{
			$graph = self::graph_of($rev);
			$alt   = trim((string) Arr::get($rev, 'title', ''));
			$title = $alt !== '' ? $alt : $title;
			$rtags = (string) Arr::get($rev, 'tags', '');
			$tags  = $rtags !== '' ? $rtags : $tags;
		}
		elseif ((string) Arr::get($source, 'status') === self::S_PUBLISHED)
		{
			$graph = self::graph_of($source);
		}

		if ($graph === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Этот граф нельзя копировать: он не опубликован.'];
		}

		$done = self::create((int) $user_id, [
			'title'    => $title.' (копия)',
			'tags'     => $tags,
			'graph'    => $graph,
			'remix_of' => (int) Arr::get($source, 'id'),
		]);

		if (Arr::get($done, 'ok'))
		{
			self::event((int) Arr::get($source, 'id'), 'remix', 'скопирован в проект '
				.Arr::get($done, 'code'), (int) $user_id);
		}

		return $done;
	}

	/** Запись в журнал проекта (модерация, заявки, remix). */
	public static function event($project_id, $kind, $note = '', $user_id = 0)
	{
		return self::_insert('nj_667_events', [
			'project_id' => (int) $project_id,
			'kind'       => UTF8::substr(trim((string) $kind), 0, 24),
			'note'       => UTF8::substr(trim((string) $note), 0, 500),
			'user_id'    => (int) $user_id,
		]);
	}

	public static function events($project_id, $limit = 30)
	{
		return self::_all('SELECT * FROM nj_667_events
			WHERE project_id = '.(int) $project_id.'
			ORDER BY id DESC LIMIT '.min(100, max(1, (int) $limit)));
	}

	// ------------------------------------------------------------- саммари

	/** Сохранить саммари и топологический отпечаток (из воркера). */
	public static function set_summary($project_id, $summary, $topo_hash = '')
	{
		$set = ['summary = '.self::_q(UTF8::substr(trim((string) $summary), 0,
			(int) self::limits('max_summary', 1000))), 'updated_at = now()'];

		if ($topo_hash !== '')
		{
			$set[] = 'topo_hash = '.self::_q(UTF8::substr((string) $topo_hash, 0, 64));
		}

		self::_exec('UPDATE nj_667_projects SET '.implode(', ', $set).'
			WHERE id = '.(int) $project_id);

		return TRUE;
	}

	/**
	 * Сохранить вектор саммари.
	 *
	 * Работает и с pgvector, и с real[]: тип колонки известен только базе,
	 * поэтому формат значения выбираем по ответу самой PostgreSQL.
	 */
	public static function set_embedding($project_id, array $vector, $model = '')
	{
		$vector = array_values(array_filter(array_map('floatval', $vector), 'is_finite'));

		if ( ! $vector)
		{
			return FALSE;
		}

		$cast = self::_embedding_cast($vector);

		self::_exec('UPDATE nj_667_projects
			SET embedding = '.$cast.',
				embedding_dims = '.count($vector).',
				embedding_model = '.self::_q(UTF8::substr((string) $model, 0, 160)).',
				updated_at = now()
			WHERE id = '.(int) $project_id);

		return TRUE;
	}

	/**
	 * Похожие опубликованные графы по вектору.
	 *
	 * Для RAG (few-shot модели) и для поиска в каталоге — один и тот же
	 * запрос: с pgvector это косинусное расстояние, без него — своя функция
	 * по real[] (медленнее, но каталог на сотни работ это терпит).
	 */
	public static function similar(array $vector, $limit = 6, $min_score = NULL)
	{
		$vector = array_values(array_filter(array_map('floatval', $vector), 'is_finite'));
		$limit  = min(30, max(1, (int) $limit));

		if ( ! $vector)
		{
			return [];
		}

		$min   = $min_score === NULL ? (float) Arr::get(self::branch('rag'), 'min_score', 0.25) : (float) $min_score;
		$cast  = self::_embedding_cast($vector);
		$score = self::_embedding_is_array()
			? '1 - nj_667_vec_dist(embedding, '.$cast.')'
			: '1 - (embedding <=> '.$cast.')';

		// Псевдоним score в WHERE использовать нельзя (PostgreSQL вычисляет
		// SELECT после WHERE) — выражение повторяем целиком.
		// score — косинусная близость: больше = ближе, поэтому DESC.
		return self::_all('SELECT id, code, title, summary, tags, nodes_count, edges_count,
				topo_hash, graph_json, '.$score.' AS score
			FROM nj_667_projects
			WHERE status = '.self::_q(self::S_PUBLISHED).'
				AND deleted_at IS NULL
				AND embedding IS NOT NULL
				AND '.$score.' >= '.(float) $min.'
			ORDER BY '.$score.' DESC
			LIMIT '.$limit);
	}

	/**
	 * Похожие опубликованные графы по топологическому отпечатку.
	 *
	 * Два стула с разными подписями имеют один hash: степени узлов и типы
	 * совпадают. Этого few-shot не хватало — искали только по вектору саммари
	 * (слова), и модель получала «похожий текст», а не похожую конструкцию.
	 *
	 * Невалидный хеш не ходит в базу: стенд без PostgreSQL это покрывает.
	 */
	public static function similar_by_topology($hash, $limit = 6, $exclude_id = 0)
	{
		$hash       = strtolower(trim((string) $hash));
		$limit      = min(30, max(1, (int) $limit));
		$exclude_id = (int) $exclude_id;

		if ( ! preg_match('/^[a-f0-9]{32}$/', $hash))
		{
			return [];
		}

		$extra = $exclude_id > 0 ? ' AND id <> '.$exclude_id : '';

		return self::_all('SELECT id, code, title, summary, tags, nodes_count, edges_count,
				topo_hash, graph_json, 1.0 AS score
			FROM nj_667_projects
			WHERE status = '.self::_q(self::S_PUBLISHED).'
				AND deleted_at IS NULL
				AND topo_hash = '.self::_q($hash).'
				'.$extra.'
			ORDER BY featured DESC, remixes DESC, updated_at DESC
			LIMIT '.$limit);
	}

	/** Текстовый поиск по каталогу (когда вектора ещё не посчитаны). */
	public static function search_text($query, $limit = 24)
	{
		$query = trim((string) $query);

		if ($query === '')
		{
			return self::catalog($limit);
		}

		$like  = self::_q('%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%');
		$limit = min(60, max(1, (int) $limit));

		return self::_all('SELECT *, '.self::_published_build_sql().' AS published_build_id FROM nj_667_projects
			WHERE status = '.self::_q(self::S_PUBLISHED).'
				AND deleted_at IS NULL
				AND (title ILIKE '.$like.' OR summary ILIKE '.$like.' OR tags ILIKE '.$like.')
			ORDER BY featured DESC, updated_at DESC
			LIMIT '.$limit);
	}

	/**
	 * Генерации nj667 для /graph3d/usage: основа — учёт nj_120_usage (живёт
	 * дольше задачи, см. nj120/005), задача и проект подтягиваются, если ещё
	 * есть. Промпт берём из payload задачи либо из payload_brief учёта — так
	 * история читается и после уборки монитора.
	 */
	public static function generations($limit = 40, $offset = 0, $query = '', $day = '')
	{
		$limit  = min(100, max(1, (int) $limit));
		$offset = max(0, (int) $offset);

		try
		{
			return self::_all('SELECT u.job_id AS id, u.channel,
					COALESCE(NULLIF(j.status, \'\'), NULLIF(u.status, \'\'), CASE WHEN u.had_error = 1 THEN \'failed\' ELSE \'done\' END) AS status,
					COALESCE(j.payload::text, u.payload_brief::text) AS payload,
					u.created_at, j.finished_at, j.created_by, u.visitor_id,
					u.prompt_tokens, u.completion_tokens, u.llm_calls, u.elapsed_ms, u.had_error,
					(j.id IS NULL) AS job_gone,
					p.code, p.title, p.user_id
				FROM nj_120_usage u
				LEFT JOIN nj_120_jobs j ON j.id = u.job_id
				LEFT JOIN nj_667_projects p ON p.id = '.self::_generations_project_id().'
				WHERE '.self::_generations_where($query, $day).'
				ORDER BY u.job_id DESC
				LIMIT '.$limit.' OFFSET '.$offset);
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	/** Сколько генераций nj667.* (для пагинации /graph3d/usage). */
	public static function generations_count($query = '', $day = '')
	{
		try
		{
			return (int) self::_value('SELECT count(*)
				FROM nj_120_usage u
				LEFT JOIN nj_120_jobs j ON j.id = u.job_id
				LEFT JOIN nj_667_projects p ON p.id = '.self::_generations_project_id().'
				WHERE '.self::_generations_where($query, $day));
		}
		catch (Throwable $e)
		{
			return 0;
		}
	}

	/**
	 * История по дням: генерации, токены, ошибки за последние $days суток.
	 * Это и есть «исторические значения» — раньше страница показывала только
	 * выживший хвост задач (владелец, 25.10.2026).
	 */
	public static function generations_by_day($days = 30, $query = '')
	{
		$days = min(366, max(1, (int) $days));

		try
		{
			return self::_all('SELECT to_char(u.created_at, \'YYYY-MM-DD\') AS day,
					count(*) AS jobs,
					count(DISTINCT NULLIF(u.visitor_id, \'\')) AS people,
					COALESCE(sum(u.prompt_tokens), 0) AS prompt_tokens,
					COALESCE(sum(u.completion_tokens), 0) AS completion_tokens,
					COALESCE(sum(u.llm_calls), 0) AS llm_calls,
					COALESCE(sum(u.had_error), 0) AS errors
				FROM nj_120_usage u
				LEFT JOIN nj_120_jobs j ON j.id = u.job_id
				LEFT JOIN nj_667_projects p ON p.id = '.self::_generations_project_id().'
				WHERE '.self::_generations_where($query).'
				  AND u.created_at >= now() - interval \''.$days.' days\'
				GROUP BY 1
				ORDER BY 1 DESC');
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	/** id проекта из payload задачи или среза учёта (задача могла быть удалена). */
	protected static function _generations_project_id()
	{
		return 'NULLIF(COALESCE(j.payload->>\'project_id\', u.payload_brief->>\'project_id\'), \'\')::bigint';
	}

	/** Условие поиска по генерациям: промпт, канал, код и название проекта, день. */
	protected static function _generations_where($query, $day = '')
	{
		$where = 'u.channel LIKE \'nj667.%\'';
		$query = trim((string) $query);
		$day   = trim((string) $day);

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day))
		{
			$where .= ' AND u.created_at >= '.self::_q($day).'::date AND u.created_at < '.self::_q($day).'::date + interval \'1 day\'';
		}

		if ($query === '')
		{
			return $where;
		}

		$like = self::_q('%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%');

		return $where.' AND (u.channel ILIKE '.$like
			.' OR COALESCE(p.title, \'\') ILIKE '.$like
			.' OR COALESCE(p.code, \'\') ILIKE '.$like
			.' OR COALESCE(j.payload->>\'prompt\', u.payload_brief->>\'prompt\', \'\') ILIKE '.$like.')';
	}

	/** Траты пользователей по каналам nj667.* */
	public static function generation_spend($limit = 30)
	{
		$limit = min(100, max(1, (int) $limit));

		try
		{
			return self::_all('SELECT COALESCE(u.user_id, 0) AS user_id,
					COALESCE(us.username, NULLIF(u.visitor_id, \'\'), \'?\') AS who,
					count(*) AS jobs,
					COALESCE(sum(u.prompt_tokens), 0) AS prompt_tokens,
					COALESCE(sum(u.completion_tokens), 0) AS completion_tokens
				FROM nj_120_usage u
				LEFT JOIN users us ON us.id = u.user_id
				WHERE u.channel LIKE \'nj667.%\'
				GROUP BY 1, 2
				ORDER BY (COALESCE(sum(u.prompt_tokens), 0) + COALESCE(sum(u.completion_tokens), 0)) DESC
				LIMIT '.$limit);
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	// ------------------------------------------------------------ для воркера

	/**
	 * Контекст для воркера: граф (или его часть), лимиты и похожие работы.
	 *
	 * Похожие — RAG только для ПУСТОГО проекта: модель видит образец масштаба.
	 * Если граф уже есть, примеры не кладём: иначе модель копирует первый
	 * стул из каталога вместо ромбододекаэдра, который правит человек
	 * (владелец, 10.10.2026).
	 *
	 * revision_id — снимок заявки на публикацию: саммари и пре-модерация
	 * считают тот граф, что ушёл модератору, а не живую копию.
	 */
	public static function worker_context($project_id, array $opt = [])
	{
		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Проект не найден.'];
		}

		$rev_id = (int) Arr::get($opt, 'revision_id', 0);
		$rev    = $rev_id > 0 ? self::revision_of($project_id, $rev_id) : NULL;
		$graph  = $rev !== NULL ? self::graph_of($rev) : self::graph_of($project);
		$full   = $graph;
		$meta   = $rev !== NULL ? $rev : $project;

		if ((string) Arr::get($opt, 'mode') === 'inpaint')
		{
			$graph = self::graph_neighborhood($graph, (array) Arr::get($opt, 'node_ids', []),
				(int) Arr::get($opt, 'depth', 1));
		}

		$examples = [];

		if ((string) Arr::get($opt, 'mode', 'generate') === 'generate'
			AND ! count((array) Arr::get($full, 'nodes', []))
			AND self::rag_from($opt))
		{
			foreach (self::few_shot((string) Arr::get($opt, 'prompt', '')
				?: (string) Arr::get($project, 'title', ''),
				(int) Arr::get($project, 'id', 0)) as $row)
			{
				$examples[] = [
					'title'     => (string) Arr::get($row, 'title'),
					'summary'   => UTF8::substr((string) Arr::get($row, 'summary'), 0, 400),
					'graph'     => self::graph_of($row),
					'why'       => (string) Arr::get($row, 'why', ''),
					'topo_hash' => (string) Arr::get($row, 'topo_hash', ''),
				];
			}
		}

		$guard = self::graph_validate($full, FALSE);

		$out = [
			'ok'         => TRUE,
			'project_id' => (int) $project_id,
			'code'       => (string) Arr::get($project, 'code'),
			'title'      => (string) Arr::get($meta, 'title') ?: (string) Arr::get($project, 'title'),
			'tags'       => (string) Arr::get($meta, 'tags') ?: (string) Arr::get($project, 'tags'),
			'summary'    => (string) Arr::get($meta, 'summary') ?: (string) Arr::get($project, 'summary'),
			'mode'       => (string) Arr::get($opt, 'mode', 'generate'),
			'prompt'     => (string) Arr::get($opt, 'prompt', ''),
			'node_ids'   => array_values((array) Arr::get($opt, 'node_ids', [])),
			'revision_id'=> $rev_id,
			'graph'      => $graph,
			'text'       => self::graph_text($full),
			'topo_hash'  => self::topology_hash($full),
			'isolated'   => (int) Arr::get(Arr::get($guard, 'stats', []), 'isolated', 0),
			'limits'     => self::limits(),
			'canvas'     => self::canvas(),
			'node_types' => array_keys(self::node_types()),
			'edge_types' => array_keys(self::edge_types()),
			'examples'      => $examples,
			'rag'           => self::rag_from($opt) ? TRUE : FALSE,
			'photo_max_px'  => self::photo_max_px(),
		];

		if ((string) Arr::get($opt, 'mode', 'generate') === 'generate')
		{
			$out['photos'] = self::photos_payload((string) Arr::get($project, 'code'));
		}

		return $out;
	}

	/**
	 * Примеры для few-shot (RAG).
	 *
	 * Три пути, в порядке пользы модели: тот же топологический отпечаток
	 * (каркас изделия), близкий вектор саммари (смысл описания), текстовый
	 * поиск по каталогу. Склеивает few_shot_merge — свой проект и дубли
	 * выкидываются. Пустой результат так и остаётся пустым: подставлять
	 * весь каталог нельзя — модель копирует первый опубликованный стул
	 * вместо текущей конструкции (владелец, 10.10.2026).
	 */
	protected static function few_shot($query, $project_id = 0)
	{
		$limit = max(1, min(4, (int) Arr::get(self::branch('rag'), 'few_shot', 2)));
		$topo  = [];
		$embed = [];
		$text  = [];

		if ($project_id > 0)
		{
			$row = self::_one('SELECT embedding, topo_hash FROM nj_667_projects
				WHERE id = '.(int) $project_id.' LIMIT 1');

			if ($row !== NULL)
			{
				$hash = strtolower(trim((string) Arr::get($row, 'topo_hash', '')));

				if (preg_match('/^[a-f0-9]{32}$/', $hash))
				{
					$topo = self::similar_by_topology($hash, $limit, $project_id);
				}

				$vector = self::_vector_of(Arr::get($row, 'embedding'));

				if ($vector)
				{
					$embed = self::similar($vector, $limit);
				}
			}
		}

		if ($query !== '')
		{
			$text = self::search_text($query, $limit);
		}

		return self::few_shot_merge($topo, $embed, $text, $limit, $project_id);
	}

	/** Вектор из колонки: pgvector ('[1,2]') или real[] ('{1,2}') → массив. */
	protected static function _vector_of($raw)
	{
		$raw = trim((string) $raw, '[]{}');

		if ($raw === '')
		{
			return [];
		}

		$out = [];

		foreach (explode(',', $raw) as $part)
		{
			$part = trim($part);

			if (is_numeric($part))
			{
				$out[] = (float) $part;
			}
		}

		return $out;
	}

	/**
	 * Принять граф от воркера: почистить, проверить, сохранить.
	 *
	 * merge=TRUE — достройка (inpaint): новые узлы и связи добавляются к
	 * существующим, а не заменяют весь граф. Именно за этим пользователь
	 * выделял узел и писал «добавь подлокотники».
	 */
	public static function apply_graph($project_id, array $graph, $merge = FALSE, $author_kind = 'ai', $label = '')
	{
		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Проект не найден.'];
		}

		$incoming = self::graph_clean($graph);

		if ($merge)
		{
			$current = self::graph_of($project);
			$incoming = self::graph_merge($current, $incoming);
		}

		$guard = self::graph_validate($incoming);

		if ( ! Arr::get($guard, 'ok'))
		{
			return ['ok' => FALSE, 'error' => implode(' ', (array) Arr::get($guard, 'errors', [])),
				'stats' => Arr::get($guard, 'stats', [])];
		}

		$done = self::save_graph($project_id, $incoming, $author_kind, $label);

		if ( ! Arr::get($done, 'ok'))
		{
			return $done;
		}

		self::clear_job($project_id);

		return ['ok' => TRUE, 'graph' => Arr::get($done, 'graph'), 'ops' => (int) Arr::get($done, 'ops', 0)];
	}

	/**
	 * Сбросить job_id проекта: сборка кончилась (успех или ошибка).
	 *
	 * Иначе оверлей на холсте крутится, пока страница не истечёт по таймауту:
	 * job_id живой, а задачи уже нет. Владелец, 11.10.2026.
	 */
	public static function clear_job($project_id)
	{
		$project_id = (int) $project_id;

		if ($project_id <= 0)
		{
			return;
		}

		try
		{
			self::_exec('UPDATE nj_667_projects SET job_id = 0, updated_at = now()
				WHERE id = '.$project_id);
		}
		catch (Throwable $e)
		{
			self::_log(Log::WARNING, 'не сбросил job_id проекта :id: :e',
				[':id' => $project_id, ':e' => $e->getMessage()]);
		}
	}

	public static function graph_merge(array $current, array $incoming)
	{
		return Service_Nj667_Graph::graph_merge($current, $incoming);
	}

	// -------------------------------------------------------------- очередь

	/** Поставить задачу воркеру. Канал и таймаут — по названию задачи. */
	public static function queue($channel, array $project, array $payload = [], array $opts = [])
	{
		if ( ! class_exists('Service_Nj120_JobQueue'))
		{
			return ['ok' => FALSE, 'error' => 'Очередь недоступна (модуль nj120 не подключён).'];
		}

		$project_id = (int) Arr::get($project, 'id', 0);

		if ($project_id <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Нет проекта для задачи.'];
		}

		$key     = UTF8::substr((string) Arr::get(explode('.', $channel), 1, 'generate'), 0, 24);
		// timeout_sec в $opts — для задач длиннее одного вызова модели: пачка
		// узлов к печати это N рендеров, и бюджет у неё свой (config).
		$timeout = (int) Arr::get($opts, 'timeout_sec', 0) > 0
			? (int) Arr::get($opts, 'timeout_sec', 0)
			: self::timeout($key);

		// Сборка — NJ667_MODEL / админка. Саммари, идеи, карточка, пре-модерация
		// — NJ667_LIGHT_MODEL, если задана (владелец, 11.10.2026).
		// CH_BUILD — не вызов модели (это OpenSCAD), модель ему не нужна.
		if (trim((string) Arr::get($payload, 'model', '')) === '' AND $channel !== self::CH_BUILD)
		{
			$light = in_array($channel, [
				self::CH_SUMMARY, self::CH_MODERATE, self::CH_IDEAS, self::CH_CAPTION,
			], TRUE);
			$chosen = $light ? self::light_model() : self::model();

			if ($chosen !== '')
			{
				$payload['model'] = $chosen;
			}
		}

		$counts_quota = self::counts_toward_llm_quota($channel);
		$quota_user_id = max(0, (int) Arr::get($opts, 'quota_user_id', Arr::get($project, 'user_id', 0)));
		$db = NULL;
		$transaction = FALSE;
		$quota = NULL;

		try
		{
			if ($counts_quota)
			{
				// Счётчик, job и project.job_id коммитятся вместе. Если очередь
				// отказала до принятия задачи, дневной лимит не расходуется.
				$db = self::_db();
				$db->begin();
				$transaction = TRUE;

				$reservation = self::_reserve_llm_quota($quota_user_id);

				if ( ! Arr::get($reservation, 'ok'))
				{
					$db->rollback();
					$transaction = FALSE;

					return $reservation;
				}

				$quota = (array) Arr::get($reservation, 'quota', []);
			}

			$job_id = Service_Nj120_JobQueue::push($channel, $payload + ['project_id' => $project_id], [
				'module'       => 'nj667',
				'visitor_id'   => 'u:'.(int) Arr::get($project, 'user_id', 0),
				'created_by'   => (int) Arr::get($project, 'user_id', 0),
				// Один заход: «ответ не JSON» на ретрае тот же, а оверлей
				// крутится ещё минуты. Владелец, 11.10.2026.
				'max_attempts' => 1,
				'timeout_sec'  => $timeout,
			]);

			if ((int) $job_id <= 0)
			{
				throw new RuntimeException('Очередь не вернула id задачи.');
			}

			// Оверлей холста — только генерация и достройка, не summary/embed.
			if ($counts_quota)
			{
				self::_exec('UPDATE nj_667_projects SET job_id = '.(int) $job_id.', updated_at = now()
					WHERE id = '.$project_id);
			}

			if ($transaction)
			{
				$db->commit();
				$transaction = FALSE;
			}
		}
		catch (Throwable $e)
		{
			if ($transaction AND $db)
			{
				$db->rollback();
			}

			self::_log(Log::ERROR, 'очередь не приняла :channel проекта :id: :e',
				[':channel' => $channel, ':id' => $project_id, ':e' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Очередь не приняла задачу: '.$e->getMessage()];
		}

		$out = ['ok' => TRUE, 'job_id' => (int) $job_id];

		if ($quota !== NULL)
		{
			$out['quota'] = $quota;
		}

		return $out;
	}

	/** Резервирование дневного счётчика; вызывается из queue() внутри транзакции. */
	protected static function _reserve_llm_quota($user_id)
	{
		$user_id = (int) $user_id;
		$limit   = (int) Arr::get(self::quota_limits($user_id), 'llm_operations_per_day', 5);

		if ($user_id <= 0)
		{
			return ['ok' => FALSE, 'http_status' => 401, 'quota_rejected' => TRUE,
				'error' => 'Для платной LLM-операции нужен зарегистрированный аккаунт.'];
		}

		if ($limit <= 0)
		{
			$status = self::llm_quota_state(0, $limit);

			return ['ok' => FALSE, 'http_status' => 429, 'quota_rejected' => TRUE,
				'quota' => $status, 'error' => 'Для этого профиля сейчас нет доступных LLM-операций.'];
		}

		try
		{
			$row = self::_one('INSERT INTO nj_667_daily_usage
				(user_id, usage_day, operation, used, updated_at)
				VALUES ('.$user_id.', (now() AT TIME ZONE \'UTC\')::date, '
					.self::_q(self::QUOTA_LLM_OPERATION).', 1, now())
				ON CONFLICT (user_id, usage_day, operation)
				DO UPDATE SET used = nj_667_daily_usage.used + 1, updated_at = now()
				WHERE nj_667_daily_usage.used < '.$limit.'
				RETURNING used');

			if ($row === NULL)
			{
				$status = self::llm_quota_status($user_id);

				return ['ok' => FALSE, 'http_status' => 429, 'quota_rejected' => TRUE,
					'quota' => $status,
					'error' => 'Дневной лимит LLM-операций исчерпан: '
						.(int) Arr::get($status, 'used', $limit).' из '.$limit
						.'. Счётчик обновится после полуночи UTC.'];
			}

			$status = self::llm_quota_state((int) Arr::get($row, 'used', 0), $limit);

			return ['ok' => TRUE, 'quota' => $status];
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'квота LLM пользователя :id не зарезервирована: :e',
				[':id' => $user_id, ':e' => $e->getMessage()]);

			return ['ok' => FALSE, 'http_status' => 503, 'quota_rejected' => TRUE,
				'quota' => ['ready' => FALSE, 'allowed' => FALSE, 'used' => 0,
					'limit' => $limit, 'remaining' => 0, 'timezone' => 'UTC'],
				'error' => 'Дневной учёт LLM-операций недоступен. Примените миграции NJ-667/012.'];
		}
	}

	// ------------------------------------------------------------- доступ

	/** Проект принадлежит владельцу (по аккаунту или по метке посетителя). */
	public static function owns(array $project, $user_id, $uid = '')
	{
		$owner = (int) Arr::get($project, 'user_id', 0);

		if ($owner > 0 AND (int) $user_id > 0 AND $owner === (int) $user_id)
		{
			return TRUE;
		}

		$visitor = (string) Arr::get($project, 'visitor_uid', '');

		return $visitor !== '' AND $uid !== '' AND $visitor === (string) $uid;
	}

	/** Читать можно, если есть опубликованная ревизия, либо это ваш проект. */
	public static function can_read(array $project, $user_id, $uid = '')
	{
		if (self::owns($project, $user_id, $uid))
		{
			return TRUE;
		}

		// модератор читает любой проект и сборку (режим контроля, 25.10.2026);
		// записи это не открывает — owns() проверяется отдельно
		if (self::moderates($user_id))
		{
			return TRUE;
		}

		if ((string) Arr::get($project, 'status') === self::S_PUBLISHED)
		{
			return TRUE;
		}

		$id = (int) Arr::get($project, 'id', 0);

		return $id > 0 AND self::published_revision($id) !== NULL;
	}

	/**
	 * Есть ли у пользователя право nj667.moderate (роли из БД, кэш на запрос).
	 * Нужно в сервисе, а не только в контроллере: can_read() зовут и API,
	 * и страница сборки /graph3d/b/<code>.
	 */
	public static function moderates($user_id)
	{
		static $cache = [];

		$user_id = (int) $user_id;

		if ($user_id <= 0)
		{
			return FALSE;
		}

		if ( ! array_key_exists($user_id, $cache))
		{
			$roles = [];

			try
			{
				foreach (self::_all('SELECT r.name FROM roles_users ru
					JOIN roles r ON r.id = ru.role_id WHERE ru.user_id = '.$user_id) as $row)
				{
					$roles[] = (string) Arr::get($row, 'name');
				}
			}
			catch (Throwable $e)
			{
				$roles = [];
			}

			$cache[$user_id] = (in_array('admin', $roles, TRUE)
				OR ($roles AND class_exists('ACL') AND (bool) ACL::check($roles, 'nj667.moderate')));
		}

		return $cache[$user_id];
	}

	/**
	 * Страница/файлы неопубликованной сборки доступны только зарегистрированным.
	 * Доступ к самому графу по-прежнему проверяет can_read(): регистрация не
	 * раскрывает чужой закрытый проект.
	 */
	public static function can_read_build(array $project, array $build, $user_id, $uid = '', $registered = FALSE)
	{
		if ( ! self::can_read($project, $user_id, $uid))
		{
			return FALSE;
		}

		return trim((string) Arr::get($build, 'published_at', '')) !== '' OR (bool) $registered;
	}

	/** Владелец проекта или зарегистрированный автор своей сборки может готовить STL-узлы. */
	public static function can_prepare_build_parts(array $project, array $build, $user_id,
		$uid = '', $registered = FALSE)
	{
		$user_id = (int) $user_id;

		if ((int) Arr::get($project, 'id', 0) !== (int) Arr::get($build, 'project_id', 0)
			OR ! self::can_read_build($project, $build, $user_id, $uid, $registered))
		{
			return FALSE;
		}

		return self::owns($project, $user_id, $uid)
			OR ((bool) $registered AND $user_id > 0
				AND (int) Arr::get($build, 'user_id', 0) === $user_id);
	}

	public static function rate_ok()
	{
		if ( ! class_exists('Service_Nj100_Limiter'))
		{
			return TRUE;
		}

		return (bool) Service_Nj100_Limiter::ok('nj667', Arr::get(self::branch('rate'), 'per_minute', 6),
			Arr::get(self::branch('rate'), 'per_hour', 60));
	}

	public static function rate_text()
	{
		return (int) Arr::get(self::branch('rate'), 'per_minute', 6).' в минуту, '
			.(int) Arr::get(self::branch('rate'), 'per_hour', 60).' в час';
	}

	// ------------------------------------------------------------- чистка

	/**
	 * Мягкое удаление: строка помечается `deleted_at`, из списков пропадает.
	 *
	 * $force — модератор (право nj667.moderate) может снять чужой проект.
	 */
	public static function delete($code, $user_id, $uid = '', $force = FALSE)
	{
		$project = self::project($code);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Проект не найден.'];
		}

		if ( ! $force AND ! self::owns($project, $user_id, $uid))
		{
			return ['ok' => FALSE, 'error' => 'Это чужой проект.'];
		}

		self::_exec('UPDATE nj_667_projects SET deleted_at = now(), updated_at = now()
			WHERE id = '.(int) Arr::get($project, 'id'));

		return ['ok' => TRUE];
	}

	/** Проекты, у которых нет владельца-аккаунта и которые давно не трогали. */
	public static function sweep($limit = NULL)
	{
		$days  = (int) Arr::get(self::config(), 'ttl_days', 180);
		$limit = (int) ($limit ?: Arr::get(self::config(), 'sweep_limit', 20));

		self::_exec('UPDATE nj_667_projects
			SET deleted_at = now()
			WHERE id IN (
				SELECT id FROM nj_667_projects
				WHERE deleted_at IS NULL
					AND user_id = 0
					AND status = '.self::_q(self::S_DRAFT).'
					AND updated_at < now() - interval \''.$days.' days\'
				LIMIT '.$limit.')');

		return TRUE;
	}

	// ---------------------------------------------------------- мелочи проекта

	/** Счётчик просмотров публичной страницы. */
	public static function bump_views($project_id)
	{
		self::_exec('UPDATE nj_667_projects SET views = views + 1 WHERE id = '.(int) $project_id);

		return TRUE;
	}

	/** Какая модель строила граф (видно в каталоге и в журнале). */
	public static function set_model($project_id, $model)
	{
		self::_exec('UPDATE nj_667_projects SET model = '
			.self::_q(UTF8::substr((string) $model, 0, 160)).'
			WHERE id = '.(int) $project_id);

		return TRUE;
	}

	/**
	 * Пометка пре-модерации в settings_json.
	 *
	 * Отдельных колонок под вердикт модели нет намеренно: это подсказка
	 * человеку, а не состояние публикации. Состояние одно — status.
	 */
	public static function set_guard($project_id, $verdict, $score = 0.0, $note = '')
	{
		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return FALSE;
		}

		$settings = json_decode((string) Arr::get($project, 'settings_json', ''), TRUE);

		if ( ! is_array($settings))
		{
			$settings = [];
		}

		$settings['guard'] = [
			'verdict' => in_array((string) $verdict, ['ok', 'review', 'reject'], TRUE) ? (string) $verdict : 'review',
			'score'   => round(max(0.0, min(1.0, (float) $score)), 3),
			'note'    => UTF8::substr((string) $note, 0, 480),
			'at'      => date('c'),
		];

		self::_exec('UPDATE nj_667_projects SET settings_json = '
			.self::_q(json_encode($settings, JSON_UNESCAPED_UNICODE)).'
			WHERE id = '.(int) $project_id);

		return TRUE;
	}

	public static function svg_preview(array $graph, $width = 480, $height = 320, $night = FALSE)
	{
		return Service_Nj667_Graph::svg_preview($graph, $width, $height, $night);
	}

	// ---------------------------------------------- чертёж (дверь в Drawing)

	public static function drawing_units()
	{
		return Service_Nj667_Drawing::drawing_units();
	}

	public static function drawing_scales()
	{
		return Service_Nj667_Drawing::drawing_scales();
	}

	public static function drawing_opt(array $opt = [])
	{
		return Service_Nj667_Drawing::drawing_opt($opt);
	}

	public static function drawing_prefs(array $project)
	{
		return Service_Nj667_Drawing::drawing_prefs($project);
	}

	/** Запомнить единицу и масштаб чертежа в проекте. */
	public static function set_drawing($project_id, array $opt)
	{
		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Проект не найден.'];
		}

		$opt      = self::drawing_opt($opt);
		$settings = json_decode((string) Arr::get($project, 'settings_json', ''), TRUE);

		if ( ! is_array($settings))
		{
			$settings = [];
		}

		$settings['drawing'] = [
			'unit'      => $opt['unit'],
			'unit_size' => $opt['unit_size'],
			'scale'     => $opt['scale'],
			'paper'     => $opt['paper'],
		];

		try
		{
			self::_exec('UPDATE nj_667_projects SET settings_json = '
				.self::_q(json_encode($settings, JSON_UNESCAPED_UNICODE))
				.', updated_at = now() WHERE id = '.(int) $project_id);

			return ['ok' => TRUE];
		}
		catch (Throwable $e)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось сохранить масштаб.'];
		}
	}

	public static function drawing_fmt($value, $unit)
	{
		return Service_Nj667_Drawing::drawing_fmt($value, $unit);
	}

	public static function drawing_plan(array $graph, array $opt = [])
	{
		return Service_Nj667_Drawing::drawing_plan($graph, $opt);
	}

	public static function drawing_svg(array $graph, array $opt = [])
	{
		return Service_Nj667_Drawing::drawing_svg($graph, $opt);
	}

	public static function drawing_view_svgs(array $graph, array $opt = [])
	{
		return Service_Nj667_Drawing::drawing_view_svgs($graph, $opt);
	}

	public static function drawing_csv(array $graph, array $opt = [])
	{
		return Service_Nj667_Drawing::drawing_csv($graph, $opt);
	}

	// ------------------------------------ сборка в изделие (дверь в Build)
	//
	// Геометрию, кластеры и BOM считает Service_Nj667_Build (чистые функции,
	// покрыты стендом). Здесь — выбор типа узла, снимок сборки, файлы и задача
	// на рендер. STL рисует НЕ сайт: CSG — это минуты, веб-процесс столько
	// ждать не может, поэтому openSCAD живёт в воркере.

	public static function build_types()
	{
		return Service_Nj667_Build::types();
	}

	/** Параметры типа узла для формы: сечение типа + общие посадки. */
	public static function build_type_params($type)
	{
		return Service_Nj667_Build::type_params($type);
	}

	public static function build_qualities()
	{
		return Service_Nj667_Build::qualities();
	}

	/** Всё ли на месте в scad-библиотеке (иначе рендерить нечем). */
	public static function build_lib_missing($type = NULL)
	{
		return Service_Nj667_Build::scad_missing($type);
	}

	public static function build_opt(array $raw = [], array $saved = [])
	{
		return Service_Nj667_Build::opt($raw, $saved);
	}

	public static function build_geometry(array $graph, array $opt)
	{
		return Service_Nj667_Build::geometry($graph, $opt);
	}

	public static function build_scad(array $geom, array $opt, $note = '')
	{
		return Service_Nj667_Build::scad($geom, $opt, $note);
	}

	public static function build_spec_csv(array $geom, array $opt)
	{
		return Service_Nj667_Build::spec_csv($geom, $opt);
	}

	public static function build_part_svg(array $geom, array $opt, $t)
	{
		return Service_Nj667_Build::part_svg($geom, $opt, $t);
	}

	/** Сохранённый выбор человека (settings_json.build) — тип, посадки, масштаб. */
	public static function build_prefs(array $project)
	{
		$settings = json_decode((string) Arr::get($project, 'settings_json', ''), TRUE);
		$build    = is_array($settings) ? (array) Arr::get($settings, 'build', []) : [];

		return $build;
	}

	/** Запомнить параметры сборки в проекте (как чертеж: единицы общие). */
	public static function set_build_prefs($project_id, array $opt)
	{
		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Проект не найден.'];
		}

		$settings = json_decode((string) Arr::get($project, 'settings_json', ''), TRUE);

		if ( ! is_array($settings))
		{
			$settings = [];
		}

		$settings['build'] = [
			'type'        => $opt['type'],
			'quality'     => $opt['quality'],
			'params'      => $opt['params'],
			'unit'        => $opt['unit'],
			'unit_size'   => $opt['unit_size'],
			'merge'       => (int) $opt['merge'],
			'perforation' => (int) $opt['perforation'],
			'show_sticks' => (int) $opt['show_sticks'],
		];

		try
		{
			self::_exec('UPDATE nj_667_projects SET settings_json = '
				.self::_q(json_encode($settings, JSON_UNESCAPED_UNICODE))
				.', updated_at = now() WHERE id = '.(int) $project_id);

			return ['ok' => TRUE];
		}
		catch (Throwable $e)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось сохранить параметры сборки.'];
		}
	}

	/** Параметры одношагового режима из settings_json (пустой массив — обычный проект). */
	public static function simple_flow_state(array $project)
	{
		$settings = json_decode((string) Arr::get($project, 'settings_json', ''), TRUE);
		$flow     = is_array($settings) ? Arr::get($settings, 'simple_flow', []) : [];

		if ( ! is_array($flow) OR empty($flow['enabled']))
		{
			return [];
		}

		return [
			'enabled'       => TRUE,
			'state'         => (string) Arr::get($flow, 'state', 'waiting'),
			'build_id'      => (int) Arr::get($flow, 'build_id', 0),
			'build_job_id'  => (int) Arr::get($flow, 'build_job_id', 0),
			'parts_job_id'  => (int) Arr::get($flow, 'parts_job_id', 0),
			'error'         => UTF8::substr(trim((string) Arr::get($flow, 'error', '')), 0, 480),
		];
	}

	/** Включить автосборку после того, как воркер примет граф. */
	public static function simple_flow_arm($project_id)
	{
		return self::simple_flow_write((int) $project_id, [
			'enabled'      => TRUE,
			'state'        => 'waiting',
			'build_id'     => 0,
			'build_job_id' => 0,
			'parts_job_id' => 0,
			'error'        => '',
		]);
	}

	/** Зафиксировать отказ запуска очереди, чтобы экран автосборки не ждал вечно. */
	public static function simple_flow_fail($project_id, $error)
	{
		return self::simple_flow_write((int) $project_id, [
			'enabled' => TRUE,
			'state' => 'error',
			'error' => UTF8::substr(trim((string) $error), 0, 480),
		]);
	}

	/**
	 * Создать изделие из плоских палочек и поставить модель + комплект узлов
	 * в очередь. Вызывается только после успешного приёма графа воркером.
	 */
	public static function simple_flow_start($project_id)
	{
		$project = self::project_by_id((int) $project_id);

		if ($project === NULL)
		{
			return ['enabled' => FALSE, 'state' => 'missing'];
		}

		$flow = self::simple_flow_state($project);

		if ( ! $flow OR (string) Arr::get($flow, 'state') !== 'waiting')
		{
			return $flow;
		}

		self::simple_flow_write($project_id, ['state' => 'starting', 'error' => '']);
		$build_id = 0;

		try
		{
			$graph = self::graph_of($project);
			$guard = self::graph_validate($graph);

			if ( ! Arr::get($guard, 'ok'))
			{
				$error = 'Граф не прошёл проверку: '.implode(' ', (array) Arr::get($guard, 'errors', []));
				self::simple_flow_write($project_id, ['state' => 'error', 'error' => $error]);

				return ['enabled' => TRUE, 'state' => 'error', 'error' => $error];
			}

			// Весь «простой» маршрут использует один безопасный профиль:
			// плоская палочка 10×2,2 мм, 10 мм на клетку, быстрый рендер.
			$opt = self::build_opt([
				'type'        => 'flat',
				'quality'     => 'low',
				'unit'        => 'mm',
				'unit_size'   => 10,
				'merge'       => 1,
				'perforation' => 0,
				'show_sticks' => 1,
			]);

			$made = self::build_create((int) $project_id, (int) Arr::get($project, 'user_id', 0),
				$graph, $opt, 'автоматическая сборка из палочек');

			if ( ! Arr::get($made, 'ok'))
			{
				$error = (string) Arr::get($made, 'error', 'Не удалось рассчитать конструкцию.');
				self::simple_flow_write($project_id, ['state' => 'error', 'error' => $error]);

				return ['enabled' => TRUE, 'state' => 'error', 'error' => $error];
			}

			$build_id = (int) Arr::get($made, 'build_id', 0);
			self::set_build_prefs((int) $project_id, $opt);
			self::simple_flow_write($project_id, ['build_id' => $build_id]);

			$render = self::build_queue($build_id, $project, $opt);
			$parts  = self::build_parts_render($build_id, $project, $graph, $opt);
			$errors = [];

			if ( ! Arr::get($render, 'ok'))
			{
				$errors[] = 'модель сборки: '.(string) Arr::get($render, 'error', 'не встала в очередь');
			}

			if ( ! Arr::get($parts, 'ok'))
			{
				$errors[] = 'детали: '.(string) Arr::get($parts, 'error', 'не встали в очередь');
			}

			$state = $errors ? 'partial' : 'queued';
			$error = UTF8::substr(implode(' ', $errors), 0, 480);
			$info  = [
				'state'        => $state,
				'build_id'     => $build_id,
				'build_job_id' => (int) Arr::get($render, 'job_id', 0),
				'parts_job_id' => (int) Arr::get($parts, 'job_id', 0),
				'error'        => $error,
			];

			self::simple_flow_write($project_id, $info);

			return ['enabled' => TRUE] + $info;
		}
		catch (Throwable $e)
		{
			$error = $build_id > 0
				? 'Сборка создана, но автоматический запуск не завершился.'
				: 'Автоматическую сборку не удалось подготовить.';
			self::_log(Log::ERROR, 'простая сборка проекта :id прервана: :error',
				[':id' => (int) $project_id, ':error' => $e->getMessage()]);
			$info = [
				'state' => $build_id > 0 ? 'partial' : 'error',
				'build_id' => $build_id,
				'error' => $error,
			];
			self::simple_flow_write($project_id, $info);

			return ['enabled' => TRUE] + $info;
		}
	}

	/** Сохранить состояние простого маршрута, не затирая настройки сборки/чертежа. */
	protected static function simple_flow_write($project_id, array $patch)
	{
		$project = self::project_by_id((int) $project_id);

		if ($project === NULL)
		{
			return FALSE;
		}

		$settings = json_decode((string) Arr::get($project, 'settings_json', ''), TRUE);

		if ( ! is_array($settings))
		{
			$settings = [];
		}

		$flow = array_merge((array) Arr::get($settings, 'simple_flow', []), $patch);
		$flow['updated_at'] = date('c');
		$settings['simple_flow'] = $flow;

		try
		{
			self::_exec('UPDATE nj_667_projects SET settings_json = '
				.self::_q(json_encode($settings, JSON_UNESCAPED_UNICODE))
				.', updated_at = now() WHERE id = '.(int) $project_id);

			return TRUE;
		}
		catch (Throwable $e)
		{
			self::_log(Log::WARNING, 'состояние простой сборки проекта :id не записалось: :error',
				[':id' => (int) $project_id, ':error' => $e->getMessage()]);

			return FALSE;
		}
	}

	/**
	 * Последняя сборка проекта (или NULL). Нужна и странице, и публичной
	 * карточке: там показывают либо готовый STL, либо «соберите изделие».
	 */
	/** Последняя сборка пользователя; чужие незапубликованные сборки не подменяют его текущую. */
	public static function build_current($project_id, $user_id = 0)
	{
		$project_id = (int) $project_id;
		$user_id    = max(0, (int) $user_id);
		$mine       = $user_id > 0 ? ' OR user_id = '.$user_id : '';
		$prefer     = $user_id > 0 ? 'CASE WHEN user_id = '.$user_id.' THEN 1 ELSE 0 END DESC, ' : '';

		return self::_one('SELECT * FROM nj_667_builds
			WHERE project_id = '.$project_id.' AND (published_at IS NOT NULL'.$mine.')
			ORDER BY '.$prefer.'id DESC LIMIT 1');
	}

	/**
	 * Опубликовать сборку (только админ — право проверяет контроллер).
	 *
	 * Одна опубликованная сборка на граф: прежняя снимается. Граф публикуется сразу,
	 * без пре-модерации: решение админа. Публиковать можно только собранную сборку
	 * с моделью на диске.
	 */
	public static function build_publish($build_id, $user_id = 0, $project_id = 0)
	{
		$build = self::build_get($build_id);

		if ( ! is_array($build) OR ($project_id > 0 AND (int) $build['project_id'] !== (int) $project_id))
		{
			return ['ok' => FALSE, 'error' => 'Сборка не найдена.'];
		}

		$project = self::project_by_id((int) $build['project_id']);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Граф не найден или удалён.'];
		}

		if ( ! in_array((string) $build['status'], ['done', 'published'], TRUE)
			OR (int) Arr::get($build, 'glb_size', 0) <= 0
			OR self::build_file_path($build, 'glb') === '')
		{
			return ['ok' => FALSE, 'error' => 'Для публикации нужен готовый GLB сборки. Сначала соберите изделие в 3D.'];
		}

		$id  = (int) $build['id'];
		$pid = (int) $project['id'];

		self::_exec('UPDATE nj_667_builds SET published_at = NULL, published_by = 0,
			status = CASE WHEN status = \'published\' THEN \'done\' ELSE status END
			WHERE project_id = '.$pid.' AND published_at IS NOT NULL AND id <> '.$id);
		self::_exec('UPDATE nj_667_builds SET published_at = now(), published_by = '.(int) $user_id.',
			status = \'published\'
			WHERE id = '.$id);

		if ((string) $project['status'] !== self::S_PUBLISHED)
		{
			self::_exec('UPDATE nj_667_projects
				SET status = '.self::_q(self::S_PUBLISHED).', kind = \'public\', updated_at = now()
				WHERE id = '.$pid);
		}

		self::event($pid, 'publish', 'сборка #'.$id.' опубликована администратором', (int) $user_id);

		// Карточка для витрин рисуется сразу; сбой рисовалки публикацию не ломает.
		try
		{
			self::build_card_write($id);
		}
		catch (Exception $e)
		{
			// карточку дорисуем по требованию в build_card_path()
		}

		return ['ok' => TRUE, 'build_id' => $id, 'code' => (string) $project['code']];
	}

	/**
	 * Снять сборку с публикации. Граф остаётся опубликованным: это отдельное решение.
	 */
	public static function build_unpublish($build_id, $project_id = 0, $user_id = 0)
	{
		$build = self::build_get($build_id);

		if ( ! is_array($build) OR ($project_id > 0 AND (int) $build['project_id'] !== (int) $project_id))
		{
			return ['ok' => FALSE, 'error' => 'Сборка не найдена.'];
		}

		$id = (int) $build['id'];

		self::_exec('UPDATE nj_667_builds SET published_at = NULL, published_by = 0,
			status = CASE WHEN status = \'published\' THEN \'done\' ELSE status END
			WHERE id = '.$id);
		self::event((int) $build['project_id'], 'unpublish', 'сборка #'.$id.' снята с публикации; граф остался опубликованным', (int) $user_id);

		return ['ok' => TRUE, 'build_id' => $id];
	}

	/**
	 * ZIP сборки: «Детали на печать» (STL каждого узла), GLB изделия и CSV спецификации.
	 *
	 * Возвращает путь к временному файлу; его удаляет контроллер после отдачи.
	 * Файлы берём теми же путями, что и поштучные ссылки, — видимость та же.
	 */
	public static function build_zip(array $build, $csv)
	{
		if ( ! class_exists('ZipArchive'))
		{
			return ['ok' => FALSE, 'error' => 'На сервере нет расширения zip — архив не собрать.'];
		}

		$tmp = @tempnam(sys_get_temp_dir(), 'nj667zip');
		$zip = new ZipArchive;

		if ($tmp === FALSE OR $zip->open($tmp, ZipArchive::OVERWRITE) !== TRUE)
		{
			return ['ok' => FALSE, 'error' => 'Временный архив не открылся.'];
		}

		$added = 0;

		foreach (self::build_parts((int) $build['id']) as $row)
		{
			$path = self::build_file_path($row, 'stl');

			if ($path === '')
			{
				continue;
			}

			$title = trim((string) preg_replace('~[^\p{L}\p{N}]+~u', '-', (string) Arr::get($row, 'title', '')), '-');
			$name  = sprintf('%02d', (int) $row['part_index'] + 1).($title !== '' ? '-'.$title : '').'.stl';

			$zip->addFile($path, 'detali-na-pechat/'.$name);
			$added++;
		}

		$glb = self::build_file_path($build, 'glb');

		if ($glb !== '')
		{
			$zip->addFile($glb, 'model.glb');
			$added++;
		}

		if ((string) $csv !== '')
		{
			$zip->addFromString('spec.csv', (string) $csv);
			$added++;
		}

		$closed = $zip->close();

		if ($added === 0 OR ! $closed)
		{
			@unlink($tmp);

			return ['ok' => FALSE, 'error' => 'В сборке нет ни деталей, ни модели, ни спецификации.'];
		}

		return ['ok' => TRUE, 'path' => $tmp, 'files' => $added];
	}

	public static function build_get($build_id)
	{
		$build_id = (int) $build_id;

		if ($build_id <= 0)
		{
			return NULL;
		}

		return self::_one('SELECT * FROM nj_667_builds WHERE id = '.$build_id.' LIMIT 1');
	}

	public static function build_history($project_id, $limit = 8)
	{
		return (array) self::_all('SELECT id, status, type_key, created_at, done_at, stl_size, error
			FROM nj_667_builds WHERE project_id = '.(int) $project_id
			.' ORDER BY id DESC LIMIT '.max(1, min(50, (int) $limit)));
	}

	/**
	 * Снимок сборки: спецификация + .scad на диске. STL ещё нет — он придёт
	 * из воркера (или не придёт, и это будет видно в status/error).
	 */
	public static function build_create($project_id, $user_id, array $graph, array $opt, $note = '')
	{
		$geom = self::build_geometry($graph, $opt);

		if (empty($geom['ok']))
		{
			return ['ok' => FALSE, 'error' => (string) Arr::get($geom, 'error', 'Сборка не удалась.')];
		}

		$scad = self::build_scad($geom, $opt, $note);

		if ($scad === '')
		{
			$miss = Service_Nj667_Build::scad_missing($opt['type']);

			return ['ok' => FALSE, 'error' => 'Библиотека scad неполная: нет '
				.($miss ? implode(', ', $miss) : 'файлов')
				.'. Сборка в STL недоступна — проверьте каталог scad модуля.'];
		}

		$kb     = (int) Service_Nj667_Build::limit('scad_max_kb', 280);

		if ($kb > 0 AND strlen($scad) > $kb * 1024)
		{
			return ['ok' => FALSE, 'error' => 'Сборка слишком большая: .scad на '
				.round(strlen($scad) / 1024, 1).' КБ (предел '.$kb.' КБ). Укрупните конструкцию.'];
		}

		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Проект не найден.'];
		}

		$row = [
			'project_id'   => (int) $project_id,
			'user_id'      => (int) $user_id,
			'revision_id'  => (int) Arr::get($project, 'revision_id', 0),
			'status'       => 'spec',
			'render_kind'  => 'glb',
			'type_key'     => (string) $opt['type'],
			'graph_hash'   => Service_Nj667_Build::graph_hash($graph),
			'topo_hash'    => (string) Arr::get($project, 'topo_hash', self::topology_hash($graph)),
			'params_json'  => json_encode(self::build_params_pack($opt), JSON_UNESCAPED_UNICODE),
			'spec_json'    => json_encode(self::build_spec_pack($geom), JSON_UNESCAPED_UNICODE),
			'stats_json'   => json_encode(['counts' => $geom['counts'], 'span' => $geom['span']], JSON_UNESCAPED_UNICODE),
			'scad_path'    => '',
			'scad_size'    => strlen($scad),
			'error'        => '',
		];

		try
		{
			$build_id = (int) self::_insert('nj_667_builds', $row);
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'сборка не создана: :error', [':error' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Сборка не записана: '.$e->getMessage()];
		}

		$dir  = self::storage_dir((string) Arr::get($project, 'code'));
		$file = $dir !== '' ? $dir.'/build-'.$build_id.'.scad' : '';

		if ($file !== '' AND @file_put_contents($file, $scad) !== FALSE)
		{
			@chmod($file, 0640);

			self::_exec('UPDATE nj_667_builds SET scad_path = '.self::_q('nj667/'.(string) Arr::get($project, 'code')
				.'/build-'.$build_id.'.scad').' WHERE id = '.$build_id);
		}

		self::event($project_id, 'build', 'сборка #'.$build_id.': '.Service_Nj667_Build::type_title($opt['type'])
			.', '.(int) $geom['counts']['types'].' типов деталей', (int) $user_id);

		return ['ok' => TRUE, 'build_id' => $build_id, 'geom' => $geom, 'opt' => $opt,
			'spec' => self::build_spec_pack($geom), 'scad' => $scad];
	}

	/**
	 * Поставить сборку на рендер. Обычный путь сохраняет GLB; STL всего изделия
	 * разрешён только отдельным вызовом модератора. Детали к печати идут другой
	 * очередью и по-прежнему остаются STL.
	 */
	public static function build_queue($build_id, array $project, array $opt = [])
	{
		$build = self::build_get($build_id);

		if ($build === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Сборка не найдена.'];
		}

		$render_kind = strtolower(trim((string) Arr::get($opt, 'kind', 'glb')));

		if ( ! in_array($render_kind, ['glb', 'stl'], TRUE))
		{
			$render_kind = 'glb';
		}

		if ($render_kind === 'glb' AND ! self::build_glb_ready())
		{
			return ['ok' => FALSE, 'error' => 'GLB-сборка недоступна: примените миграцию NJ-667/010.'];
		}

		// Worker can start as soon as push() returns an id, so persist the
		// expected format first; otherwise an STL request may race the context
		// endpoint and be interpreted as the new-build default GLB.
		try
		{
			self::_exec('UPDATE nj_667_builds SET render_kind = '.self::_q($render_kind)
				.' WHERE id = '.(int) $build_id);
		}
		catch (Throwable $e)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось записать формат сборки. Примените миграцию NJ-667/014.'];
		}

		$queued = self::queue(self::CH_BUILD, $project, [
			'build_id' => (int) $build_id,
			'kind'     => $render_kind,
			'quality'  => (string) Arr::get($opt, 'quality', ''),
		]);

		if (empty($queued['ok']))
		{
			return ['ok' => FALSE, 'error' => (string) Arr::get($queued, 'error', 'Очередь не приняла задачу.')];
		}

		$job_id = (int) Arr::get($queued, 'job_id', 0);

		self::_exec('UPDATE nj_667_builds SET render_kind = '.self::_q($render_kind)
			.', status = '.self::_q('queued').', job_id = '.$job_id
			.', error = \'\' WHERE id = '.(int) $build_id);

		return ['ok' => TRUE, 'job_id' => $job_id, 'build_id' => (int) $build_id,
			'render_kind' => $render_kind];
	}

	/** Записать принятый запрос пользователя на файл сборки для последующего анализа. */
	public static function log_build_file_request($user_id, $project_id, $source_build_id,
		$build_id, $file_kind, $job_id)
	{
		$user_id         = (int) $user_id;
		$project_id      = (int) $project_id;
		$source_build_id = max(0, (int) $source_build_id);
		$build_id        = (int) $build_id;
		$file_kind       = strtolower(trim((string) $file_kind));
		$job_id          = max(0, (int) $job_id);

		if ($user_id <= 0 OR $project_id <= 0 OR $build_id <= 0
			OR ! in_array($file_kind, ['glb', 'stl', 'parts'], TRUE) OR $job_id <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Некорректные данные запроса файла.'];
		}

		try
		{
			$request_id = self::_insert('nj_667_build_file_requests', [
				'user_id'         => $user_id,
				'project_id'      => $project_id,
				'source_build_id' => $source_build_id,
				'build_id'        => $build_id,
				'file_kind'       => $file_kind,
				'job_id'          => $job_id,
			]);

			return ['ok' => TRUE, 'id' => $request_id];
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'запрос файла сборки не учтён: :e', [':e' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Запрос поставлен, но не записался в журнал.'];
		}
	}

	/** Чем занят воркер: контекст сборки (текст .scad) с диска проекта. */
	public static function build_scad_text($build_id)
	{
		$build = self::build_get($build_id);
		$path  = self::build_file_path($build);

		if ($path === '')
		{
			return ['ok' => FALSE, 'error' => 'Файл сборки потерян на диске — соберите заново.'];
		}

		$text = (string) @file_get_contents($path);
		$max  = (int) Service_Nj667_Build::limit('scad_max_kb', 280) * 1024;

		if ($max > 0 AND strlen($text) > $max)
		{
			return ['ok' => FALSE, 'error' => 'Сборка больше лимита рендера.'];
		}

		$params      = (array) json_decode((string) Arr::get($build, 'params_json', ''), TRUE);
		$render_kind = strtolower(trim((string) Arr::get($build, 'render_kind', 'legacy')));

		if ( ! in_array($render_kind, ['glb', 'stl', 'legacy'], TRUE))
		{
			$render_kind = 'legacy';
		}

		// Старые задачи сохраняют прежний протокол: GLB + optional STL и fallback.
		// Новые сборки явно требуют GLB либо STL и никогда не меняют тип сами.
		// Скобки обязательны: у «OR» приоритет НИЖЕ присваивания, и без них
		// $keep_stl получал только первый операнд (legacy-сборки теряли STL —
		// нашёл php_ternary_lint, 16.10.2026).
		$keep_stl = (($render_kind === 'stl')
			OR ($render_kind === 'legacy'
				AND ( ! array_key_exists('keep_stl', $params) OR ! empty($params['keep_stl']))));

		return ['ok' => TRUE, 'scad' => $text, 'build_id' => (int) $build_id,
			'name' => 'nj667-build-'.(int) $build_id,
			'render_kind' => $render_kind,
			'glb' => ($render_kind !== 'stl' AND self::build_glb_ready()),
			'keep_stl' => (bool) $keep_stl,
			'max_stl_mb' => (int) Service_Nj667_Build::limit('max_stl_mb', 24)];
	}

	/**
	 * Знает ли база про узлы к печати (миграция 009). Один запрос на страницу.
	 *
	 * Без таблицы пачка не соберётся НИКОГДА, и молчать об этом нельзя:
	 * человек жмёт «Собрать узлы» и видит тишину (вопрос владельца, 13.10.2026).
	 */
	public static function build_parts_ready()
	{
		static $ready = NULL;

		if ($ready !== NULL)
		{
			return $ready;
		}

		try
		{
			self::_all('SELECT 1 FROM nj_667_build_parts LIMIT 0');
			$ready = TRUE;
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'nj667: таблицы узлов нет — пачка недоступна: :e',
				[':e' => $e->getMessage()]);
			$ready = FALSE;
		}

		return $ready;
	}

	/** Столбцы окна одного запуска (миграция 013). */
	public static function build_parts_run_ready()
	{
		static $ready = NULL;

		if ($ready !== NULL)
		{
			return $ready;
		}

		try
		{
			self::_all('SELECT parts_run_id FROM nj_667_builds LIMIT 0');
			self::_all('SELECT parts_run_id FROM nj_667_build_parts LIMIT 0');
			$ready = TRUE;
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'nj667: окно запуска деталей недоступно — нужна миграция 013: :e',
				[':e' => $e->getMessage()]);
			$ready = FALSE;
		}

		return $ready;
	}

	/**
	 * Что не накатано в схеме БД. Страница показывает это словами: код обычно
	 * уезжает на сервер раньше миграций, и «нет цвета»/«нет деталей» тогда
	 * означают ровно это, а не сломанный конвейер.
	 */
	public static function build_schema_state()
	{
		return ['glb' => self::build_glb_ready(), 'parts' => self::build_parts_ready()];
	}

	/** Что умеет воркер: есть ли у него OpenSCAD (страница показывает честно). */
	public static function build_engine_hint()
	{
		return [
			'bin' => (string) (getenv('NJ667_OPENSCAD_BIN') ?: getenv('NJ570_OPENSCAD_BIN') ?: ''),
			'lib' => (string) (getenv('NJ667_OPENSCAD_LIB') ?: getenv('NJ570_OPENSCAD_LIB') ?: ''),
		];
	}

	/** Абсолютный путь файла сборки (только файлы каталога проекта). */
	public static function build_file_path(array $build = NULL, $kind = 'scad')
	{
		if ($build === NULL)
		{
			return '';
		}

		// Три руки: .scad (исходник), .stl (печать), .glb (браузер). Чужое
		// имя поля — не путь наружу: сюда приходят строки из адресной строки.
		$kind = in_array($kind, ['stl', 'glb'], TRUE) ? $kind : 'scad';
		$rel  = (string) Arr::get($build, $kind.'_path', '');

		if ($rel === '' OR strpos($rel, '..') !== FALSE OR ! defined('APPPATH'))
		{
			return '';
		}

		$path = realpath(APPPATH.'../storage/'.$rel);

		if ($path === FALSE OR ! is_file($path))
		{
			return '';
		}

		$root = realpath(APPPATH.'../storage');

		return ($root !== FALSE AND strpos($path, $root.DIRECTORY_SEPARATOR) === 0) ? $path : '';
	}

	/** Принять STL из воркера: проверить, положить, записать статистику. */
	public static function build_attach_stl($build_id, $bytes, $engine = '')
	{
		$build = self::build_get($build_id);

		if ($build === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Сборка не найдена.'];
		}

		$max_mb = (int) Service_Nj667_Build::limit('max_stl_mb', 24);
		$bytes  = (string) $bytes;

		if ($max_mb > 0 AND strlen($bytes) > $max_mb * 1024 * 1024)
		{
			self::build_mark($build_id, 'error', 'STL больше '.$max_mb.' МБ — не принимаем.');

			return ['ok' => FALSE, 'error' => 'STL больше '.$max_mb.' МБ.'];
		}

		$stats = Service_Nj667_Build::stl_stats($bytes);

		if (empty($stats['ok']))
		{
			$msg = (string) Arr::get($stats, 'error', 'STL не разобран.');

			self::build_mark($build_id, 'error', $msg.($engine !== '' ? ' ('.$engine.')' : ''));

			return ['ok' => FALSE, 'error' => $msg];
		}

		$project = self::project_by_id((int) Arr::get($build, 'project_id'));
		$dir     = self::storage_dir((string) Arr::get($project, 'code', ''));
		$path    = $dir !== '' ? $dir.'/build-'.(int) $build_id.'.stl' : '';

		if ($path === '' OR @file_put_contents($path, $bytes) === FALSE)
		{
			self::build_mark($build_id, 'error', 'Модель не записалась на диск.');

			return ['ok' => FALSE, 'error' => 'Модель не записалась на диск.'];
		}

		@chmod($path, 0640);

		$rel = 'nj667/'.(string) Arr::get($project, 'code').'/build-'.(int) $build_id.'.stl';

		// stats_json дополняем, а не заменяем: в нём уже counts/span со дня
		// создания, карточке проекта они нужны и после рендера.
		$stats_all = (array) json_decode((string) Arr::get($build, 'stats_json', ''), TRUE);
		$stats_all['stl'] = $stats;

		self::_exec('UPDATE nj_667_builds SET status = '.self::_done_status_sql()
			.', stl_path = '.self::_q($rel)
			.', stl_size = '.(int) strlen($bytes)
			.', stl_sha = '.self::_q((string) sha1($bytes))
			.', stats_json = '.self::_q(json_encode($stats_all, JSON_UNESCAPED_UNICODE))
			.', error = \'\'
			WHERE id = '.(int) $build_id);

		return ['ok' => TRUE, 'stats' => $stats, 'size' => strlen($bytes)];
	}

	/**
	 * Принять GLB из воркера: проверить, положить, записать в сборку.
	 *
	 * Порядок такой: сначала доезжает GLB (его человек увидит сразу), следом
	 * STL — если его просили хранить. Поэтому «готово» здесь ещё не значит
	 * «можно печатать»: печать смотрит на stl_path.
	 */
	public static function build_attach_glb($build_id, $bytes, $engine = '')
	{
		$build = self::build_get($build_id);

		if ($build === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Сборка не найдена.'];
		}

		$max_mb = (int) Service_Nj667_Build::limit('max_stl_mb', 24);
		$bytes  = (string) $bytes;

		if ($max_mb > 0 AND strlen($bytes) > $max_mb * 1024 * 1024)
		{
			self::build_mark($build_id, 'error', 'GLB больше '.$max_mb.' МБ — не принимаем.');

			return ['ok' => FALSE, 'error' => 'GLB больше '.$max_mb.' МБ.'];
		}

		$stats = Service_Nj667_Build::glb_stats($bytes);

		if (empty($stats['ok']))
		{
			$msg = (string) Arr::get($stats, 'error', 'GLB не разобран.');

			self::build_mark($build_id, 'error', $msg.($engine !== '' ? ' ('.$engine.')' : ''));

			return ['ok' => FALSE, 'error' => $msg];
		}

		$project = self::project_by_id((int) Arr::get($build, 'project_id'));
		$dir     = self::storage_dir((string) Arr::get($project, 'code', ''));
		$path    = $dir !== '' ? $dir.'/build-'.(int) $build_id.'.glb' : '';

		if ($path === '' OR @file_put_contents($path, $bytes) === FALSE)
		{
			self::build_mark($build_id, 'error', 'Модель не записалась на диск.');

			return ['ok' => FALSE, 'error' => 'Модель не записалась на диск.'];
		}

		@chmod($path, 0640);

		$rel = 'nj667/'.(string) Arr::get($project, 'code').'/build-'.(int) $build_id.'.glb';

		$stats_all = (array) json_decode((string) Arr::get($build, 'stats_json', ''), TRUE);
		$stats_all['glb'] = $stats;

		try
		{
			self::_exec('UPDATE nj_667_builds SET glb_path = '.self::_q($rel)
				.', glb_size = '.(int) strlen($bytes)
				.', stats_json = '.self::_q(json_encode($stats_all, JSON_UNESCAPED_UNICODE))
				.', status = '.self::_done_status_sql()
				.', error = \'\'
				WHERE id = '.(int) $build_id);
		}
		catch (Throwable $e)
		{
			// Миграция 010 могла упасть между проверкой и записью. Файл на
			// диске остаётся, но врать «готово» без строки в базе не будем:
			// сборка доживёт на STL, а человек увидит, почему glTF не показан.
			self::_log(Log::ERROR, 'nj667: glb сборки :id не отметился: :e',
				[':id' => (int) $build_id, ':e' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'GLB лёг на диск, но в базе не отметился — нет миграции 010.'];
		}

		return ['ok' => TRUE, 'stats' => $stats, 'size' => strlen($bytes)];
	}

	/**
	 * Знает ли база про GLB (миграция 010). Один запрос на страницу.
	 *
	 * Молча отказываемся от glTF, если колонки ещё нет: код на сервере
	 * бывает раньше миграции, а страница изделия не обязана из-за этого
	 * падать или показывать человеку ошибку там, где всё работает на STL.
	 */
	public static function build_glb_ready()
	{
		static $ready = NULL;

		if ($ready !== NULL)
		{
			return $ready;
		}

		try
		{
			self::_all('SELECT glb_path FROM nj_667_builds LIMIT 0');
			$ready = TRUE;
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'nj667: колонки glb_path нет — сборки едут без GLB: :e',
				[':e' => $e->getMessage()]);
			$ready = FALSE;
		}

		return $ready;
	}

	/**
	 * Переход сборки в другое состояние (queued/done/error) с текстом проблемы.
	 *
	 * Это единственная точка, где сборка получает свой итог: файлы (STL/GLB)
	 * приезжают отдельно, а «собрано» или «не собралось» говорит воркер именно
	 * отсюда через /graph3d/w/build_result. Поэтому и извещение владельцу уходит
	 * отсюда — ровно один раз на переход.
	 */
	public static function build_mark($build_id, $status, $error = '', $render_ms = 0)
	{
		$allow = ['spec', 'queued', 'done', 'error'];
		$status = (string) $status;

		if ( ! in_array($status, $allow, TRUE))
		{
			return ['ok' => FALSE, 'error' => 'Неизвестный статус сборки.'];
		}

		$before = self::build_get((int) $build_id);

		$set = 'status = '.($status === 'done' ? self::_done_status_sql() : self::_q($status))
			.', error = '.self::_q(UTF8::substr(trim((string) $error), 0, 480));

		if ($render_ms > 0)
		{
			$set .= ', render_ms = '.(int) $render_ms;
		}

		if (in_array($status, ['done', 'error'], TRUE))
		{
			$set .= ', done_at = now()';
		}

		self::_exec('UPDATE nj_667_builds SET '.$set.' WHERE id = '.(int) $build_id);

		// Извещаем только на первый переход: повторный вызов с тем же итогом
		// (воркер повторил запрос, файл приехал следом) — не повод для второго
		// письма.
		if (in_array($status, ['done', 'error'], TRUE)
			AND $before
			AND (string) Arr::get($before, 'status', '') !== $status)
		{
			self::_notify_build($before, $status, $error);
		}

		return ['ok' => TRUE];
	}

	/**
	 * Сообщить владельцу изделия, что сборка закончилась (nj597).
	 *
	 * Владелец (15.10.2026): «подумай, что добавить в уведомления из модулей:
	 * /trends и /graph3d добавь, настрой шаблоны». События объявлены в
	 * notify.events манифеста, текст письма берётся из шаблонов (шестой контракт),
	 * а без центра уведомлений вызов молча ничего не делает.
	 *
	 * @param array  $build  строка сборки ДО обновления (владелец и статус)
	 * @param string $status 'done' | 'error'
	 * @param string $error  текст ошибки воркера
	 */
	protected static function _notify_build(array $build, $status, $error = '')
	{
		$owner = (int) Arr::get($build, 'user_id', 0);

		// Гостевые проекты (auth => FALSE) живут без аккаунта: колокольчика у них
		// нет, а письмо слать некуда.
		if ($owner <= 0 OR ! class_exists('Service_Nj597_Notify'))
		{
			return;
		}

		$build_id = (int) Arr::get($build, 'id', 0);
		$project  = self::project_by_id((int) Arr::get($build, 'project_id', 0));
		$code     = (string) Arr::get((array) $project, 'code', '');
		$title    = trim((string) Arr::get((array) $project, 'title', ''));

		if ($title === '')
		{
			$title = 'Проект изделия';
		}

		$where = $code !== '' ? '/3d/'.$code : '/graph3d';

		try
		{
			if ($status === 'done')
			{
				Service_Nj597_Notify::emit('nj667.build.done', [
					'user_id'   => $owner,
					'title'     => 'Сборка готова: '.mb_substr($title, 0, 120),
					'body'      => 'Модель собрана — можно смотреть и печатать.',
					'href'      => $where,
					'dedup_key' => 'nj667.build.done:'.$build_id,
					// Своё событие: модель собирали по его заказу. Если он на
					// сайте и смотрит сборку — колокольчик молчит (ошибка
					// сборки ниже такого флага не имеет: сбой будим всегда).
					'self'      => TRUE,
					'data'      => [
						'title' => mb_substr($title, 0, 200),
						'code'  => $code,
						'href'  => $where,
					],
				]);
			}
			else
			{
				$why = mb_substr(trim((string) $error), 0, 300);

				Service_Nj597_Notify::emit('nj667.build.error', [
					'user_id'   => $owner,
					'title'     => 'Сборка не удалась: '.mb_substr($title, 0, 120),
					'body'      => ($why !== '' ? 'Причина: '.$why : 'Воркер не отдал модель.').' Можно повторить сборку.',
					'href'      => $where,
					'dedup_key' => 'nj667.build.error:'.$build_id,
					'data'      => [
						'title' => mb_substr($title, 0, 200),
						'error' => $why !== '' ? $why : 'воркер не отдал модель',
						'href'  => $where,
					],
				]);
			}
		}
		catch (Throwable $e)
		{
			self::_log(Log::WARNING, 'nj667: уведомление о сборке :id не ушло: :e',
				[':id' => $build_id, ':e' => $e->getMessage()]);
		}
	}

	/** Сборка устарела, если граф с тех пор двигали (сверка по каноническому хешу). */
	public static function build_stale(array $build, array $graph)
	{
		if ( ! $build)
		{
			return FALSE;
		}

		$saved = trim((string) Arr::get($build, 'graph_hash', ''));

		return $saved !== '' AND $saved !== Service_Nj667_Build::graph_hash($graph);
	}

	/** Что положить в spec_json (только показ; большие массивы — пересчёт). */
	public static function build_spec_pack(array $geom)
	{
		return Service_Nj667_Build::spec_pack($geom);
	}

	/** Что положить в params_json (чтобы повторить сборку слово в слово). */
	public static function build_params_pack(array $opt)
	{
		return Service_Nj667_Build::params_pack($opt);
	}

	// ------------------------------------------------------- узлы к печати
	//
	// «Готовые к печати узлы» — это не STL сборки: в цех несут деталь, а не
	// «всё сразу». На каждый уникальный тип — свой рендер (mode="single" в
	// generators/assembly.scad), свой файл и своя строка статуса: один тип мог
	// не собраться, когда остальные уже скачивают. Пачка живёт ОДНОЙ задачей
	// очереди — отдельная задача на каждый тип создала бы конкурирующие
	// рендеры и не дала бы одного сводного ответа о готовности.

	/** План узлов к печати (уникальные типы, копии, лимит пачки). */
	public static function build_parts_plan(array $geom, array $opt, $build_id = 0, $user_id = NULL)
	{
		if ($user_id === NULL AND (int) $build_id > 0)
		{
			$build = self::build_get((int) $build_id);
			$project = is_array($build)
				? self::project_by_id((int) Arr::get($build, 'project_id', 0))
				: NULL;
			$user_id = (int) Arr::get((array) $build, 'user_id', 0);

			if ($user_id <= 0)
			{
				$user_id = (int) Arr::get((array) $project, 'user_id', 0);
			}
		}

		return Service_Nj667_Build::parts_plan($geom, $opt,
			self::build_parts_run_limit((int) $user_id), self::build_parts_done($build_id));
	}

	/**
	 * Индексы типов, чей STL уже лежит на диске (status = done).
	 *
	 * Это окно пачки: лимит накладывают ПОСЛЕ вычитания готового, иначе
	 * следующие за `parts_max` типы не дожидаются очереди никогда.
	 */
	public static function build_parts_done($build_id)
	{
		$done = [];

		if ((int) $build_id <= 0)
		{
			return $done;
		}

		foreach (self::build_parts($build_id) as $row)
		{
			if ((string) Arr::get($row, 'status') === 'done'
				AND trim((string) Arr::get($row, 'stl_path', '')) !== '')
			{
				$done[(int) Arr::get($row, 'part_index')] = TRUE;
			}
		}

		return $done;
	}

	/** Номер текущего запуска деталей у сборки. */
	public static function build_parts_run_id($build_id)
	{
		try
		{
			return (int) self::_value('SELECT parts_run_id FROM nj_667_builds WHERE id = '
				.(int) $build_id.' LIMIT 1');
		}
		catch (Throwable $e)
		{
			return 0;
		}
	}

	/** Создать новый пользовательский запуск (автопродолжение номер не меняет). */
	protected static function build_parts_start_run($build_id)
	{
		$row = self::_one('UPDATE nj_667_builds SET parts_run_id = parts_run_id + 1
			WHERE id = '.(int) $build_id.' RETURNING parts_run_id');

		return (int) Arr::get((array) $row, 'parts_run_id', 0);
	}

	/** Строки, закреплённые за одним запуском. */
	protected static function build_parts_run_rows($build_id, $run_id)
	{
		return (array) self::_all('SELECT * FROM nj_667_build_parts WHERE build_id = '
			.(int) $build_id.' AND parts_run_id = '.(int) $run_id.' ORDER BY part_index ASC');
	}

	/**
	 * Строки узлов сборки: страница, «доделать», приёмка воркера.
	 *
	 * Пусто, а не исключение, если таблицы ещё нет: код уезжает на сервер
	 * раньше миграции, и из-за этого страница изделия не обязана падать —
	 * спецификация, STL сборки и чертежи узла живы и без пачки.
	 */
	public static function build_parts($build_id)
	{
		try
		{
			return (array) self::_all('SELECT * FROM nj_667_build_parts WHERE build_id = '
				.(int) $build_id.' ORDER BY part_index ASC');
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'nj667: узлы сборки :id не читаются: :e',
				[':id' => (int) $build_id, ':e' => $e->getMessage()]);

			return [];
		}
	}

	public static function build_part_get($build_id, $part)
	{
		return self::_one('SELECT * FROM nj_667_build_parts WHERE build_id = '.(int) $build_id
			.' AND part_index = '.(int) $part.' LIMIT 1');
	}

	/** Сколько узлов ещё не собрано (spec/error — то, ради чего встают в очередь). */
	public static function build_parts_pending($build_id, $run_id = NULL)
	{
		$where = '';

		if ($run_id !== NULL)
		{
			$where = ' AND parts_run_id = '.(int) $run_id;
		}

		return (int) self::_value('SELECT count(*) FROM nj_667_build_parts WHERE build_id = '
			.(int) $build_id.$where.' AND status IN (\'spec\', \'error\')');
	}

	/**
	 * .scad на каждый недостающий тип + строки в статусе queued.
	 *
	 * done не трогаем: файл на диске описывает ровно эту сборку, и выпрашивать
	 * минуты CPU на перерендер готового — значит отнимать их у следующего
	 * человека. Отказ по одному узлу не отменяет пачку: он становится строкой
	 * с ошибкой, и страница покажет его рядом с готовыми.
	 */
	public static function build_parts_prepare($build_id, $project_id, array $geom, array $opt,
		$run_id = 0, $continuation = FALSE)
	{
		$project = self::project_by_id((int) $project_id);
		$build   = self::build_get((int) $build_id);
		$user_id = (int) Arr::get((array) $build, 'user_id', 0);

		if ($user_id <= 0)
		{
			$user_id = (int) Arr::get((array) $project, 'user_id', 0);
		}

		if ($continuation)
		{
			$full = Service_Nj667_Build::parts_plan($geom, $opt, 0, self::build_parts_done($build_id));
			$run_parts = [];

			foreach (self::build_parts_run_rows($build_id, $run_id) as $run_row)
			{
				if ((string) Arr::get($run_row, 'status') !== 'done')
				{
					$run_parts[] = (int) Arr::get($run_row, 'part_index');
				}
			}

			$plan = Service_Nj667_Build::parts_run_window($full, $run_parts);
		}
		else
		{
			// Готовые типы вычитаются до пользовательского лимита запуска.
			$plan = self::build_parts_plan($geom, $opt, (int) $build_id, $user_id);
		}

		$have = [];
		$code = (string) Arr::get($project, 'code', '');
		$dir    = self::storage_dir($code);
		$kb     = (int) Service_Nj667_Build::limit('scad_max_kb', 280);
		$made   = [];
		$failed = [];

		foreach (self::build_parts($build_id) as $row)
		{
			if ((string) Arr::get($row, 'status') === 'done'
				AND trim((string) Arr::get($row, 'stl_path', '')) !== '')
			{
				$have[(int) Arr::get($row, 'part_index')] = TRUE;
			}
		}

		foreach ((array) $plan['parts'] as $p)
		{
			$part = (int) $p['part'];

			if (isset($have[$part]))
			{
				continue;
			}

			$rel  = '';
			$error = '';
			$scad = Service_Nj667_Build::scad_for_part($geom, $opt, $part,
				'узел #'.$part.' · '.$p['copies'].' шт. в изделии');

			if ($scad === '')
			{
				$error = 'библиотека scad неполная';
			}
			elseif ($kb > 0 AND strlen($scad) > $kb * 1024)
			{
				$error = '.scad '.round(strlen($scad) / 1024, 1).' КБ > лимита '.$kb.' КБ';
			}
			else
			{
				$file = $dir !== '' ? $dir.'/build-'.$build_id.'-part-'.$part.'.scad' : '';

				if ($file === '' OR @file_put_contents($file, $scad) === FALSE)
				{
					$error = '.scad не записался на диск';
				}
				else
				{
					@chmod($file, 0640);
					$rel = 'nj667/'.$code.'/build-'.$build_id.'-part-'.$part.'.scad';
				}
			}

			self::build_part_upsert($build_id, $p, $rel, $rel === '' ? 'error' : 'queued', $error, $run_id);

			if ($rel === '')
			{
				$failed[] = 'узел '.$part.': '.$error;
			}
			else
			{
				$made[] = $part;
			}
		}

		return ['ok' => ($made !== [] OR $failed === []), 'parts' => $made,
			'failed' => $failed, 'skipped' => (int) $plan['skipped'],
			'total' => (int) $plan['total']];
	}

	/** Строка узла заново (пересборка меняет всё, кроме готовых файлов). */
	protected static function build_part_upsert($build_id, array $p, $rel, $status, $error = '', $run_id = 0)
	{
		self::_exec('DELETE FROM nj_667_build_parts WHERE build_id = '.(int) $build_id
			.' AND part_index = '.(int) $p['part']);

		return self::_insert('nj_667_build_parts', [
			'build_id'   => (int) $build_id,
			'part_index' => (int) $p['part'],
			'type_key'   => (string) $p['type'],
			'title'      => UTF8::substr((string) $p['title'], 0, 80),
			'copies'     => (int) $p['copies'],
			'parts_run_id' => (int) $run_id,
			'status'     => (string) $status,
			'scad_path'  => (string) $rel,
			'stl_path'   => '',
			'stl_size'   => 0,
			'error'      => UTF8::substr(trim((string) $error), 0, 480),
		]);
	}

	/**
	 * Узлы → очередь. Возвращает и типы за пределами текущего окна, чтобы
	 * человек видел, что их можно запустить отдельно после завершения.
	 */
	public static function build_parts_render($build_id, array $project, array $graph, array $opt, $run_id = 0)
	{
		$build = self::build_get($build_id);

		if ($build === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Сборка не найдена — сначала пересчитайте изделие.'];
		}

		$request_user_id = (int) Arr::get($opt, 'request_user_id', 0);

		if ($request_user_id <= 0)
		{
			$request_user_id = (int) Arr::get($build, 'user_id', 0);
		}

		if ($request_user_id <= 0)
		{
			$request_user_id = (int) Arr::get($project, 'user_id', 0);
		}

		if ( ! self::build_parts_ready())
		{
			return ['ok' => FALSE, 'error' => 'В базе нет таблицы nj_667_build_parts (миграция 009): '
				.'накатите миграции — make migrate. Без неё детали к печати не появятся.'];
		}

		if ( ! self::build_parts_run_ready())
		{
			return ['ok' => FALSE, 'error' => 'Для ограничения деталей нужна миграция NJ-667/013 (make migrate).'];
		}

		if (self::build_stale((array) $build, $graph))
		{
			return ['ok' => FALSE,
				'error' => 'Граф с момента сборки двигали: пересчитайте изделие, потом узлы.'];
		}

		$continuation = (int) $run_id > 0;

		if ($continuation)
		{
			if ((int) $run_id !== self::build_parts_run_id($build_id))
			{
				return ['ok' => FALSE, 'error' => 'Запуск подготовки деталей устарел. Обновите страницу.'];
			}
		}
		else
		{
			$active_job = self::build_parts_job($build_id);

			if ($active_job > 0 AND self::job_live(self::job_state($active_job)))
			{
				return ['ok' => FALSE, 'error' => 'Подготовка деталей уже выполняется.'];
			}

			$run_id = self::build_parts_start_run($build_id);

			if ($run_id <= 0)
			{
				return ['ok' => FALSE, 'error' => 'Не удалось начать подготовку деталей.'];
			}
		}

		$geom = self::build_geometry($graph, $opt);

		if (empty($geom['ok']))
		{
			return ['ok' => FALSE, 'error' => (string) Arr::get($geom, 'error', 'Сборка не посчитана.')];
		}

		$prep = self::build_parts_prepare($build_id, (int) Arr::get($project, 'id', 0),
			$geom, $opt, (int) $run_id, $continuation);

		if ( ! $prep['parts'])
		{
			return ['ok' => (bool) $prep['ok'],
				'error' => $prep['ok'] ? 'Все узлы этого запуска уже обработаны.'
					: implode('; ', (array) $prep['failed']),
				'skipped' => (int) $prep['skipped'], 'total' => (int) $prep['total'],
				'run_id' => (int) $run_id];
		}

		$budget = (int) Service_Nj667_Build::limit('parts_budget_s', 3600);

		$queued = self::queue(self::CH_BUILD, $project, [
			'build_id'      => (int) $build_id,
			'kind'          => 'parts',
			'parts_run_id'  => (int) $run_id,
			'quality'       => (string) Arr::get($opt, 'quality', ''),
		], ['timeout_sec' => $budget > 0 ? $budget : self::timeout('build')]);

		if (empty($queued['ok']))
		{
			// Строки уже лежат в queued — вернуть их назад обязанность сайта:
			// воркер задачу не увидит, а «в очереди навсегда» ломает кнопку.
			self::_exec('UPDATE nj_667_build_parts SET status = \'spec\' WHERE build_id = '
				.(int) $build_id.' AND parts_run_id = '.(int) $run_id.' AND status = \'queued\'');

			return ['ok' => FALSE, 'run_id' => (int) $run_id,
				'error' => (string) Arr::get($queued, 'error', 'Очередь не приняла задачу.')];
		}

		$job_id = (int) Arr::get($queued, 'job_id', 0);

		self::_exec('UPDATE nj_667_build_parts SET job_id = '.$job_id.' WHERE build_id = '
			.(int) $build_id.' AND parts_run_id = '.(int) $run_id.' AND status = \'queued\'');
		// Только job_id: пачка узлов модель изделия не трогает, а status =
		// 'queued' на сборке означает «ждём модель». Воркер отвечает по пачке
		// отдельно (build_parts_result статус сборки не трогает нарочно), и
		// сборка повисала бы «Ждём модель из воркера…» вечно (жалоба владельца,
		// 13.10.2026: кнопка «не работает», потому что страница ждала модель).
		self::_exec('UPDATE nj_667_builds SET job_id = '.$job_id.' WHERE id = '.(int) $build_id);

		if ( ! $continuation AND $request_user_id > 0 AND $job_id > 0)
		{
			self::log_build_file_request($request_user_id, (int) Arr::get($project, 'id', 0),
				(int) $build_id, (int) $build_id, 'parts', $job_id);
		}

		self::event((int) Arr::get($project, 'id', 0), 'build',
			'узлы к печати сборки #'.$build_id.': '.count($prep['parts']).' шт.', $request_user_id);

		return ['ok' => TRUE, 'job_id' => $job_id, 'run_id' => (int) $run_id,
			'parts' => count($prep['parts']), 'skipped' => (int) $prep['skipped'],
			'total' => (int) $prep['total'], 'failed' => (array) $prep['failed']];
	}

	/** План пачки для воркера: только строки закреплённого пользовательского запуска. */
	public static function build_parts_context($build_id, $run_id = 0)
	{
		$run_id = (int) $run_id;

		if ($run_id <= 0)
		{
			$run_id = self::build_parts_run_id($build_id);
		}

		if ($run_id !== self::build_parts_run_id($build_id))
		{
			return ['ok' => FALSE, 'error' => 'Запуск подготовки деталей устарел.'];
		}

		$rows  = self::_all('SELECT part_index, title, copies FROM nj_667_build_parts
			WHERE build_id = '.(int) $build_id.' AND parts_run_id = '.$run_id
			.' AND status = \'queued\' ORDER BY part_index ASC');
		$parts = [];

		foreach ((array) $rows as $row)
		{
			$part = (int) Arr::get($row, 'part_index');

			$parts[] = [
				'part'   => $part,
				'name'   => 'nj667-'.(int) $build_id.'-part-'.$part,
				'title'  => (string) Arr::get($row, 'title'),
				'copies' => (int) Arr::get($row, 'copies'),
			];
		}

		if ( ! $parts)
		{
			return ['ok' => FALSE, 'error' => 'Недостающих узлов нет: сборка уже собрана целиком.'];
		}

		return ['ok' => TRUE, 'kind' => 'parts', 'build_id' => (int) $build_id,
			'parts_run_id' => $run_id, 'parts' => $parts,
			'budget_s'     => (int) Service_Nj667_Build::limit('parts_budget_s', 3600),
			'max_stl_mb'   => (int) Service_Nj667_Build::limit('max_stl_mb', 24)];
	}

	/** .scad одного узла с диска (ровно то, что видел OpenSCAD на прошлом заходе). */
	public static function build_part_scad_text($build_id, $part, $run_id = NULL)
	{
		$row = self::build_part_get($build_id, $part);

		if ( ! is_array($row))
		{
			return ['ok' => FALSE, 'error' => 'Такого узла у сборки нет.'];
		}

		if ($run_id !== NULL AND (int) Arr::get($row, 'parts_run_id', 0) !== (int) $run_id)
		{
			return ['ok' => FALSE, 'error' => 'Узел принадлежит другому запуску подготовки.'];
		}

		$path = self::build_file_path($row, 'scad');

		if ($path === '')
		{
			return ['ok' => FALSE, 'error' => 'Файл сборки потерян на диске — пересоберите изделие.'];
		}

		$text = (string) @file_get_contents($path);
		$max  = (int) Service_Nj667_Build::limit('scad_max_kb', 280) * 1024;

		if ($max > 0 AND strlen($text) > $max)
		{
			return ['ok' => FALSE, 'error' => 'Узел больше лимита рендера.'];
		}

		return ['ok' => TRUE, 'scad' => $text, 'build_id' => (int) $build_id,
			'part' => (int) $part, 'name' => 'nj667-'.(int) $build_id.'-part-'.(int) $part,
			'max_stl_mb' => (int) Service_Nj667_Build::limit('max_stl_mb', 24)];
	}

	/** Принять STL узла (проверка байтов — та же, что у сборки: доверия нет). */
	/**
	 * Превью узла (PNG 128 px) рядом с его STL. Файл, не строка: картинка нужна
	 * только для таблицы, а статус узла ведёт STL. Лишний PNG не ломает итог.
	 */
	public static function build_part_attach_preview($build_id, $part, $bytes, $run_id = NULL)
	{
		$row   = self::build_part_get($build_id, $part);
		$bytes = (string) $bytes;

		if ( ! is_array($row))
		{
			return ['ok' => FALSE, 'error' => 'Такого узла у сборки нет.'];
		}

		if ($run_id !== NULL AND (int) Arr::get($row, 'parts_run_id', 0) !== (int) $run_id)
		{
			return ['ok' => FALSE, 'error' => 'Превью принадлежит другому запуску подготовки.'];
		}

		// Превью — маленький PNG. Всё иное (модель, мусор, раздутый файл) не принимаем.
		if (strlen($bytes) > 256 * 1024 OR substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n")
		{
			return ['ok' => FALSE, 'error' => 'Превью не PNG или слишком большое.'];
		}

		$path = self::build_part_preview_file($build_id, $part);

		if ($path === '' OR @file_put_contents($path, $bytes) === FALSE)
		{
			return ['ok' => FALSE, 'error' => 'Превью не записалось на диск.'];
		}

		@chmod($path, 0640);

		return ['ok' => TRUE, 'size' => strlen($bytes)];
	}

	/** Опубликованная сборка проекта (строка nj_667_builds) или NULL. */
	public static function published_build_of($project_id)
	{
		$row = self::_one('SELECT * FROM nj_667_builds
			WHERE project_id = '.(int) $project_id.' AND published_at IS NOT NULL
			ORDER BY published_at DESC LIMIT 1');

		return $row ?: NULL;
	}

	/** Карточка сборки для витрин: 160 px, сглаживание через ×4 (SS). */
	const CARD_PX = 160;
	const CARD_SS = 4;

	/** Файл карточки сборки в папке проекта; '' — папки нет. */
	protected static function build_card_file($build_id, $code)
	{
		$dir = self::storage_dir((string) $code);

		return $dir === '' ? '' : $dir.'/build-'.(int) $build_id.'-card-160.png';
	}

	/**
	 * Путь PNG-карточки опубликованной сборки. Файл рисуем при публикации; если его
	 * нет (сборку опубликовали раньше) — рисуем сейчас. '' — карточки нет.
	 */
	public static function build_card_path($build_id)
	{
		$build = self::build_get($build_id);

		if ( ! is_array($build) OR (string) Arr::get($build, 'published_at', '') === '')
		{
			return '';
		}

		$project = self::project_by_id((int) $build['project_id']);
		$file    = $project === NULL ? '' : self::build_card_file((int) $build['id'], (string) $project['code']);

		if ($file === '')
		{
			return '';
		}

		if (is_file($file))
		{
			return $file;
		}

		return self::build_card_write((int) $build['id']) ? $file : '';
	}

	/** Рисует карточку сборки на диск (атомарно). TRUE — файл записан. */
	public static function build_card_write($build_id)
	{
		$build   = self::build_get($build_id);
		$project = is_array($build) ? self::project_by_id((int) $build['project_id']) : NULL;

		if ($project === NULL)
		{
			return FALSE;
		}

		$file = self::build_card_file((int) $build['id'], (string) $project['code']);

		if ($file === '')
		{
			return FALSE;
		}

		$png = self::build_card_png($build);

		if ($png === '')
		{
			return FALSE;
		}

		$tmp = $file.'.tmp';

		if (file_put_contents($tmp, $png) === FALSE)
		{
			return FALSE;
		}

		@chmod($tmp, 0640);

		return @rename($tmp, $file);
	}

	/**
	 * Карточка сборки — изометрия GLB, который показывается пользователям.
	 * STL изделия не является запасным источником для витрин. '' — GLB нет
	 * или GD не умеет рисовать.
	 */
	public static function build_card_png(array $build)
	{
		$tris = self::build_mesh_tris($build);

		return $tris === [] ? '' : self::mesh_png($tris);
	}

	/**
	 * Треугольники сборки: [[ [x,y,z]×3, [r,g,b] ], …] в миллиметрах сборки.
	 * Цвет — sRGB 0–255. Пусто — модели нет или она не читается.
	 */
	protected static function build_mesh_tris(array $build)
	{
		$glb = self::build_file_path($build, 'glb');

		return $glb === '' ? [] : self::glb_tris((string) file_get_contents($glb));
	}

	/** Предел на карточку: больше треугольников — картинка не стоит памяти и времени. */
	const CARD_MAX_TRIS = 250000;

	/**
	 * Читает бинарный GLB (glTF 2.0): меш → примитивы → треугольники в мировых
	 * координатах узлов. Координаты не переворачиваем: воркер пишет их как есть
	 * (ось Z — вверх, как в OpenSCAD). Чужой или битый файл — [].
	 */
	protected static function glb_tris($data)
	{
		if (strlen($data) < 28 OR substr($data, 0, 4) !== 'glTF' OR substr($data, 16, 4) !== 'JSON')
		{
			return [];
		}

		$jlen = unpack('V', substr($data, 12, 4))[1];
		$json = json_decode((string) substr($data, 20, $jlen), TRUE);

		if ( ! is_array($json))
		{
			return [];
		}

		$bin_at = 20 + $jlen;
		$bin    = '';

		if (strlen($data) >= $bin_at + 8)
		{
			$blen = unpack('V', substr($data, $bin_at, 4))[1];
			$bin  = (string) substr($data, $bin_at + 8, $blen);
		}

		$scene = Arr::get((array) Arr::get($json, 'scenes', []), (int) Arr::get($json, 'scene', 0));
		$roots = is_array($scene) ? (array) Arr::get($scene, 'nodes', []) : [];

		$identity = [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1];
		$tris     = [];
		$stack    = [];

		foreach ($roots as $root)
		{
			$stack[] = [(int) $root, $identity];
		}

		$guard = 0;

		while ($stack AND $guard++ < 10000)
		{
			[$ni, $parent] = array_pop($stack);
			$node = Arr::get((array) Arr::get($json, 'nodes', []), $ni);

			if ( ! is_array($node))
			{
				continue;
			}

			$world = self::mat_mul($parent, self::glb_local($node));

			foreach ((array) Arr::get($node, 'children', []) as $child)
			{
				$stack[] = [(int) $child, $world];
			}

			if ( ! isset($node['mesh']))
			{
				continue;
			}

			$mesh = Arr::get((array) Arr::get($json, 'meshes', []), (int) $node['mesh']);

			foreach ((array) Arr::get((array) $mesh, 'primitives', []) as $prim)
			{
				$attrs = (array) Arr::get($prim, 'attributes', []);
				$pos   = self::glb_accessor($json, $bin, (int) Arr::get($attrs, 'POSITION', -1), 3);

				if ($pos === [])
				{
					continue;
				}

				$verts = [];

				for ($i = 0, $n = count($pos); $i + 2 < $n; $i += 3)
				{
					$verts[] = self::mat_point($world, $pos[$i], $pos[$i + 1], $pos[$i + 2]);
				}

				$idx = isset($prim['indices'])
					? self::glb_accessor($json, $bin, (int) $prim['indices'], 1)
					: range(0, count($verts) - 1);

				$mat = Arr::get((array) Arr::get($json, 'materials', []), (int) Arr::get($prim, 'material', -1));
				$pbr = (array) Arr::get((array) $mat, 'pbrMetallicRoughness', []);
				$rgb = self::glb_rgb(Arr::get($pbr, 'baseColorFactor'));

				if (count($tris) + intdiv(count($idx), 3) > self::CARD_MAX_TRIS)
				{
					return [];
				}

				for ($i = 0, $n = count($idx); $i + 2 < $n; $i += 3)
				{
					$a = (int) $idx[$i];
					$b = (int) $idx[$i + 1];
					$c = (int) $idx[$i + 2];

					if (isset($verts[$a], $verts[$b], $verts[$c]))
					{
						$tris[] = [[$verts[$a], $verts[$b], $verts[$c]], $rgb];
					}
				}
			}
		}

		return $tris;
	}

	/** Значения accessor'а (плоским списком): $size компонент на элемент. */
	protected static function glb_accessor(array $json, $bin, $index, $size)
	{
		$acc = Arr::get((array) Arr::get($json, 'accessors', []), $index);
		$bv  = Arr::get((array) Arr::get($json, 'bufferViews', []), (int) Arr::get((array) $acc, 'bufferView', -1));

		if ( ! is_array($acc) OR ! is_array($bv) OR (int) Arr::get($bv, 'buffer', 0) !== 0)
		{
			return [];
		}

		// componentType → формат unpack (всё little-endian, как в GLB).
		$fmt = ['5126' => ['g', 4], '5125' => ['V', 4], '5123' => ['v', 2], '5121' => ['C', 1]];
		$type = Arr::get($fmt, (string) Arr::get($acc, 'componentType', 0));

		if ($type === NULL)
		{
			return [];
		}

		$count  = (int) Arr::get($acc, 'count', 0);
		$csize  = $type[1];
		$stride = (int) Arr::get($bv, 'byteStride', 0) ?: $csize * $size;
		$base   = (int) Arr::get($bv, 'byteOffset', 0) + (int) Arr::get($acc, 'byteOffset', 0);
		$out    = [];

		if ($count <= 0 OR $base + ($count - 1) * $stride + $csize * $size > strlen($bin))
		{
			return [];
		}

		for ($i = 0; $i < $count; $i++)
		{
			$chunk = substr($bin, $base + $i * $stride, $csize * $size);
			$vals  = array_values(unpack($type[0].'*', $chunk));

			foreach ($vals as $v)
			{
				$out[] = (float) $v;
			}
		}

		return $out;
	}

	/** Локальная матрица узла (столбцами, как в glTF): matrix или TRS. */
	protected static function glb_local(array $node)
	{
		if (isset($node['matrix']) AND count($node['matrix']) === 16)
		{
			return array_map('floatval', $node['matrix']);
		}

		$t = array_map('floatval', (array) Arr::get($node, 'translation', [0, 0, 0]));
		$r = array_map('floatval', (array) Arr::get($node, 'rotation', [0, 0, 0, 1]));
		$s = array_map('floatval', (array) Arr::get($node, 'scale', [1, 1, 1]));

		[$x, $y, $z, $w] = count($r) === 4 ? $r : [0, 0, 0, 1];

		$m = [
			(1 - 2 * ($y * $y + $z * $z)) * $s[0], 2 * ($x * $y + $w * $z) * $s[0], 2 * ($x * $z - $w * $y) * $s[0], 0,
			2 * ($x * $y - $w * $z) * $s[1], (1 - 2 * ($x * $x + $z * $z)) * $s[1], 2 * ($y * $z + $w * $x) * $s[1], 0,
			2 * ($x * $z + $w * $y) * $s[2], 2 * ($y * $z - $w * $x) * $s[2], (1 - 2 * ($x * $x + $y * $y)) * $s[2], 0,
			$t[0] ?? 0, $t[1] ?? 0, $t[2] ?? 0, 1,
		];

		return $m;
	}

	/** Произведение матриц 4×4 по столбцам: a · b. */
	protected static function mat_mul(array $a, array $b)
	{
		$out = [];

		for ($c = 0; $c < 4; $c++)
		{
			for ($r = 0; $r < 4; $r++)
			{
				$sum = 0.0;

				for ($k = 0; $k < 4; $k++)
				{
					$sum += $a[$k * 4 + $r] * $b[$c * 4 + $k];
				}

				$out[$c * 4 + $r] = $sum;
			}
		}

		return $out;
	}

	protected static function mat_point(array $m, $x, $y, $z)
	{
		return [
			$m[0] * $x + $m[4] * $y + $m[8] * $z + $m[12],
			$m[1] * $x + $m[5] * $y + $m[9] * $z + $m[13],
			$m[2] * $x + $m[6] * $y + $m[10] * $z + $m[14],
		];
	}

	/** Цвет glTF — линейный; в картинку — sRGB 0–255. По умолчанию серый. */
	protected static function glb_rgb($factor)
	{
		$f = is_array($factor) ? array_map('floatval', $factor) : [0.8, 0.8, 0.8];
		$out = [];

		foreach ([0, 1, 2] as $i)
		{
			$c = max(0.0, min(1.0, (float) ($f[$i] ?? 0.8)));
			$s = $c <= 0.0031308 ? 12.92 * $c : 1.055 * pow($c, 1 / 2.4) - 0.055;
			$out[] = (int) round(255 * max(0.0, min(1.0, $s)));
		}

		return $out;
	}

	/** Двоичный или текстовый STL: треугольники, один серый цвет. */
	protected static function stl_tris($data)
	{
		$grey = [150, 160, 170];
		$tris = [];

		if (strlen($data) >= 84)
		{
			$count = unpack('V', substr($data, 80, 4))[1];

			if (84 + 50 * $count === strlen($data))
			{
				for ($i = 0; $i < $count; $i++)
				{
					$f = array_values(unpack('g*', substr($data, 84 + 50 * $i + 12, 36)));
					$tris[] = [[[$f[0], $f[1], $f[2]], [$f[3], $f[4], $f[5]], [$f[6], $f[7], $f[8]]], $grey];

					if (count($tris) > self::CARD_MAX_TRIS)
					{
						return [];
					}
				}

				return $tris;
			}
		}

		if (preg_match_all('~vertex\s+(\S+)\s+(\S+)\s+(\S+)~i', $data, $m, PREG_SET_ORDER))
		{
			$v = [];

			foreach ($m as $p)
			{
				$v[] = [(float) $p[1], (float) $p[2], (float) $p[3]];

				if (count($v) === 3)
				{
					$tris[] = [$v, $grey];
					$v = [];
				}
			}
		}

		return count($tris) > self::CARD_MAX_TRIS ? [] : $tris;
	}

	/**
	 * Рисует треугольники изометрией (yaw 45°, наклон arctan(1/√2), Z вверх) в
	 * 128 px с прозрачным фоном. Сглаживание ×4 (SS). Художник: грани по глубине
	 * от дальних к ближним; освещение — как в превью детали воркера.
	 */
	protected static function mesh_png(array $tris)
	{
		if ( ! function_exists('imagecreatetruecolor') OR ! function_exists('imagepng'))
		{
			return '';
		}

		$yaw   = M_PI / 4;
		$pitch = atan(1 / sqrt(2));

		// Экран: x вправо, y вверх; глубина — больше значит ближе к зрителю.
		$iso = function (array $p) use ($yaw, $pitch) {
			$x1 = $p[0] * cos($yaw) - $p[1] * sin($yaw);
			$y1 = $p[0] * sin($yaw) + $p[1] * cos($yaw);
			$y2 = $y1 * cos($pitch) - $p[2] * sin($pitch);
			$z2 = $y1 * sin($pitch) + $p[2] * cos($pitch);

			return [$x1, -$y2, $z2];
		};

		$light = [-0.35, 0.45, 0.82];
		$ln    = sqrt(array_sum(array_map(function ($c) { return $c * $c; }, $light)));
		$light = array_map(function ($c) use ($ln) { return $c / $ln; }, $light);

		$recs = [];
		$minx = $miny = INF;
		$maxx = $maxy = -INF;

		foreach ($tris as $t)
		{
			$p = [$iso($t[0][0]), $iso($t[0][1]), $iso($t[0][2])];

			$e1 = [$p[1][0] - $p[0][0], $p[1][1] - $p[0][1], $p[1][2] - $p[0][2]];
			$e2 = [$p[2][0] - $p[0][0], $p[2][1] - $p[0][1], $p[2][2] - $p[0][2]];
			$n  = [$e1[1] * $e2[2] - $e1[2] * $e2[1], $e1[2] * $e2[0] - $e1[0] * $e2[2], $e1[0] * $e2[1] - $e1[1] * $e2[0]];
			$nl = sqrt($n[0] * $n[0] + $n[1] * $n[1] + $n[2] * $n[2]);

			if ($nl < 1e-12)
			{
				continue;
			}

			// Модуль: нормали в STL часто врут по знаку.
			$k     = abs($n[0] * $light[0] + $n[1] * $light[1] + $n[2] * $light[2]) / $nl;
			$shade = 0.6 + 0.4 * min(1.0, $k);
			$rgb   = $t[1];
			$col   = ((int) round($rgb[0] * $shade) << 16) | ((int) round($rgb[1] * $shade) << 8) | (int) round($rgb[2] * $shade);

			$d = ($p[0][2] + $p[1][2] + $p[2][2]) / 3;

			foreach ($p as $q)
			{
				$minx = min($minx, $q[0]); $maxx = max($maxx, $q[0]);
				$miny = min($miny, $q[1]); $maxy = max($maxy, $q[1]);
			}

			$recs[] = [$d, $p[0][0], $p[0][1], $p[1][0], $p[1][1], $p[2][0], $p[2][1], $col];
		}

		if ($recs === [])
		{
			return '';
		}

		usort($recs, function ($x, $y) { return $x[0] <=> $y[0]; });

		$S    = self::CARD_PX * self::CARD_SS;
		$pad  = 0.10 * $S;
		$span = max($maxx - $minx, $maxy - $miny, 1e-6);
		$k    = ($S - 2 * $pad) / $span;
		$cx   = ($minx + $maxx) / 2;
		$cy   = ($miny + $maxy) / 2;

		$im = imagecreatetruecolor($S, $S);
		imagesavealpha($im, TRUE);
		imagealphablending($im, FALSE);
		imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
		imagealphablending($im, TRUE);

		foreach ($recs as $r)
		{
			$pts = [];

			for ($i = 1; $i <= 5; $i += 2)
			{
				$pts[] = $S / 2 + ($r[$i] - $cx) * $k;
				$pts[] = $S / 2 - ($r[$i + 1] - $cy) * $k;
			}

			imagefilledpolygon($im, $pts, $r[7]);
		}

		$out = imagecreatetruecolor(self::CARD_PX, self::CARD_PX);
		imagesavealpha($out, TRUE);
		imagealphablending($out, FALSE);
		imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
		imagecopyresampled($out, $im, 0, 0, 0, 0, self::CARD_PX, self::CARD_PX, $S, $S);

		ob_start();
		imagepng($out);

		return (string) ob_get_clean();
	}

	/**
	 * Изометрия графа в PNG 128×128: палочки и узлы, цвет по типу узла, прозрачный
	 * фон. Поворот 45° вокруг вертикали и наклон на arctan(1/√2) — настоящая
	 * изометрия. '' — рисовать нечего или нет GD.
	 */
	public static function card_png(array $graph)
	{
		if ( ! function_exists('imagecreatetruecolor') OR ! function_exists('imagepng'))
		{
			return '';
		}

		$nodes = [];

		foreach ((array) Arr::get($graph, 'nodes', []) as $n)
		{
			if (is_array($n) AND isset($n['id']))
			{
				$nodes[(string) $n['id']] = [
					'p'    => [(float) Arr::get($n, 'x', 0), (float) Arr::get($n, 'y', 0), (float) Arr::get($n, 'z', 0)],
					'type' => (string) Arr::get($n, 'type', ''),
				];
			}
		}

		if ($nodes === [])
		{
			return '';
		}

		$yaw   = M_PI / 4;
		$pitch = atan(1 / sqrt(2));

		// Экран: x вправо, y вверх; глубина z2 — больше значит ближе к зрителю.
		$iso = function (array $p) use ($yaw, $pitch) {
			$x1 = $p[0] * cos($yaw) - $p[1] * sin($yaw);
			$y1 = $p[0] * sin($yaw) + $p[1] * cos($yaw);
			$y2 = $y1 * cos($pitch) - $p[2] * sin($pitch);
			$z2 = $y1 * sin($pitch) + $p[2] * cos($pitch);

			return [$x1, -$y2, $z2];
		};

		$proj = [];
		$minx = $miny = INF;
		$maxx = $maxy = -INF;

		foreach ($nodes as $id => $n)
		{
			$q = $iso($n['p']);
			$proj[$id] = $q;
			$minx = min($minx, $q[0]); $maxx = max($maxx, $q[0]);
			$miny = min($miny, $q[1]); $maxy = max($maxy, $q[1]);
		}

		$S    = self::CARD_PX * self::CARD_SS;
		$pad  = 0.10 * $S;
		$span = max($maxx - $minx, $maxy - $miny, 1e-6);
		$k    = ($S - 2 * $pad) / $span;
		$cx   = ($minx + $maxx) / 2;
		$cy   = ($miny + $maxy) / 2;

		$to = function (array $q) use ($k, $cx, $cy, $S) {
			return [$S / 2 + ($q[0] - $cx) * $k, $S / 2 - ($q[1] - $cy) * $k];
		};

		$r = max(7.0, min(20.0, 52 / sqrt(count($nodes)))) * (self::CARD_SS / 2);
		$w = $r * 0.45;

		$items = [];

		foreach ($nodes as $id => $n)
		{
			$items[] = ['z' => $proj[$id][2], 'node' => TRUE, 'pt' => $to($proj[$id]), 'type' => $n['type']];
		}

		foreach ((array) Arr::get($graph, 'edges', []) as $e)
		{
			$a = (string) Arr::get($e, 'source', '');
			$b = (string) Arr::get($e, 'target', '');

			if ( ! isset($proj[$a], $proj[$b]) OR $a === $b)
			{
				continue;
			}

			$items[] = [
				'z'    => ($proj[$a][2] + $proj[$b][2]) / 2,
				'node' => FALSE,
				'a'    => $to($proj[$a]),
				'b'    => $to($proj[$b]),
			];
		}

		usort($items, function ($x, $y) {
			return $x['z'] <=> $y['z'];
		});

		$im = imagecreatetruecolor($S, $S);
		imagesavealpha($im, TRUE);
		imagealphablending($im, FALSE);
		imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
		imagealphablending($im, TRUE);

		$hex = function ($h, $f = 1.0) use ($im) {
			$c = [hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2))];
			$c = array_map(function ($v) use ($f) { return (int) max(0, min(255, round($v * $f))); }, $c);

			return imagecolorallocate($im, $c[0], $c[1], $c[2]);
		};

		$node_colors = ['connector' => '#4F46E5', 'endpoint' => '#0EA5E9'];
		$ink         = imagecolorallocatealpha($im, 15, 23, 42, 45);
		$amber       = '#F59E0B';

		foreach ($items as $it)
		{
			if ($it['node'])
			{
				[$cx2, $cy2] = $it['pt'];
				$base = $node_colors[$it['type']] ?? '#64748B';
				imagefilledellipse($im, (int) $cx2, (int) $cy2, (int) (2 * $r + 4), (int) (2 * $r + 4), $ink);
				imagefilledellipse($im, (int) $cx2, (int) $cy2, (int) (2 * $r), (int) (2 * $r), $hex($base, 0.55));
				imagefilledellipse($im, (int) ($cx2 - 0.12 * $r), (int) ($cy2 - 0.12 * $r), (int) (1.7 * $r), (int) (1.7 * $r), $hex($base, 0.9));
				imagefilledellipse($im, (int) ($cx2 - 0.35 * $r), (int) ($cy2 - 0.35 * $r), (int) (0.7 * $r), (int) (0.7 * $r), $hex($base, 1.45));
				continue;
			}

			[$ax, $ay] = $it['a'];
			[$bx, $by] = $it['b'];
			$dx = $bx - $ax;
			$dy = $by - $ay;
			$len = hypot($dx, $dy);

			if ($len < 1e-3)
			{
				continue;
			}

			// Нормаль к палочке; «свет» сверху-слева: блик — на стороне с меньшим y.
			$nx = -$dy / $len;
			$ny = $dx / $len;
			if ($ny > 0) { $nx = -$nx; $ny = -$ny; }

			$quad = function ($hw) use ($ax, $ay, $bx, $by, $nx, $ny) {
				return [$ax + $nx * $hw, $ay + $ny * $hw, $bx + $nx * $hw, $by + $ny * $hw,
					$bx - $nx * $hw, $by - $ny * $hw, $ax - $nx * $hw, $ay - $ny * $hw];
			};

			imagefilledpolygon($im, $quad($w + 2), $ink);
			imagefilledpolygon($im, $quad($w), $hex($amber, 0.8));
			imagefilledpolygon($im, $quad($w * 0.55), $hex($amber));
			imagesetthickness($im, max(1, (int) round($w * 0.35)));
			imageline($im, (int) round($ax + $nx * $w * 0.45), (int) round($ay + $ny * $w * 0.45),
				(int) round($bx + $nx * $w * 0.45), (int) round($by + $ny * $w * 0.45), $hex('#FDE68A'));
			imagesetthickness($im, 1);
		}

		$out = imagecreatetruecolor(self::CARD_PX, self::CARD_PX);
		imagesavealpha($out, TRUE);
		imagealphablending($out, FALSE);
		imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
		imagecopyresampled($out, $im, 0, 0, 0, 0, self::CARD_PX, self::CARD_PX, $S, $S);

		ob_start();
		imagepng($out, NULL, 9);
		$png = (string) ob_get_clean();

		imagedestroy($im);
		imagedestroy($out);

		return $png;
	}

	/** Три (по умолчанию) последние опубликованные сборки — витрина корня модуля. */
	public static function latest_published_builds($limit = 3)
	{
		$limit = min(12, max(1, (int) $limit));

		return self::_all('SELECT b.id AS build_id, b.published_at, p.id AS project_id,
			p.code, p.title, p.summary
			FROM nj_667_builds b
			JOIN nj_667_projects p ON p.id = b.project_id
			WHERE b.published_at IS NOT NULL AND p.status = '.self::_q(self::S_PUBLISHED).'
				AND p.deleted_at IS NULL
			ORDER BY b.published_at DESC, b.id DESC LIMIT '.$limit);
	}

	/** Путь превью узла на диске; '' — если узла или файла нет. */
	public static function build_part_preview_path($build_id, $part)
	{
		if ( ! is_array(self::build_part_get($build_id, $part)))
		{
			return '';
		}

		$path = self::build_part_preview_file($build_id, $part);

		return ($path !== '' AND is_file($path)) ? $path : '';
	}

	/** Номера узлов, у которых превью уже лежит на диске (одна выборка и одна папка). */
	public static function build_parts_previews($build_id)
	{
		$build = self::build_get($build_id);

		if ( ! is_array($build))
		{
			return [];
		}

		$project = self::project_by_id((int) Arr::get($build, 'project_id', 0));
		$dir     = self::storage_dir((string) Arr::get($project, 'code', ''));
		$out     = [];

		if ($dir === '')
		{
			return [];
		}

		foreach (self::build_parts((int) $build_id) as $row)
		{
			$i = (int) Arr::get($row, 'part_index');

			if (is_file($dir.'/build-'.(int) $build_id.'-part-'.$i.'.png'))
			{
				$out[] = $i;
			}
		}

		return $out;
	}

	/** Путь файла превью: в папке проекта, рядом с STL того же узла. */
	protected static function build_part_preview_file($build_id, $part)
	{
		$build   = self::build_get($build_id);
		$project = self::project_by_id((int) Arr::get($build, 'project_id', 0));
		$dir     = self::storage_dir((string) Arr::get($project, 'code', ''));

		if ($dir === '')
		{
			return '';
		}

		return $dir.'/build-'.(int) $build_id.'-part-'.(int) $part.'.png';
	}

	public static function build_part_attach_stl($build_id, $part, $bytes, $engine = '', $run_id = NULL)
	{
		$row = self::build_part_get($build_id, $part);

		if ( ! is_array($row))
		{
			return ['ok' => FALSE, 'error' => 'Такого узла у сборки нет.'];
		}

		if ($run_id !== NULL AND (int) Arr::get($row, 'parts_run_id', 0) !== (int) $run_id)
		{
			return ['ok' => FALSE, 'error' => 'STL принадлежит другому запуску подготовки.'];
		}

		$build = self::build_get($build_id);
		$bytes = (string) $bytes;
		$max_mb = (int) Service_Nj667_Build::limit('max_stl_mb', 24);

		if ($max_mb > 0 AND strlen($bytes) > $max_mb * 1024 * 1024)
		{
			self::build_part_mark($build_id, $part, 'error', 'STL больше '.$max_mb.' МБ — не принимаем.');

			return ['ok' => FALSE, 'error' => 'STL больше '.$max_mb.' МБ.'];
		}

		$stats = Service_Nj667_Build::stl_stats($bytes);

		if (empty($stats['ok']))
		{
			$msg = (string) Arr::get($stats, 'error', 'STL не разобран.');

			self::build_part_mark($build_id, $part, 'error', $msg.($engine !== '' ? ' ('.$engine.')' : ''));

			return ['ok' => FALSE, 'error' => $msg];
		}

		$project = self::project_by_id((int) Arr::get($build, 'project_id', 0));
		$code    = (string) Arr::get($project, 'code', '');
		$dir     = self::storage_dir($code);
		$rel     = 'nj667/'.$code.'/build-'.$build_id.'-part-'.$part.'.stl';
		$path    = $dir !== '' ? $dir.'/build-'.$build_id.'-part-'.$part.'.stl' : '';

		if ($path === '' OR @file_put_contents($path, $bytes) === FALSE)
		{
			self::build_part_mark($build_id, $part, 'error', 'Модель узла не записалась на диск.');

			return ['ok' => FALSE, 'error' => 'Модель узла не записалась на диск.'];
		}

		@chmod($path, 0640);

		self::_exec('UPDATE nj_667_build_parts SET status = \'done\', stl_path = '.self::_q($rel)
			.', stl_size = '.(int) strlen($bytes).', stl_sha = '.self::_q((string) sha1($bytes))
			.', error = \'\', done_at = now() WHERE build_id = '.(int) $build_id
			.' AND part_index = '.(int) $part);

		return ['ok' => TRUE, 'stats' => $stats, 'size' => strlen($bytes),
			'copies' => (int) Arr::get($row, 'copies')];
	}

	public static function build_part_mark($build_id, $part, $status, $error = '', $render_ms = 0)
	{
		$allow  = ['spec', 'queued', 'done', 'error'];
		$status = (string) $status;

		if ( ! in_array($status, $allow, TRUE))
		{
			return ['ok' => FALSE, 'error' => 'Неизвестный статус узла.'];
		}

		$set = 'status = '.self::_q($status).', error = '
			.self::_q(UTF8::substr(trim((string) $error), 0, 480));

		if ($render_ms > 0)
		{
			$set .= ', render_ms = '.(int) $render_ms;
		}

		if (in_array($status, ['done', 'error'], TRUE))
		{
			$set .= ', done_at = now()';
		}

		self::_exec('UPDATE nj_667_build_parts SET '.$set.' WHERE build_id = '.(int) $build_id
			.' AND part_index = '.(int) $part);

		return ['ok' => TRUE];
	}

	/**
	 * Итог пачки от воркера: статус по каждому узлу, затем — по сборке.
	 *
	 * «Успех без файла» узла стоит ошибки ровно как у сборки: человек иначе
	 * скачал бы чертёж типа, решив, что STL уже лежит. Часть готовой пачки —
	 * тоже успех: недостающее доделывается той же кнопкой.
	 */
	public static function build_parts_result($build_id, array $parts, $render_ms = 0,
		$error = '', $run_id = NULL)
	{
		$current_run = self::build_parts_run_id($build_id);

		if ($run_id !== NULL AND (int) $run_id !== $current_run)
		{
			return ['ok' => FALSE, 'error' => 'Результат относится к устаревшему запуску деталей.',
				'run_id' => $current_run, 'stale' => TRUE];
		}

		$run_id = $current_run;
		$done = 0;
		$fail = 0;
		$first = trim((string) $error);

		foreach ((array) $parts as $row)
		{
			$part = (int) Arr::get($row, 'part', -1);
			$cur  = self::build_part_get($build_id, $part);

			if ( ! is_array($cur) OR (int) Arr::get($cur, 'parts_run_id', 0) !== (int) $run_id)
			{
				continue;
			}

			$ok   = ! empty($row['ok']);
			$file = trim((string) Arr::get($cur, 'stl_path', ''));

			if ($ok AND $file !== '')
			{
				self::build_part_mark($build_id, $part, 'done', '', (int) Arr::get($row, 'render_ms', 0));
				$done++;

				continue;
			}

			$why = $ok ? 'Воркер сообщил об успехе, но STL не прислал.'
				: (string) Arr::get($row, 'error', 'рендер не удался');

			self::build_part_mark($build_id, $part, 'error', $why, (int) Arr::get($row, 'render_ms', 0));

			if ($first === '')
			{
				$first = 'узел '.$part.': '.$why;
			}

			$fail++;
		}

		// Строки «queued», до которых воркер не дошёл (вышел бюджет задачи),
		// задача уже не держит: возвращаем их в «ждут». Иначе висели бы в
		// «в очереди» вечно, и цепочка их не подхватила бы.
		self::_exec('UPDATE nj_667_build_parts SET status = \'spec\' WHERE build_id = '
			.(int) $build_id.' AND parts_run_id = '.(int) $run_id.' AND status = \'queued\'');

		$rest = self::build_parts_spec_count($build_id, $run_id);

		if ($rest > 0 AND $done > 0)
		{
			$first = 'готово '.$done.', осталось '.$rest.' — следующая пачка';
		}
		elseif ($rest > 0 AND $done === 0 AND $first === '')
		{
			$first = 'узлы не собраны: не хватило времени рендера';
		}

		// Статус сборки НЕ трогаем: он про модель изделия, а пачка — отдельная
		// работа, и каждый узел отметился сам. Пометить сборку «done» после
		// узлов значило бы соврать: страница показала бы «модель готова» при
		// нуле байт, а просмотрщик — «модель не легла» там, где модель никто
		// не заказывал. Слово о недобранной пачке уходит вызывающему.
		return ['ok' => TRUE, 'done' => $done, 'failed' => $fail, 'pending' => $rest,
			'run_id' => (int) $run_id, 'note' => $first, 'render_ms' => (int) $render_ms];
	}

	/**
	 * Задача пачки: job_id живых узлов (строки «queued» несут её id). 0 — пачки нет.
	 * У сборки job_id — рендер модели, он пачку не описывает.
	 */
	public static function build_parts_job($build_id)
	{
		return (int) self::_value('SELECT job_id FROM nj_667_build_parts WHERE build_id = '
			.(int) $build_id.' AND status = \'queued\' AND job_id > 0 ORDER BY part_index ASC LIMIT 1');
	}

	/** Узлы, которые ещё ни разу не пробовали (spec): только им нужна следующая пачка. */
	public static function build_parts_spec_count($build_id, $run_id = NULL)
	{
		$where = $run_id === NULL ? '' : ' AND parts_run_id = '.(int) $run_id;

		return (int) self::_value('SELECT count(*) FROM nj_667_build_parts WHERE build_id = '
			.(int) $build_id.$where.' AND status = \'spec\'');
	}

	/**
	 * Следующая пачка сама — сразу после итога предыдущей, в том же запросе
	 * воркера. Человеку не нужно второй раз жать кнопку и перезагружать страницу.
	 *
	 * Бюджет на пачку (parts_max, parts_budget_s) остаётся: каждая задача по-прежнему
	 * короткая, меняется только то, что остаток не ждёт нажатия. Цикл конечен:
	 * цепочка идёт, только если пачка дала хотя бы один НОВЫЙ готовый узел, а
	 * таких узлов у сборки конечное число.
	 */
	public static function build_parts_continue($build_id, $run_id = NULL)
	{
		$build = self::build_get($build_id);

		if ( ! is_array($build))
		{
			return ['ok' => FALSE, 'error' => 'Сборка не найдена.'];
		}

		$current_run = self::build_parts_run_id($build_id);
		$run_id = $run_id === NULL ? $current_run : (int) $run_id;

		if ($run_id <= 0 OR $run_id !== $current_run)
		{
			return ['ok' => FALSE, 'error' => 'Запуск подготовки деталей устарел.'];
		}

		$project = self::project_by_id((int) Arr::get($build, 'project_id', 0));

		if ( ! is_array($project))
		{
			return ['ok' => FALSE, 'error' => 'Проект изделия не найден.'];
		}

		$opt = self::build_opt([], (array) json_decode((string) Arr::get($build, 'params_json', ''), TRUE));

		return self::build_parts_render($build_id, $project, self::graph_of($project), $opt, $run_id);
	}

	/**
	 * Остановить цепочку: узлы, которые дальше не поставить, получают причину
	 * строкой ошибки. Страница тогда говорит, что случилось, а кнопка снова
	 * активна — после исправления причины (например, пересчёта изделия).
	 */
	public static function build_parts_stop($build_id, $why, $run_id = NULL)
	{
		$why = trim((string) $why);

		if ($why === '')
		{
			$why = 'узел не собран';
		}

		foreach (self::build_parts($build_id) as $row)
		{
			if ((string) Arr::get($row, 'status') === 'spec'
				AND ($run_id === NULL OR (int) Arr::get($row, 'parts_run_id', 0) === (int) $run_id))
			{
				self::build_part_mark($build_id, (int) Arr::get($row, 'part_index'), 'error', $why);
			}
		}
	}

	/**
	 * Снять с сборки «queued», если задача воркера уже не живая.
	 *
	 * Статус сборки про модель изделия; залипнуть он мог по-разному:
	 * перечитать файлы дешевле, чем ждать, пока человек сам догадается нажать
	 * «Пересчитать». Возвращает исправленную строку (или входную — ломать нечего).
	 */
	public static function build_reconcile($build_id)
	{
		$build = self::build_get($build_id);

		if ( ! is_array($build))
		{
			return NULL;
		}

		$status = (string) Arr::get($build, 'status', '');

		if ( ! in_array($status, ['queued', 'running'], TRUE))
		{
			return $build;
		}

		// Гадать о жизни задачи, когда очереди нет, нельзя: сборка «queued»
		// без nj120 — это «мы её поставили, но работать некому», и менять
		// статус на свой страх мы не имеем права.
		if ( ! class_exists('Service_Nj120_JobQueue'))
		{
			return $build;
		}

		if (self::job_live(self::job_state((int) Arr::get($build, 'job_id', 0))))
		{
			return $build;
		}

		// Сверяем ровно тот формат, который запрашивала эта задача. Для старых
		// строк сохраняем прежнее поведение «любой файл подходит».
		$render_kind = strtolower(trim((string) Arr::get($build, 'render_kind', 'legacy')));
		$have_glb = self::build_file_path($build, 'glb') !== '';
		$have_stl = self::build_file_path($build, 'stl') !== '';

		if ($render_kind === 'glb')
		{
			$have = $have_glb;
		}
		elseif ($render_kind === 'stl')
		{
			$have = $have_stl;
		}
		else
		{
			$have = ($have_glb OR $have_stl);
		}

		$next = $have ? 'done' : 'spec';

		self::_exec('UPDATE nj_667_builds SET status = '.self::_q($next).' WHERE id = '
			.(int) Arr::get($build, 'id', 0));

		self::_log(Log::INFO, 'nj667: сборка :id висела в :from без живой задачи — статус :to',
			[':id' => (int) Arr::get($build, 'id', 0), ':from' => $status, ':to' => $next]);

		return self::build_get((int) Arr::get($build, 'id', 0));
	}

	/** Состояние задачи воркера для поллинга (пусто, если очереди нет). */
	public static function job_state($job_id)
	{
		$job_id = (int) $job_id;

		if ($job_id <= 0 OR ! class_exists('Service_Nj120_JobQueue'))
		{
			return [];
		}

		try
		{
			return (array) Service_Nj120_JobQueue::instance()->status_of($job_id);
		}
		catch (Throwable $e)
		{
			self::_log(Log::WARNING, 'статус задачи :id недоступен: :e',
				[':id' => $job_id, ':e' => $e->getMessage()]);

			return [];
		}
	}

	/**
	 * Задача уже не живая: failed/cancelled или retry с текстом ошибки.
	 *
	 * nj120 при fail() и attempts < max возвращает pending + error — для UI
	 * это тот же отказ, ждать второй заход модели незачем. Владелец, 11.10.2026.
	 */
	public static function job_failed(array $job)
	{
		if ( ! $job)
		{
			return FALSE;
		}

		$status = strtolower((string) Arr::get($job, 'status', Arr::get($job, 'state', '')));

		if (in_array($status, ['failed', 'error', 'cancelled'], TRUE))
		{
			return TRUE;
		}

		$error = trim((string) Arr::get($job, 'error', ''));

		return $error !== '' AND ! in_array($status, ['running', 'done'], TRUE);
	}

	/**
	 * Задача ещё строится: queued/running. Иначе поллинг холста не нужен.
	 *
	 * status_of для пропавшей задачи отдаёт status=none, found=false — это
	 * не «живёт», job_failed() её тоже не ловит (владелец, 11.10.2026).
	 */
	public static function job_live(array $job)
	{
		if ( ! $job)
		{
			return FALSE;
		}

		if (array_key_exists('found', $job) AND ! Arr::get($job, 'found'))
		{
			return FALSE;
		}

		if (self::job_failed($job))
		{
			return FALSE;
		}

		$status = strtolower((string) Arr::get($job, 'status', Arr::get($job, 'state', '')));

		return in_array($status, ['queued', 'running', 'pending', 'processing'], TRUE);
	}

	/**
	 * job_id проекта, если сборка реально идёт. Иначе сбрасываем хвост в БД.
	 */
	public static function live_job_id($project)
	{
		$project = (array) $project;
		$id      = (int) Arr::get($project, 'id', 0);
		$job_id  = (int) Arr::get($project, 'job_id', 0);

		if ($job_id <= 0)
		{
			return 0;
		}

		if (self::job_live(self::job_state($job_id)))
		{
			return $job_id;
		}

		self::clear_job($id);

		return 0;
	}

	/**
	 * Короткий текст ошибки задачи для холста: сырой ValueError с куском JSON
	 * в тост не кладём.
	 */
	public static function job_error_text(array $job)
	{
		$raw = trim((string) Arr::get($job, 'error', ''));

		if ($raw === '')
		{
			return self::job_failed($job) ? 'сборка не удалась' : '';
		}

		if (stripos($raw, 'json') !== FALSE)
		{
			return 'Модель вернула сломанный граф. Нажмите «Построить граф» ещё раз или упростите описание.';
		}

		$raw = preg_replace('/\s+/u', ' ', $raw);

		return UTF8::substr($raw, 0, 240);
	}

	// ------------------------------------------------------------------ БД

	/**
	 * Тип колонки embedding: pgvector (`vector`) или запасной `real[]`.
	 *
	 * information_schema.data_type для vector — USER-DEFINED, для real[] —
	 * ARRAY. Смотрим udt_name: так не путаем «массив» с «вектором».
	 */
	protected static function _embedding_is_array()
	{
		$row = self::_one("SELECT udt_name FROM information_schema.columns
			WHERE table_name = 'nj_667_projects' AND column_name = 'embedding' LIMIT 1");

		return strtolower((string) Arr::get((array) $row, 'udt_name', 'vector')) !== 'vector';
	}

	/** Литерал вектора в том типе, в каком колонка реально лежит. */
	protected static function _embedding_cast(array $vector)
	{
		if (self::_embedding_is_array())
		{
			return "'{".implode(',', $vector)."}'::real[]";
		}

		return "'[".implode(',', $vector)."]'::vector";
	}

	protected static function _insert($table, array $row)
	{
		$fields = [];
		$values = [];

		foreach ($row as $field => $value)
		{
			$fields[] = $field;
			$values[] = $value === NULL ? 'NULL' : self::_q($value);
		}

		$one = self::_one('INSERT INTO '.$table.' ('.implode(', ', $fields).') VALUES ('
			.implode(', ', $values).') RETURNING id');

		return (int) Arr::get((array) $one, 'id', 0);
	}

	protected static function _value($sql)
	{
		$row = self::_one($sql);

		return $row === NULL ? 0 : (int) reset($row);
	}

	protected static function _log($level, $message, array $params = [])
	{
		if ( ! class_exists('Log_Plain') OR ! is_object(Kohana::$log))
		{
			return;
		}

		Log_Plain::event($level, 'NJ667 '.$message, $params);
	}

	protected static function _db()
	{
		return Database::instance();
	}

	/**
	 * Образец для витрины на главной: «клон с палочками» — исходный уголок,
	 * его копия (сдвинута по z) и три палочки между парами вершин. Координаты
	 * условные, в единицах модели; на главной только показываем, не сохраняем.
	 */
	public static function home_sample()
	{
		return [
			'nodes' => [
				['id' => 'a',  'type' => 'connector', 'x' => 0,  'y' => 0,  'z' => 0],
				['id' => 'b',  'type' => 'endpoint',  'x' => 10, 'y' => 0,  'z' => 0],
				['id' => 'c',  'type' => 'endpoint',  'x' => 0,  'y' => 10, 'z' => 0],
				['id' => 'a2', 'type' => 'connector', 'x' => 0,  'y' => 0,  'z' => 14],
				['id' => 'b2', 'type' => 'endpoint',  'x' => 10, 'y' => 0,  'z' => 14],
				['id' => 'c2', 'type' => 'endpoint',  'x' => 0,  'y' => 10, 'z' => 14],
			],
			'edges' => [
				['id' => 'e1', 'source' => 'a',  'target' => 'b',  'type' => 'rigid'],
				['id' => 'e2', 'source' => 'a',  'target' => 'c',  'type' => 'rigid'],
				['id' => 'e3', 'source' => 'a2', 'target' => 'b2', 'type' => 'rigid'],
				['id' => 'e4', 'source' => 'a2', 'target' => 'c2', 'type' => 'rigid'],
				['id' => 'e5', 'source' => 'a',  'target' => 'a2', 'type' => 'rigid'],
				['id' => 'e6', 'source' => 'b',  'target' => 'b2', 'type' => 'rigid'],
				['id' => 'e7', 'source' => 'c',  'target' => 'c2', 'type' => 'rigid'],
			],
		];
	}

	/**
	 * SQL статуса «готово» для записи: опубликованная сборка остаётся
	 * `published`, а не падает в `done` при перезаписи файла или статуса.
	 */
	protected static function _done_status_sql()
	{
		return 'CASE WHEN published_at IS NULL THEN \'done\' ELSE \'published\' END';
	}

	protected static function _q($value)
	{
		return self::_db()->quote($value);
	}

	protected static function _one($sql)
	{
		$row = self::_db()->query(Database::SELECT, $sql, FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	protected static function _all($sql)
	{
		$rows = self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();

		return $rows ? array_map(function ($row) { return (array) $row; }, $rows) : [];
	}

	protected static function _exec($sql)
	{
		return self::_db()->query(Database::INSERT, $sql, FALSE);
	}
}
