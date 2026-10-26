<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-668 «Плоские иллюстрации»: единственная дверь в модуль.
 *
 * Модель данных — векторная сцена (прямоугольники, круги, ломаные, кубические
 * Безье, path) в jsonb. Модель рисует её по описанию темы, браузер показывает
 * SVG и даёт править руками. Картинку всегда можно пересчитать; источник
 * правды — сцена, не PNG (владелец, 11.10.2026).
 *
 * Чистая геометрия живёт в Service_Nj668_Scene. Здесь — проекты, очередь,
 * доступ, превью.
 */
class Service_Nj668_Draw2d
{
	const S_DRAFT     = 'draft';
	const S_PUBLISHED = 'published';
	const S_REJECTED  = 'rejected';

	const CH_GENERATE = 'nj668.generate';
	const CH_IDEAS    = 'nj668.ideas';

	const PHOTO_MAX       = 4;
	const PHOTO_MAX_BYTES = 5242880;
	const PHOTO_MAX_SIDE  = 1280;

	public static function config()
	{
		$config = Kohana::$config->load('nj668_draw2d');

		return $config instanceof Kohana_Config_Group ? $config->as_array() : (array) $config;
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

	public static function statuses()
	{
		return (array) Arr::get(self::config(), 'statuses', [
			'draft'     => 'Черновик',
			'published' => 'В каталоге',
			'rejected'  => 'Отклонена',
		]);
	}

	public static function presets()
	{
		return (array) Arr::get(self::config(), 'presets', []);
	}

	public static function timeout($channel_key)
	{
		return (int) Arr::get(self::branch('timeout'), $channel_key, 300);
	}

	public static function model()
	{
		return trim((string) (getenv('NJ668_MODEL') ?: Arr::get(self::config(), 'model', '')));
	}

	/**
	 * Дешёвая модель для идей. NJ668_LIGHT_MODEL из .env, иначе та же, что model()
	 * (владелец, 11.10.2026). NJ668_MODEL — только сборка сцены.
	 */
	public static function light_model()
	{
		$own = UTF8::substr(trim((string) (getenv('NJ668_LIGHT_MODEL') ?: '')), 0, 160);

		if ($own !== '')
		{
			return $own;
		}

		return self::model();
	}

	/**
	 * До какой длинной стороны уменьшать кадр для модели, px.
	 * NJ668_PHOTO_MAX_PX, иначе 512 (владелец, 11.10.2026).
	 */
	public static function photo_max_px()
	{
		$raw = trim(self::setting('photo_max_px', ''));

		if ($raw === '')
		{
			$raw = (string) (getenv('NJ668_PHOTO_MAX_PX') ?: '512');
		}

		$n = (int) $raw;

		return max(128, min(2048, $n > 0 ? $n : 512));
	}

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

	public static function create($user_id, array $opt)
	{
		$count = 0;

		try
		{
			$count = (int) self::_value('SELECT count(*) FROM nj_668_projects
				WHERE deleted_at IS NULL AND user_id = '.(int) $user_id);
		}
		catch (Throwable $e)
		{
			$count = 0;
		}

		if ($count >= (int) self::limits('max_projects', 200))
		{
			return ['ok' => FALSE, 'error' => 'Иллюстраций уже '
				.(int) self::limits('max_projects', 200).' — удалите ненужные.'];
		}

		$scene  = Service_Nj668_Scene::clean(Arr::get($opt, 'scene', Service_Nj668_Scene::scene_new()));
		$prompt = UTF8::substr(trim((string) Arr::get($opt, 'prompt', '')), 0,
			(int) self::limits('max_prompt', 800));
		$title  = UTF8::substr(trim((string) Arr::get($opt, 'title', '')), 0, 200);

		if ($title === '')
		{
			$title = $prompt !== '' ? UTF8::substr($prompt, 0, 200) : '';
		}

		if ($title === '' AND ! empty($opt['has_photos']))
		{
			$title = 'Иллюстрация по фото';
		}

		if ($title === '')
		{
			$title = 'Новая иллюстрация';
		}

		$code = self::code_new();
		$uid  = class_exists('Visitor') ? (string) Visitor::uid() : '';

		$row = [
			'code'         => $code,
			'user_id'      => (int) $user_id,
			'visitor_uid'  => mb_substr($uid, 0, 40),
			'title'        => $title,
			'summary'      => UTF8::substr(trim((string) Arr::get($opt, 'summary', '')), 0, 1000),
			'tags'         => UTF8::substr(trim((string) Arr::get($opt, 'tags', '')), 0, 300),
			'kind'         => 'private',
			'status'       => self::S_DRAFT,
			'scene_json'   => json_encode($scene, JSON_UNESCAPED_UNICODE),
			'prompt'       => $prompt,
			'shapes_count' => count(Arr::get($scene, 'shapes', [])),
			'model'        => UTF8::substr((string) Arr::get($opt, 'model', self::model()), 0, 160),
		];

		try
		{
			$id = self::_insert('nj_668_projects', $row);
		}
		catch (Exception $e)
		{
			self::_log(Log::ERROR, 'проект не создан: :error', [':error' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Не удалось создать иллюстрацию.'];
		}

		return ['ok' => TRUE, 'id' => $id, 'code' => $code];
	}

	public static function project($code)
	{
		$code = trim((string) $code);

		if ($code === '' OR preg_match('~^[23456789a-z]{8,24}$~', $code) !== 1)
		{
			return NULL;
		}

		return self::_one('SELECT * FROM nj_668_projects WHERE code = '.self::_q($code)
			.' AND deleted_at IS NULL');
	}

	public static function project_by_id($id)
	{
		return self::_one('SELECT * FROM nj_668_projects WHERE id = '.(int) $id
			.' AND deleted_at IS NULL');
	}

	public static function scene_of(array $project)
	{
		$raw = Arr::get($project, 'scene_json');

		if (is_array($raw))
		{
			return Service_Nj668_Scene::clean($raw);
		}

		$decoded = json_decode((string) $raw, TRUE);

		return Service_Nj668_Scene::clean(is_array($decoded) ? $decoded : []);
	}

	public static function save_scene($project_id, $scene, $title = NULL, array $opt = [])
	{
		$project_id = (int) $project_id;
		$clean      = Service_Nj668_Scene::clean($scene, (int) self::limits('max_shapes', 120));

		self::_store_scene($project_id, $clean, $title);

		if (Arr::get($opt, 'revision', TRUE))
		{
			self::commit_revision($project_id, $clean,
				(string) Arr::get($opt, 'label', ''),
				(string) Arr::get($opt, 'author_kind', 'user'));
		}

		return ['ok' => TRUE, 'scene' => $clean];
	}

	public static function accept_scene($project_id, $scene, $model = '')
	{
		$done = self::save_scene($project_id, $scene, NULL, [
			'revision'    => TRUE,
			'author_kind' => 'ai',
			'label'       => 'модель',
		]);

		if ( ! Arr::get($done, 'ok'))
		{
			return $done;
		}

		if (trim((string) $model) !== '')
		{
			self::_exec('UPDATE nj_668_projects SET model = '
				.self::_q(UTF8::substr((string) $model, 0, 160))
				.' WHERE id = '.(int) $project_id);
		}

		return $done;
	}

	protected static function _store_scene($project_id, array $clean, $title = NULL)
	{
		$set = [
			'scene_json = '.self::_q(json_encode($clean, JSON_UNESCAPED_UNICODE)),
			'shapes_count = '.count(Arr::get($clean, 'shapes', [])),
			'error = \'\'',
			'updated_at = now()',
		];

		if ($title !== NULL)
		{
			$title = UTF8::substr(trim((string) $title), 0, 200);

			if ($title !== '')
			{
				$set[] = 'title = '.self::_q($title);
			}
		}

		self::_exec('UPDATE nj_668_projects SET '.implode(', ', $set)
			.' WHERE id = '.(int) $project_id);

		self::clear_job($project_id);
	}

	public static function set_prompt($project_id, $prompt)
	{
		self::_exec('UPDATE nj_668_projects SET prompt = '
			.self::_q(UTF8::substr(trim((string) $prompt), 0, (int) self::limits('max_prompt', 800)))
			.', updated_at = now() WHERE id = '.(int) $project_id);

		return TRUE;
	}

	public static function set_error($project_id, $error)
	{
		self::_exec('UPDATE nj_668_projects SET error = '
			.self::_q(UTF8::substr((string) $error, 0, 500)).', job_id = 0, updated_at = now()
			WHERE id = '.(int) $project_id);

		return TRUE;
	}

	public static function for_user($user_id, $uid = '', $limit = 24)
	{
		$limit = min(60, max(1, (int) $limit));
		$where = ['deleted_at IS NULL'];

		if ((int) $user_id > 0)
		{
			$where[] = 'user_id = '.(int) $user_id;
		}
		else
		{
			$uid = trim((string) $uid);

			if ($uid === '')
			{
				return [];
			}

			$where[] = 'visitor_uid = '.self::_q(mb_substr($uid, 0, 40));
		}

		return self::_all('SELECT id, code, title, summary, tags, status, shapes_count,
				views, job_id, updated_at, scene_json
			FROM nj_668_projects
			WHERE '.implode(' AND ', $where).'
			ORDER BY id DESC LIMIT '.$limit);
	}

	public static function catalog($limit = 24, $query = '')
	{
		$limit = min(60, max(1, (int) $limit));
		$where = 'deleted_at IS NULL AND status = '.self::_q(self::S_PUBLISHED);
		$query = UTF8::substr(trim((string) $query), 0, 80);

		if ($query !== '')
		{
			$like  = self::_q('%'.$query.'%');
			$where .= ' AND (title ILIKE '.$like.' OR summary ILIKE '.$like.' OR tags ILIKE '.$like.')';
		}

		return self::_all('SELECT id, code, title, summary, tags, status, shapes_count,
				views, featured, updated_at, scene_json
			FROM nj_668_projects
			WHERE '.$where.'
			ORDER BY featured DESC, views DESC, id DESC
			LIMIT '.$limit);
	}

	public static function showcase($limit = 9)
	{
		return self::catalog($limit);
	}

	public static function publish($code, $user_id, $uid = '')
	{
		$project = self::project($code);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Иллюстрация не найдена.'];
		}

		if ( ! self::owns($project, $user_id, $uid))
		{
			return ['ok' => FALSE, 'error' => 'Это чужая иллюстрация.'];
		}

		$scene = self::scene_of($project);

		if ( ! Arr::get($scene, 'shapes'))
		{
			return ['ok' => FALSE, 'error' => 'Пустую сцену в каталог не кладём — сначала нарисуйте.'];
		}

		self::_exec('UPDATE nj_668_projects
			SET status = '.self::_q(self::S_PUBLISHED).', kind = \'public\', updated_at = now()
			WHERE id = '.(int) Arr::get($project, 'id'));

		return ['ok' => TRUE];
	}

	public static function delete($code, $user_id, $uid = '')
	{
		$project = self::project($code);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Иллюстрация не найдена.'];
		}

		if ( ! self::owns($project, $user_id, $uid))
		{
			return ['ok' => FALSE, 'error' => 'Это чужая иллюстрация.'];
		}

		self::_exec('UPDATE nj_668_projects SET deleted_at = now(), updated_at = now()
			WHERE id = '.(int) Arr::get($project, 'id'));

		return ['ok' => TRUE, 'code' => (string) Arr::get($project, 'code')];
	}

	public static function bump_views($project_id)
	{
		self::_exec('UPDATE nj_668_projects SET views = views + 1 WHERE id = '.(int) $project_id);

		return TRUE;
	}

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

	public static function is_public(array $project)
	{
		return (string) Arr::get($project, 'status') === self::S_PUBLISHED;
	}

	// -------------------------------------------------------------- очередь

	public static function queue($channel, array $project, array $payload = [])
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

		if (trim((string) Arr::get($payload, 'model', '')) === '')
		{
			$chosen = self::model();

			if ($chosen !== '')
			{
				$payload['model'] = $chosen;
			}
		}

		try
		{
			$job_id = Service_Nj120_JobQueue::push($channel, $payload + ['project_id' => $project_id], [
				'module'       => 'nj668',
				'visitor_id'   => 'u:'.(int) Arr::get($project, 'user_id', 0),
				'created_by'   => (int) Arr::get($project, 'user_id', 0),
				'max_attempts' => 1,
				'timeout_sec'  => self::timeout('generate'),
			]);
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'очередь не приняла :channel: :e',
				[':channel' => $channel, ':e' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Очередь не приняла задачу: '.$e->getMessage()];
		}

		self::_exec('UPDATE nj_668_projects SET job_id = '.(int) $job_id.', error = \'\', updated_at = now()
			WHERE id = '.$project_id);

		return ['ok' => TRUE, 'job_id' => (int) $job_id];
	}

	public static function clear_job($project_id)
	{
		$project_id = (int) $project_id;

		if ($project_id <= 0)
		{
			return;
		}

		try
		{
			self::_exec('UPDATE nj_668_projects SET job_id = 0, updated_at = now()
				WHERE id = '.$project_id);
		}
		catch (Throwable $e)
		{
			self::_log(Log::WARNING, 'не сбросил job_id проекта :id: :e',
				[':id' => $project_id, ':e' => $e->getMessage()]);
		}
	}

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
			return [];
		}
	}

	public static function job_failed(array $job)
	{
		if ( ! $job)
		{
			return FALSE;
		}

		$status = strtolower((string) Arr::get($job, 'status', Arr::get($job, 'state', '')));

		if (in_array($status, ['failed', 'fail', 'error', 'cancelled', 'canceled'], TRUE))
		{
			return TRUE;
		}

		return trim((string) Arr::get($job, 'error', '')) !== ''
			AND ! in_array($status, ['running', 'active', 'working'], TRUE);
	}

	/**
	 * Задача ещё рисует: queued/running. Иначе поллинг холста не нужен.
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

		return in_array($status, ['queued', 'running', 'pending', 'processing', 'active', 'working'], TRUE);
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

	public static function job_error_text(array $job)
	{
		$text = trim((string) Arr::get($job, 'error', Arr::get($job, 'message', '')));

		return $text !== '' ? UTF8::substr($text, 0, 400) : 'Модель не нарисовала сцену.';
	}

	public static function worker_context($project_id)
	{
		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Проект не найден.'];
		}

		return [
			'ok'           => TRUE,
			'id'           => (int) Arr::get($project, 'id'),
			'code'         => (string) Arr::get($project, 'code'),
			'title'        => (string) Arr::get($project, 'title'),
			'prompt'       => (string) Arr::get($project, 'prompt'),
			'scene'        => self::scene_of($project),
			'limits'       => self::limits(),
			'canvas'       => ['w' => Service_Nj668_Scene::canvas_w(), 'h' => Service_Nj668_Scene::canvas_h()],
			'photo_max_px' => self::photo_max_px(),
			'photos'       => self::photos_payload((string) Arr::get($project, 'code')),
		];
	}

	// ------------------------------------------------------------- превью

	public static function preview_href(array $row)
	{
		$code = rawurlencode((string) Arr::get($row, 'code', ''));

		return '/draw2d/api/preview?code='.$code;
	}

	public static function preview_img(array $row, $alt = '', $class = '')
	{
		$alt   = HTML::chars((string) $alt);
		$class = trim((string) $class);
		$cls   = $class === '' ? '' : ' '.$class;
		$href  = HTML::chars(self::preview_href($row));

		// Картинка целиком, без обрезки; своя ширина у миниатюры (w-12) главнее w-full.
		$base  = 'nj668-preview block object-contain bg-blue-50 dark:bg-gray-900';
		$base .= preg_match('/(^|\s)w-/', $class) ? '' : ' w-full';

		return '<img src="'.$href.'" alt="'.$alt.'" class="'.$base.$cls.'">';
	}

	public static function svg_of(array $project, $width = 0, $height = 0)
	{
		return Service_Nj668_Scene::svg(self::scene_of($project), $width, $height);
	}

	// ------------------------------------------------------------- limiter

	public static function rate_ok()
	{
		if (class_exists('Service_Nj100_Limiter'))
		{
			return (bool) Service_Nj100_Limiter::ok('nj668',
				Arr::get(self::branch('rate'), 'per_minute', 6),
				Arr::get(self::branch('rate'), 'per_hour', 40));
		}

		return TRUE;
	}

	public static function rate_text()
	{
		return (int) Arr::get(self::branch('rate'), 'per_minute', 6).' в минуту, '
			.(int) Arr::get(self::branch('rate'), 'per_hour', 40).' в час';
	}

	public static function sweep($limit = NULL)
	{
		$days  = max(1, (int) Arr::get(self::config(), 'ttl_days', 180));
		$limit = (int) ($limit ?: Arr::get(self::config(), 'sweep_limit', 20));

		if ($limit < 1)
		{
			return 0;
		}

		self::_exec('UPDATE nj_668_projects SET deleted_at = now()
			WHERE id IN (
				SELECT id FROM nj_668_projects
				WHERE deleted_at IS NULL
					AND user_id = 0
					AND status = '.self::_q(self::S_DRAFT).'
					AND updated_at < now() - interval \''.$days.' days\'
				LIMIT '.$limit.')');

		return TRUE;
	}

	// -------------------------------------------------------------- фото

	/**
	 * Референс-фото: диск, не jsonb очереди (урок nj646).
	 * Кадры — storage/nj668/<code>/01.jpg. Воркер забирает из /draw2d/w/context.
	 */

	public static function photos_dir($code, $create = TRUE)
	{
		$code = trim((string) $code);

		if (preg_match('~^[A-Za-z0-9]{8,24}$~', $code) !== 1 OR ! defined('APPPATH'))
		{
			return '';
		}

		$dir = APPPATH.'../storage/nj668/'.$code;

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

	public static function uploads_count($files)
	{
		$n = 0;

		foreach (self::uploads_from($files) as $file)
		{
			if ((int) Arr::get($file, 'error', UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
				AND (int) Arr::get($file, 'size', 0) > 0)
			{
				$n++;
			}
		}

		return $n;
	}

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

	public static function prepare_uploads($files)
	{
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

			if (count($out) >= self::PHOTO_MAX)
			{
				break;
			}
		}

		return $out;
	}

	public static function prepare_payload(array $images)
	{
		$out = [];

		foreach (array_slice($images, 0, self::PHOTO_MAX) as $row)
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

	public static function photos_count($code)
	{
		return count(self::photos_files($code));
	}

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

	// -------------------------------------------------------------- настройки

	public static function setting($key, $default = '')
	{
		$key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));

		if ($key === '')
		{
			return (string) $default;
		}

		try
		{
			$row = self::_one('SELECT value FROM nj_668_settings WHERE key = '.self::_q($key).' LIMIT 1');

			if ($row !== NULL AND array_key_exists('value', $row))
			{
				return (string) $row['value'];
			}
		}
		catch (Throwable $e)
		{
		}

		return (string) $default;
	}

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
			self::_exec('INSERT INTO nj_668_settings (key, value, updated_at)
				VALUES ('.self::_q($key).', '.self::_q($value).', now())
				ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()');
		}
		catch (Throwable $e)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось сохранить настройку: '.$e->getMessage()];
		}

		return ['ok' => TRUE, 'key' => $key, 'value' => $value];
	}

	// -------------------------------------------------------------- идеи

	public static function idea_presets_fallback()
	{
		$from = self::presets();

		return $from ? array_values($from) : [
			'Закат над тихим морем: лодка с парусом, волны Безье, тёплое солнце',
			'Домик с красной крышей в зелёном лесу, тропинка и круглое солнце',
			'Полосатый воздушный шар над холмами, облака и корзина',
		];
	}

	public static function idea_presets($limit = 200)
	{
		$limit = max(1, min(500, (int) $limit));

		try
		{
			return self::_all('SELECT id, theme, body, job_id, created_at
				FROM nj_668_idea_presets
				ORDER BY id DESC
				LIMIT '.$limit);
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	public static function idea_presets_pick($n = 3)
	{
		$n    = max(1, min(6, (int) $n));
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

	public static function idea_presets_save($theme, array $ideas, $job_id = 0)
	{
		$theme  = UTF8::substr(trim((string) $theme), 0, 200);
		$job_id = (int) $job_id;
		$clean  = [];
		$seen   = [];

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
				$dup = self::_one('SELECT id FROM nj_668_idea_presets WHERE body = '.self::_q($text).' LIMIT 1');

				if ($dup !== NULL)
				{
					continue;
				}

				self::_exec('INSERT INTO nj_668_idea_presets (theme, body, job_id)
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

	public static function idea_preset_delete($id)
	{
		$id = (int) $id;

		if ($id <= 0)
		{
			return FALSE;
		}

		try
		{
			self::_exec('DELETE FROM nj_668_idea_presets WHERE id = '.$id);

			return TRUE;
		}
		catch (Throwable $e)
		{
			return FALSE;
		}
	}

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
				'module'       => 'nj668',
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

	// -------------------------------------------------------------- ревизии

	public static function commit_revision($project_id, array $scene, $label = '', $author_kind = 'user')
	{
		$project_id = (int) $project_id;

		if ($project_id <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Нет проекта.'];
		}

		$clean = Service_Nj668_Scene::clean($scene, (int) self::limits('max_shapes', 120));
		$hash  = Service_Nj668_Scene::hash($clean);

		try
		{
			$last = self::_one('SELECT scene_json FROM nj_668_revisions
				WHERE project_id = '.$project_id.' AND deleted_at IS NULL
				ORDER BY no DESC LIMIT 1');
		}
		catch (Throwable $e)
		{
			$last = NULL;
		}

		if ($last !== NULL AND Service_Nj668_Scene::hash(self::scene_of($last)) === $hash)
		{
			return ['ok' => TRUE, 'ops' => 0];
		}

		try
		{
			$no = 1 + (int) self::_value('SELECT COALESCE(max(no), 0) FROM nj_668_revisions
				WHERE project_id = '.$project_id);

			self::_insert('nj_668_revisions', [
				'project_id'  => $project_id,
				'no'          => $no,
				'kind'        => 'commit',
				'label'       => UTF8::substr(trim((string) $label), 0, 200),
				'scene_json'  => json_encode($clean, JSON_UNESCAPED_UNICODE),
				'author_kind' => $author_kind === 'ai' ? 'ai' : 'user',
			]);

			self::_trim_revisions($project_id);
		}
		catch (Throwable $e)
		{
			return ['ok' => FALSE, 'error' => 'Ревизию не записали.'];
		}

		return ['ok' => TRUE, 'no' => $no];
	}

	public static function restore($project_id, $revision_id)
	{
		$project_id  = (int) $project_id;
		$revision_id = (int) $revision_id;
		$rev         = self::revision_of($project_id, $revision_id);

		if ($rev === NULL OR Arr::get($rev, 'deleted_at'))
		{
			return ['ok' => FALSE, 'error' => 'Ревизия не найдена.'];
		}

		$scene = self::scene_of($rev);
		self::_store_scene($project_id, $scene);

		return ['ok' => TRUE, 'scene' => $scene, 'no' => (int) Arr::get($rev, 'no', 0)];
	}

	public static function delete_revision($project_id, $revision_id)
	{
		$project_id  = (int) $project_id;
		$revision_id = (int) $revision_id;

		try
		{
			$rev = self::_one('SELECT id FROM nj_668_revisions
				WHERE id = '.$revision_id.' AND project_id = '.$project_id.'
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

		self::_exec('UPDATE nj_668_revisions SET deleted_at = now()
			WHERE id = '.$revision_id.' AND project_id = '.$project_id);

		return ['ok' => TRUE];
	}

	public static function revisions($project_id, $limit = 50)
	{
		$limit = min(200, max(1, (int) $limit));
		$pid   = (int) $project_id;

		try
		{
			return self::_all('SELECT * FROM nj_668_revisions
				WHERE project_id = '.$pid.' AND deleted_at IS NULL
				ORDER BY no DESC LIMIT '.$limit);
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	public static function revision_of($project_id, $revision_id)
	{
		if ((int) $revision_id <= 0)
		{
			return NULL;
		}

		try
		{
			return self::_one('SELECT * FROM nj_668_revisions
				WHERE id = '.(int) $revision_id.' AND project_id = '.(int) $project_id.' LIMIT 1');
		}
		catch (Throwable $e)
		{
			return NULL;
		}
	}

	protected static function _trim_revisions($project_id)
	{
		$max = (int) self::limits('max_revisions', 80);
		$pid = (int) $project_id;

		if ($max < 8)
		{
			$max = 8;
		}

		try
		{
			self::_exec('DELETE FROM nj_668_revisions WHERE id IN (
				SELECT id FROM nj_668_revisions
				WHERE project_id = '.$pid.'
				ORDER BY no DESC
				OFFSET '.$max.')');
		}
		catch (Throwable $e)
		{
		}
	}

	// -------------------------------------------------------------- модерация

	public static function moderation_queue($status = NULL, $limit = 60)
	{
		$limit  = min(80, max(1, (int) $limit));
		$where  = 'deleted_at IS NULL';
		$status = (string) $status;

		if ($status !== '' AND array_key_exists($status, self::statuses()))
		{
			$where .= ' AND status = '.self::_q($status);
		}
		else
		{
			$where .= ' AND status IN ('.self::_q(self::S_PUBLISHED).', '.self::_q(self::S_REJECTED).')';
		}

		try
		{
			return self::_all('SELECT id, code, title, summary, tags, status, shapes_count,
					views, featured, updated_at, scene_json, moderation_note, model
				FROM nj_668_projects
				WHERE '.$where.'
				ORDER BY (status = '.self::_q(self::S_PUBLISHED).') DESC, featured DESC, updated_at DESC
				LIMIT '.$limit);
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	public static function moderate($project_id, $verdict, $note = '', $user_id = 0)
	{
		$project = self::project_by_id($project_id);

		if ($project === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Иллюстрация не найдена.'];
		}

		$note    = UTF8::substr(trim((string) $note), 0, 500);
		$verdict = (string) $verdict;

		if ( ! in_array($verdict, ['approve', 'reject', 'feature', 'unfeature'], TRUE))
		{
			return ['ok' => FALSE, 'error' => 'Неизвестное решение.'];
		}

		$set = ['updated_at = now()', 'reviewed_by = '.(int) $user_id, 'reviewed_at = now()',
			'moderation_note = '.self::_q($note), 'kind = \'public\''];

		switch ($verdict)
		{
			case 'approve':
				$set[] = 'status = '.self::_q(self::S_PUBLISHED);
				break;

			case 'reject':
				$set[] = 'status = '.self::_q(self::S_REJECTED);
				$set[] = 'featured = 0';
				break;

			case 'feature':
				$set[] = 'status = '.self::_q(self::S_PUBLISHED);
				$set[] = 'featured = 1';
				break;

			case 'unfeature':
				$set[] = 'featured = 0';
				break;
		}

		try
		{
			self::_exec('UPDATE nj_668_projects SET '.implode(', ', $set)
				.' WHERE id = '.(int) $project_id);
		}
		catch (Throwable $e)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось записать решение.'];
		}

		return ['ok' => TRUE, 'status' => $verdict];
	}

	// ------------------------------------------------------------- SQL

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

		Log_Plain::event($level, 'NJ668 '.$message, $params);
	}

	protected static function _db()
	{
		return Database::instance();
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
