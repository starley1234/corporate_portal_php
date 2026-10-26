<?php defined('SYSPATH') or die('No direct script access.');

/** Самостоятельные проекты деталей. БД — только nj_669_solid3d_*, очередь — публичный API nj120. */
class Service_Nj669_Solid3d
{
	public static function rows($sql, array $params = [])
	{
		return DB::query(Database::SELECT, $sql)->parameters($params)->execute()->as_array();
	}

	public static function row($sql, array $params = [])
	{
		$rows = self::rows($sql, $params);
		return $rows ? $rows[0] : NULL;
	}

	public static function exec($sql, array $params = [])
	{
		return DB::query(Database::UPDATE, $sql)->parameters($params)->execute();
	}

	public static function config($key)
	{
		return Kohana::$config->load('nj669_solid3d.'.$key);
	}

	public static function settings()
	{
		$settings = ['model' => trim((string) getenv('NJ669_MODEL')),
			'vision_model' => trim((string) getenv('NJ669_VISION_MODEL')),
			'light_model' => trim((string) getenv('NJ669_LIGHT_MODEL')),
			'projects' => (int) self::config('projects'), 'daily_runs' => (int) self::config('daily_runs'),
			'repairs' => 2];
		foreach (self::rows('SELECT key, value FROM nj_669_solid3d_settings') as $row)
		{
			if (array_key_exists($row['key'], $settings)) { $settings[$row['key']] = $row['value']; }
		}
		return $settings;
	}

	public static function save_settings(array $data)
	{
		foreach (['model', 'vision_model', 'light_model', 'projects', 'daily_runs', 'repairs'] as $key)
		{
			$value = trim((string) Arr::get($data, $key, ''));
			if (in_array($key, ['projects', 'daily_runs', 'repairs'], TRUE))
			{
				$max = $key === 'repairs' ? 2 : 1000;
				if ( ! ctype_digit($value) OR (int) $value > $max) { throw new DomainException('Некорректное значение лимита.'); }
			}
			if (mb_strlen($value) > 200) { throw new DomainException('Слишком длинное имя модели.'); }
		}
		$db = Database::instance(); $db->begin();
		try
		{
			foreach (['model', 'vision_model', 'light_model', 'projects', 'daily_runs', 'repairs'] as $key)
			{
				self::exec('INSERT INTO nj_669_solid3d_settings (key,value) VALUES (:key,:value) ON CONFLICT (key) DO UPDATE SET value=EXCLUDED.value',
					[':key' => $key, ':value' => trim((string) Arr::get($data, $key, ''))]);
			}
			$db->commit();
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
	}

	/** NULL наследует; ноль действительно запрещает операцию. Не привязано к VIP. */
	public static function effective_limits(array $settings, array $overrides = [])
	{
		$result = [];
		foreach (['projects', 'daily_runs'] as $key)
		{
			$result[$key] = max(0, (int) (isset($overrides[$key]) ? $overrides[$key] : $settings[$key]));
		}
		return $result;
	}

	public static function limits($uid)
	{
		$override = self::row('SELECT projects,daily_runs FROM nj_669_solid3d_quotas WHERE user_id=:u', [':u' => (int) $uid]);
		$limits = self::effective_limits(self::settings(), $override ?: []);
		$counts = self::row("SELECT (SELECT count(*) FROM nj_669_solid3d_projects WHERE user_id=:u AND deleted_at IS NULL) AS projects_used,
			(SELECT count(*) FROM nj_669_solid3d_revisions WHERE user_id=:u AND job_id>0 AND llm_run AND created_at >= date_trunc('day',now()))
			+ (SELECT count(*) FROM nj_669_solid3d_tasks WHERE user_id=:u AND kind IN ('caption','ideas') AND job_id>0 AND created_at >= date_trunc('day',now())) AS runs_used", [':u' => (int) $uid]);
		return $limits + $counts;
	}

	public static function save_limits($uid, array $data)
	{
		if ($uid <= 0) { throw new DomainException('Укажите числовой ID пользователя.'); }
		$params = [':u' => (int) $uid];
		foreach (['projects', 'daily_runs'] as $key)
		{
			$value = trim((string) Arr::get($data, $key, ''));
			if ($value !== '' AND ( ! ctype_digit($value) OR (int) $value > 1000)) { throw new DomainException('Лимит: 0–1000 или пусто для наследования.'); }
			$params[':'.$key] = $value === '' ? NULL : (int) $value;
		}
		self::exec('INSERT INTO nj_669_solid3d_quotas (user_id,projects,daily_runs) VALUES (:u,:projects,:daily_runs)
			ON CONFLICT (user_id) DO UPDATE SET projects=EXCLUDED.projects,daily_runs=EXCLUDED.daily_runs', $params);
	}

	public static function project($code, $lock = FALSE)
	{
		return self::row('SELECT * FROM nj_669_solid3d_projects WHERE code=:c AND deleted_at IS NULL'.($lock ? ' FOR UPDATE' : ''), [':c' => $code]);
	}

	public static function revision($id, $pid)
	{
		return self::row('SELECT * FROM nj_669_solid3d_revisions WHERE id=:id AND project_id=:p', [':id' => (int) $id, ':p' => (int) $pid]);
	}

	public static function can_read(array $p, $uid, $moderator, $public = FALSE)
	{
		return empty($p['deleted_at']) AND (($public AND (int) $p['published_revision_id'] > 0)
			OR ( ! $public AND ($moderator OR ((int) $uid > 0 AND (int) $p['user_id'] === (int) $uid))));
	}

	public static function can_read_revision(array $p, $rid, $uid, $moderator)
	{
		return self::can_read($p, $uid, $moderator) OR (empty($p['deleted_at']) AND (int) $rid > 0 AND (int) $p['published_revision_id'] === (int) $rid);
	}

	public static function listing($mode, $uid = 0, $search = '', $page = 1)
	{
		$params = [];
		$where = 'p.deleted_at IS NULL';
		if ($mode === 'projects') { $where .= ' AND p.user_id=:u'; $params[':u'] = (int) $uid; }
		elseif ($mode === 'moderate') { $where .= " AND p.publication='review'"; }
		else { $where .= ' AND p.published_revision_id>0'; }
		if ($search !== '')
		{
			if ($mode === 'projects') { $where .= ' AND (p.title ILIKE :q OR p.summary ILIKE :q OR p.tags ILIKE :q)'; }
			else
			{
				$meta = $mode === 'moderate' ? 'review_meta' : 'published_meta';
				$where .= " AND (COALESCE(p.".$meta."->>'title','') ILIKE :q OR COALESCE(p.".$meta."->>'summary','') ILIKE :q OR COALESCE(p.".$meta."->>'tags','') ILIKE :q)";
			}
			$params[':q'] = '%'.mb_substr($search, 0, 100).'%';
		}
		$select = $mode === 'moderate'
			? 'SELECT p.*, rv.visual_ok AS review_visual_ok, rv.vlm_status AS review_vlm_status, rv.vlm_checked_at AS review_vlm_checked_at,
				rv.tokens_in AS review_tokens_in, rv.tokens_out AS review_tokens_out, rv.vlm_tokens_in AS review_vlm_tokens_in, rv.vlm_tokens_out AS review_vlm_tokens_out
				FROM nj_669_solid3d_projects p LEFT JOIN nj_669_solid3d_revisions rv ON rv.id=p.review_revision_id'
			: 'SELECT p.* FROM nj_669_solid3d_projects p';
		$rows = self::rows($select.' WHERE '.$where.' ORDER BY p.updated_at DESC,p.id DESC LIMIT 24 OFFSET '.(max(0, min(10000, (int) $page - 1)) * 24), $params);
		if ($mode === 'projects') { return $rows; }
		return array_map(function ($p) use ($mode) { return Service_Nj669_Workspace::public_project($p, $mode === 'moderate'); }, $rows);
	}

	public static function root()
	{
		return dirname(rtrim(APPPATH, '/')).'/storage/nj669';
	}

	public static function dir($code)
	{
		if ( ! preg_match('/\A[a-f0-9]{20}\z/D', $code)) { throw new DomainException('Некорректный код проекта.'); }
		return self::root().'/'.$code;
	}

	public static function file($code, $rid, $kind)
	{
		if ((int) $rid <= 0 OR ! in_array($kind, ['stl','glb','png','png2','png3','scad'], TRUE)) { throw new DomainException('Некорректный файл.'); }
		return self::dir($code).'/r'.(int) $rid.'.'.$kind;
	}

	public static function mkdir($dir)
	{
		if ( ! is_dir($dir) AND ! mkdir($dir, 0700, TRUE) AND ! is_dir($dir)) { throw new RuntimeException('Не удалось создать хранилище детали.'); }
	}

	/** Название — необязательно: без него берём начало описания, а для фото без текста — нейтральную подпись. */
	public static function title_for($title, $prompt, $photo = NULL)
	{
		$title = trim((string) preg_replace('/\s+/u', ' ', (string) $title));
		if ($title !== '') { return mb_substr($title, 0, 200); }
		$text = trim((string) preg_replace('/\s+/u', ' ', (string) $prompt));
		if ($text === '') { return $photo ? 'Деталь по фото' : 'Новая деталь'; }
		if (mb_strlen($text) <= 80) { return $text; }
		$cut = mb_substr($text, 0, 80);
		$word = (string) preg_replace('/\s+\S*$/u', '', $cut);
		return ($word !== '' ? $word : $cut).'…';
	}

	/** Фотография остаётся вне public_html и не попадает в payload очереди. */
	public static function photo_upload(array $file)
	{
		if ( ! $file OR (int) Arr::get($file, 'error', UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return NULL; }
		if ((int) $file['error'] !== UPLOAD_ERR_OK OR ! is_uploaded_file($file['tmp_name'])) { throw new DomainException('Не удалось загрузить фото.'); }
		if (filesize($file['tmp_name']) > (int) self::config('max_photo_bytes')) { throw new DomainException('Фото должно быть не больше 5 МБ.'); }
		$info = @getimagesize($file['tmp_name']);
		$types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
		if ( ! $info OR ! isset($types[$info['mime']]) OR $info[0] * $info[1] > 24000000) { throw new DomainException('Нужен JPEG, PNG или WebP до 24 мегапикселей.'); }
		return ['bytes' => file_get_contents($file['tmp_name']), 'ext' => $types[$info['mime']]];
	}

	public static function create($uid, $title, $prompt, $photo = NULL, $remix = NULL)
	{
		if ($uid <= 0) { throw new DomainException('Нужен вход.'); }
		$title = self::title_for($title, $prompt, $photo);
		if (trim($prompt) === '' AND ! $photo AND ! $remix) { throw new DomainException('Опишите деталь или приложите фотографию.'); }
		if (mb_strlen($prompt) > 6000) { throw new DomainException('Описание: до 6000 знаков.'); }
		$db = Database::instance(); $db->begin(); $dir = '';
		try
		{
			self::row('SELECT pg_advisory_xact_lock(669,:u)', [':u' => (int) $uid]);
			$limits = self::limits($uid);
			if ((int) $limits['projects_used'] >= $limits['projects']) { throw new DomainException('Достигнут лимит проектов. Удалите ненужный проект.'); }
			$code = bin2hex(random_bytes(10));
			$p = self::row('INSERT INTO nj_669_solid3d_projects (code,user_id,title,summary,remix_of,photo) VALUES (:c,:u,:t,:s,:r,:ph) RETURNING *',
				[':c' => $code, ':u' => (int) $uid, ':t' => $title, ':s' => mb_substr($prompt, 0, 2000), ':r' => $remix ? (int) $remix['project_id'] : 0, ':ph' => $photo ? $photo['ext'] : '']);
			if ($photo)
			{
				$dir = self::dir($code); self::mkdir($dir);
				if (file_put_contents($dir.'/photo.'.$photo['ext'], $photo['bytes']) === FALSE) { throw new RuntimeException('Фото не сохранено.'); }
				chmod($dir.'/photo.'.$photo['ext'], 0600);
			}
			if ($remix)
			{
				// Копируем источник, не чужие приватные фото или файлы. Первое пересчитывание — по общей квоте.
				$r = self::row("INSERT INTO nj_669_solid3d_revisions (project_id,user_id,prompt,source,status,run_token,error)
					VALUES (:p,:u,'Использовать как шаблон',:s,'error',:token,'Шаблон скопирован. Нажмите «Пересчитать код», чтобы получить свои файлы.') RETURNING id",
					[':p' => $p['id'], ':u' => (int) $uid, ':s' => $remix['source'], ':token' => bin2hex(random_bytes(24))]);
				self::exec('UPDATE nj_669_solid3d_projects SET current_revision_id=:r WHERE id=:p', [':r' => $r['id'], ':p' => $p['id']]);
			}
			$db->commit(); return self::project($code);
		}
		catch (Throwable $e)
		{
			$db->rollback();
			if ($dir !== '' AND is_dir($dir)) { foreach (glob($dir.'/*') as $f) { unlink($f); } rmdir($dir); }
			throw $e;
		}
	}

	/** Операция с кодом тоже платная: никакого обхода суточного лимита через повторный рендер. */
	/**
	 * Любое изменение детали снимает её с каталога и с модерации: опубликованная версия остаётся
	 * в истории и у владельца, а в каталоге ничего не меняется, пока деталь не отправят заново.
	 * Вызывается внутри транзакции того действия, которое меняет деталь.
	 */
	public static function withdraw($p, $uid, $why)
	{
		if ( ! in_array($p['publication'], ['published', 'review'], TRUE)) { return FALSE; }
		self::exec("UPDATE nj_669_solid3d_projects SET publication='draft',published_revision_id=0,review_revision_id=0,updated_at=now() WHERE id=:p", [':p' => $p['id']]);
		self::exec('INSERT INTO nj_669_solid3d_events (project_id,user_id,kind,note) VALUES (:p,:u,:k,:n)',
			[':p' => $p['id'], ':u' => (int) $uid, ':k' => 'withdraw', ':n' => mb_substr((string) $why, 0, 1000)]);
		return TRUE;
	}

	/** Показ: дата в Москве, «12.10.2026 14:05». Пусто, если строку не разобрать. */
	public static function when($value)
	{
		if ((string) $value === '') { return ''; }
		try { return (new DateTime((string) $value))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i'); }
		catch (Exception $e) { return ''; }
	}

	/** Целое с пробелом-разделителем разрядов: 12 345. */
	public static function fmt_int($n)
	{
		return number_format((int) $n, 0, ',', "\u{00A0}");
	}

	/** Расход для показа: «вход → выход» или «—», если модель не вызывалась. */
	public static function tokens_text($in, $out)
	{
		if ((int) $in + (int) $out === 0) { return '—'; }
		return self::fmt_int($in).' → '.self::fmt_int($out);
	}

	/** Токены LLM из тела воркера: только неотрицательные целые, не больше миллиарда. */
	public static function tokens_of(array $body)
	{
		$t = (array) Arr::get($body, 'tokens', []);
		return [':ti' => max(0, min(1000000000, (int) Arr::get($t, 'in', 0))), ':tout' => max(0, min(1000000000, (int) Arr::get($t, 'out', 0)))];
	}

	public static function queue($code, $uid, $prompt, $source = NULL)
	{
		$prompt = trim($prompt);
		if ($prompt === '' OR mb_strlen($prompt) > 6000) { throw new DomainException('Запрос: от 1 до 6000 знаков.'); }
		if ($source !== NULL) { self::validate_source($source); }
		$db = Database::instance(); $db->begin();
		try
		{
			self::row('SELECT pg_advisory_xact_lock(669,:u)', [':u' => (int) $uid]);
			$p = self::project($code, TRUE);
			if ( ! $p OR (int) $p['user_id'] !== (int) $uid) { throw new DomainException('Нет доступа к проекту.'); }
			self::expire($p['id'], TRUE);
			if (self::row("SELECT id FROM nj_669_solid3d_revisions WHERE project_id=:p AND status IN ('queued','running')", [':p' => $p['id']])) { throw new DomainException('Дождитесь завершения текущей задачи.'); }
			// Квота — за запуски с языковой моделью. Ручной пересчёт кода (source задан) модели не вызывает.
			$llm = $source === NULL;
			if ($llm)
			{
				$limits = self::limits($uid);
				if ((int) $limits['runs_used'] >= $limits['daily_runs']) { throw new DomainException('Исчерпан суточный лимит запусков.'); }
			}
			self::withdraw($p, $uid, $llm ? 'Новая версия: деталь снята с каталога.' : 'Новый код: деталь снята с каталога.');
			$s = self::settings();
			$r = self::row('INSERT INTO nj_669_solid3d_revisions (project_id,user_id,parent_id,prompt,source,run_token,model,vision_model,llm_run)
				VALUES (:p,:u,:parent,:prompt,:source,:token,:model,:vision,CAST(:llm AS BOOLEAN)) RETURNING *', [
				':p' => $p['id'], ':u' => (int) $uid, ':parent' => $p['current_revision_id'], ':prompt' => $prompt,
				':source' => $source === NULL ? '' : $source, ':token' => bin2hex(random_bytes(24)),
				':model' => $s['model'], ':vision' => $s['vision_model'], ':llm' => $llm ? 'true' : 'false']);
			$job = Service_Nj120_JobQueue::push('nj669.generate', [
				'revision_id' => (int) $r['id'], 'run_token' => $r['run_token'], 'code_only' => $source !== NULL,
				'repairs' => max(0, min(2, (int) $s['repairs'])),
			], ['module' => 'nj669', 'created_by' => (int) $uid, 'visitor_id' => 'u:'.(int) $uid,
				'max_attempts' => 1, 'timeout_sec' => (int) self::config('timeout_sec')]);
			if ( ! $job) { throw new RuntimeException('Очередь не приняла задачу.'); }
			self::exec('UPDATE nj_669_solid3d_revisions SET job_id=:j WHERE id=:r', [':j' => (int) $job, ':r' => $r['id']]);
			self::exec('UPDATE nj_669_solid3d_projects SET updated_at=now() WHERE id=:p', [':p' => $p['id']]);
			$db->commit(); return (int) $r['id'];
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
	}

	/**
	 * Ползунки: новые размеры ТЕКУЩЕЙ версии. Ревизия не создаётся: строка остаётся той же,
	 * меняются только params/files после приёмки. Код и значения ждут в pending_* до сборки.
	 * Квоту не тратит: модель не вызывается, только сборка. Одна сборка на проект за раз — через блокировку и статус очереди.
	 */
	public static function resize($code, $uid, $rid, array $values)
	{
		if ((int) $uid <= 0) { throw new DomainException('Нет доступа к проекту.'); }
		$db = Database::instance(); $db->begin();
		try
		{
			self::row('SELECT pg_advisory_xact_lock(669,:u)', [':u' => (int) $uid]);
			$p = self::project($code, TRUE);
			if ( ! $p OR (int) $p['user_id'] !== (int) $uid) { throw new DomainException('Нет доступа к проекту.'); }
			self::expire($p['id'], TRUE);
			if (self::row("SELECT id FROM nj_669_solid3d_revisions WHERE project_id=:p AND status IN ('queued','running')", [':p' => $p['id']])) { throw new DomainException('Дождитесь завершения текущей задачи.'); }
			$r = self::revision($rid, $p['id']);
			if ( ! $r OR $r['status'] !== 'done' OR (int) $r['id'] !== (int) $p['current_revision_id']) { throw new DomainException('Размеры меняются только у текущей версии.'); }
			if (in_array($r['vlm_status'], ['queued', 'running'], TRUE)) { throw new DomainException('Дождитесь окончания проверки ракурсов.'); }
			$source = Service_Nj669_Parameters::apply($r['source'], $values);
			self::validate_source($source);
			$current = Service_Nj669_Parameters::parse($r['source']);
			$stored = Service_Nj669_Parameters::normalize($r['source'], $values);
			$changed = FALSE;
			foreach ($stored as $name => $value) { if (isset($current[$name]) AND $current[$name]['value'] !== $value) { $changed = TRUE; } }
			if ( ! $changed) { throw new DomainException('Размеры не изменились.'); }
			self::withdraw($p, $uid, 'Изменены размеры: деталь снята с каталога.');
			$token = bin2hex(random_bytes(24));
			$job = Service_Nj120_JobQueue::push('nj669.generate', [
				'revision_id' => (int) $r['id'], 'run_token' => $token, 'code_only' => TRUE, 'repairs' => 0,
			], ['module' => 'nj669', 'created_by' => (int) $uid, 'visitor_id' => 'u:'.(int) $uid,
				'max_attempts' => 1, 'timeout_sec' => (int) self::config('timeout_sec')]);
			if ( ! $job) { throw new RuntimeException('Очередь не приняла задачу.'); }
			self::exec("UPDATE nj_669_solid3d_revisions SET status='queued',run_token=:t,job_id=:j,error='',build_log='',queued_at=now(),
				pending_source=:src,pending_params=CAST(:pp AS jsonb) WHERE id=:r",
				[':t' => $token, ':j' => (int) $job, ':src' => $source, ':pp' => json_encode($stored), ':r' => (int) $r['id']]);
			self::exec('INSERT INTO nj_669_solid3d_events (project_id,user_id,kind,note) VALUES (:p,:u,:k,:n)',
				[':p' => $p['id'], ':u' => (int) $uid, ':k' => 'resize', ':n' => 'Размеры версии №'.(int) $r['id']]);
			self::exec('UPDATE nj_669_solid3d_projects SET updated_at=now() WHERE id=:p', [':p' => $p['id']]);
			$db->commit(); return (int) $r['id'];
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
	}

	/** Текст ошибки для показа: не-модератору не видны движок, код и подсказки компилятора. */
	public static function public_error($text, $moderator = FALSE)
	{
		$text = trim((string) $text);
		if ($text === '' OR $moderator) { return $text; }
		if (preg_match('/openscad|scad|parser|error|warning|syntax|line \d+/iu', $text))
		{
			return 'Модель не собралась. Измените описание или размеры и повторите.';
		}
		return $text;
	}

	/**
	 * ИИ-проверка ракурсов — отдельная задача канала nj669.verify по запросу администратора.
	 * Не расходует суточную квоту владельца и не меняет текущую версию: пишет только вердикт.
	 */
	public static function queue_verify($code, $uid, $rid, $moderator)
	{
		if ( ! $moderator) { throw new DomainException('Проверку запускает администратор.'); }
		$db = Database::instance(); $db->begin();
		try
		{
			$p = self::project($code, TRUE);
			if ( ! $p) { throw new DomainException('Проект не найден.'); }
			self::expire($p['id'], TRUE);
			$r = self::revision((int) $rid, $p['id']);
			if ( ! $r OR $r['status'] !== 'done') { throw new DomainException('Проверять можно только готовую версию.'); }
			if (in_array($r['vlm_status'], ['queued', 'running'], TRUE)) { throw new DomainException('Проверка этой версии уже выполняется.'); }
			foreach (['png', 'png2', 'png3'] as $kind)
			{
				if ( ! is_file(self::file($p['code'], $r['id'], $kind))) { throw new DomainException('У версии нет трёх ракурсов для проверки. Пересоберите деталь.'); }
			}
			$token = bin2hex(random_bytes(24));
			$job = Service_Nj120_JobQueue::push('nj669.verify', ['revision_id' => (int) $r['id'], 'run_token' => $token],
				['module' => 'nj669', 'created_by' => (int) $uid, 'visitor_id' => 'u:'.(int) $uid, 'max_attempts' => 1,
				'timeout_sec' => (int) self::config('verify_timeout_sec')]);
			if ( ! $job) { throw new RuntimeException('Очередь не приняла задачу.'); }
			self::exec("UPDATE nj_669_solid3d_revisions SET vlm_status='queued',vlm_run_token=:t,vlm_job_id=:j,vlm_error='',vlm_started_at=now(),vlm_by=:u WHERE id=:r",
				[':t' => $token, ':j' => (int) $job, ':u' => (int) $uid, ':r' => $r['id']]);
			self::exec('INSERT INTO nj_669_solid3d_events (project_id,user_id,kind,note) VALUES (:p,:u,:k,:n)',
				[':p' => $p['id'], ':u' => (int) $uid, ':k' => 'vlm', ':n' => 'Проверка ракурсов версии №'.(int) $r['id']]);
			$db->commit(); return (int) $r['id'];
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
	}

	/** Фото для воркера: только из хранилища проекта, никогда из payload очереди. */
	protected static function photo_for_worker(array $p)
	{
		if ( ! in_array($p['photo'], ['jpg', 'png', 'webp'], TRUE)) { return NULL; }
		$file = self::dir($p['code']).'/photo.'.$p['photo'];
		if ( ! is_file($file)) { return NULL; }
		return ['b64' => base64_encode(file_get_contents($file)), 'media_type' => 'image/'.($p['photo'] === 'jpg' ? 'jpeg' : $p['photo'])];
	}

	/** Приёмная проверки: свой токен версии (vlm_run_token), файлы не загружаются, геометрия не меняется. */
	public static function verify_worker(array $body, $action)
	{
		$rid = (int) Arr::get($body, 'revision_id', 0);
		$token = (string) Arr::get($body, 'run_token', '');
		$ref = self::row('SELECT p.code FROM nj_669_solid3d_projects p JOIN nj_669_solid3d_revisions r ON r.project_id=p.id WHERE r.id=:r AND p.deleted_at IS NULL', [':r' => $rid]);
		if ( ! $ref) { throw new DomainException('Версия не найдена.'); }
		$db = Database::instance(); $db->begin();
		try
		{
			$p = self::project($ref['code'], TRUE);
			if ( ! $p) { throw new DomainException('Проект удалён.'); }
			self::expire($p['id'], TRUE);
			$r = self::revision($rid, $p['id']);
			if ( ! $r OR $token === '' OR $r['vlm_run_token'] === '' OR ! hash_equals($r['vlm_run_token'], $token)) { throw new DomainException('Токен проверки не совпал.'); }
			$closed = in_array($r['vlm_status'], ['done', 'error'], TRUE);
			if ($action === 'verify_context')
			{
				if ($closed) { throw new DomainException('Проверка уже закрыта.'); }
				self::exec("UPDATE nj_669_solid3d_revisions SET vlm_status='running' WHERE id=:r AND vlm_status='queued'", [':r' => $rid]);
				$views = [];
				foreach (['png', 'png2', 'png3'] as $kind)
				{
					$file = self::file($p['code'], $rid, $kind);
					if ( ! is_file($file)) { throw new DomainException('Нет ракурсов для проверки. Пересоберите деталь.'); }
					$views[] = base64_encode((string) file_get_contents($file));
				}
				$db->commit();
				return ['ok' => TRUE, 'description' => $p['summary'], 'prompt' => $r['prompt'], 'source' => $r['source'],
					'vision_model' => $r['vision_model'], 'views' => $views, 'photo' => self::photo_for_worker($p)];
			}
			// Повтор уже закрытой проверки (например, после сетевого сбоя) — идемпотентно.
			if ($closed) { $db->commit(); return ['ok' => TRUE, 'already_closed' => TRUE]; }
			if ($action === 'verify_result')
			{
				self::exec("UPDATE nj_669_solid3d_revisions SET vlm_status='done',visual_ok=:ok,review=:review,vlm_checked_at=now(),vlm_error='',vlm_tokens_in=:ti,vlm_tokens_out=:tout WHERE id=:r", self::tokens_of($body) + [
					':ok' => Arr::get($body, 'visual_ok') === TRUE ? 'true' : 'false',
					':review' => mb_substr((string) Arr::get($body, 'review', ''), 0, 2000), ':r' => $rid]);
			}
			elseif ($action === 'verify_fail')
			{
				self::exec("UPDATE nj_669_solid3d_revisions SET vlm_status='error',vlm_error=:e,vlm_tokens_in=:ti,vlm_tokens_out=:tout WHERE id=:r", self::tokens_of($body) + [
					':e' => mb_substr((string) Arr::get($body, 'error', 'Ошибка проверки'), 0, 1000), ':r' => $rid]);
			}
			else { throw new DomainException('Неизвестная операция проверки.'); }
			$db->commit();
			return ['ok' => TRUE];
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
	}

	public static function validate_source($source)
	{
		if (trim($source) === '' OR strlen($source) > 60000) { throw new DomainException('Код модели должен занимать от 1 до 60 000 байт.'); }
		$plain = preg_replace('~"(?:\\\\.|[^"\\\\])*"|/\*.*?\*/|//[^\r\n]*~s', ' ', $source);
		if (preg_match('/\b(include|use|import|surface)\b/i', $plain)) { throw new DomainException('Внешние файлы, include/use, import и surface запрещены. Деталь должна быть самодостаточной.'); }
	}

	/** Без ручного SQL в чужую очередь: лишь собственный дедлайн, включая смерть процесса. */
	public static function expire($pid, $locked = FALSE)
	{
		$db = Database::instance();
		if ( ! $locked) { $db->begin(); }
		try
		{
			$p = self::row('SELECT code FROM nj_669_solid3d_projects WHERE id=:p FOR UPDATE', [':p' => (int) $pid]);
			// Пересчёт размеров (pending_source не NULL) возвращает версию в done: прежние файлы остаются.
			$expired = self::rows("UPDATE nj_669_solid3d_revisions SET status=CASE WHEN pending_source IS NULL THEN 'error' ELSE 'done' END,
				error=CASE WHEN pending_source IS NULL THEN 'Истекло время задачи. Последняя удачная версия сохранена.' ELSE 'Пересчёт не завершился за отведённое время. Прежние размеры сохранены.' END,
				finished_at=now()
				WHERE project_id=:p AND status IN ('queued','running') AND queued_at < now() - interval '35 minutes' RETURNING id, pending_source", [':p' => (int) $pid]);
			self::exec("UPDATE nj_669_solid3d_revisions SET vlm_status='error',vlm_error='Проверка не завершилась за отведённое время. Запустите её снова.'
				WHERE project_id=:p AND vlm_status IN ('queued','running') AND vlm_started_at < now() - interval '15 minutes'", [':p' => (int) $pid]);
			if ($p)
			{
				foreach ($expired as $r)
				{
					if ($r['pending_source'] !== NULL) { continue; }
					foreach (['stl','glb','png','png2','png3','scad'] as $kind)
					{
						$file = self::file($p['code'], $r['id'], $kind);
						if (is_file($file)) { unlink($file); }
					}
				}
			}
			if ( ! $locked) { $db->commit(); }
		}
		catch (Throwable $e) { if ( ! $locked) { $db->rollback(); } throw $e; }
	}

	public static function revisions($pid, $page = 1)
	{
		self::expire($pid);
		return self::rows('SELECT id,parent_id,prompt,status,job_id,visual_ok,review,error,build_log,attempts,model,vision_model,created_at,queued_at,label,milestone,vlm_status,vlm_error,vlm_checked_at,params,tokens_in,tokens_out,vlm_tokens_in,vlm_tokens_out,(pending_source IS NOT NULL) AS resizing FROM nj_669_solid3d_revisions WHERE project_id=:p ORDER BY id DESC LIMIT 100 OFFSET '.(max(0, min(1000, (int) $page - 1)) * 100), [':p' => (int) $pid]);
	}

	public static function change($code, $uid, $moderator, $action, array $data)
	{
		$db = Database::instance(); $db->begin(); $remove = FALSE; $remove_revision = NULL;
		try
		{
			$p = self::project($code, TRUE);
			if ( ! $p) { throw new DomainException('Проект не найден.'); }
			$owner = ((int) $uid > 0 AND (int) $p['user_id'] === (int) $uid);
			if ( ! $owner AND ! ($moderator AND in_array($action, ['publish','reject','unpublish','delete','delete_revision'], TRUE))) { throw new DomainException('Нет доступа.'); }
			$id = (int) $p['id']; $params = [':p' => $id];
			if ($action === 'restore')
			{
				self::expire($id, TRUE);
				if (self::row("SELECT id FROM nj_669_solid3d_revisions WHERE project_id=:p AND status IN ('queued','running')", $params)) { throw new DomainException('Дождитесь завершения текущей задачи.'); }
				$r = self::revision((int) Arr::get($data, 'revision', 0), $id);
				if ( ! $r OR $r['status'] !== 'done') { throw new DomainException('Можно восстановить только готовую версию.'); }
				self::exec('UPDATE nj_669_solid3d_projects SET current_revision_id=:r,updated_at=now() WHERE id=:p', $params + [':r' => $r['id']]);
				self::withdraw($p, $uid, 'Восстановлена другая версия: деталь снята с каталога.');
			}
			elseif ($action === 'submit')
			{
				$r = self::revision($p['current_revision_id'], $id);
				if ( ! $r OR $r['status'] !== 'done') { throw new DomainException('Сначала получите готовую модель.'); }
				self::exec("UPDATE nj_669_solid3d_projects SET publication='review',review_revision_id=:r,review_meta=CAST(:meta AS jsonb),moderation_note='',updated_at=now() WHERE id=:p", $params + [':r' => $r['id'], ':meta' => json_encode(Service_Nj669_Workspace::metadata($p), JSON_UNESCAPED_UNICODE)]);
			}
			elseif ($action === 'publish' OR $action === 'reject')
			{
				if ( ! $moderator OR $p['publication'] !== 'review') { throw new DomainException('Нет версии на модерации.'); }
				$r = self::revision($p['review_revision_id'], $id);
				if ( ! $r OR $r['status'] !== 'done') { throw new DomainException('Версия не готова.'); }
				$note = mb_substr(trim((string) Arr::get($data, 'note', '')), 0, 1000);
				if ($action === 'reject' AND $note === '') { throw new DomainException('Укажите причину отклонения.'); }
				self::exec('UPDATE nj_669_solid3d_projects SET publication=:s,published_revision_id=:r,published_meta=CAST(:meta AS jsonb),moderation_note=:n,updated_at=now() WHERE id=:p',
					$params + [':s' => $action === 'publish' ? 'published' : 'rejected', ':r' => $action === 'publish' ? (int) $p['review_revision_id'] : (int) $p['published_revision_id'], ':n' => $note, ':meta' => $action === 'publish' ? $p['review_meta'] : $p['published_meta']]);
			}
			elseif ($action === 'unpublish')
			{
				self::exec("UPDATE nj_669_solid3d_projects SET publication='draft',published_revision_id=0,review_revision_id=0,updated_at=now() WHERE id=:p", $params);
			}
			elseif ($action === 'delete_revision')
			{
				if ((string) Arr::get($data, 'armed', '') !== '1') { throw new DomainException('Подтвердите удаление версии повторным нажатием.'); }
				$r = self::revision((int) Arr::get($data, 'revision', 0), $id);
				if ( ! $r) { throw new DomainException('Версия не найдена.'); }
				if (in_array($r['status'], ['queued','running'], TRUE)) { throw new DomainException('Версия собирается. Дождитесь результата или остановите задачу.'); }
				if ((int) $r['id'] === (int) $p['current_revision_id']) { throw new DomainException('Текущую версию удалить нельзя. Сначала восстановите другую.'); }
				if (in_array((int) $r['id'], [(int) $p['published_revision_id'], (int) $p['review_revision_id']], TRUE)) { throw new DomainException('Опубликованную или проверяемую версию удалить нельзя. Снимите её с каталога или отклоните.'); }
				if (in_array($r['vlm_status'], ['queued','running'], TRUE)) { throw new DomainException('Идёт проверка ракурсов этой версии.'); }
				self::exec('DELETE FROM nj_669_solid3d_revisions WHERE id=:r AND project_id=:p', [':r' => (int) $r['id'], ':p' => $id]);
				$remove_revision = [$p['code'], (int) $r['id']];
			}
			elseif ($action === 'delete')
			{
				if ((string) Arr::get($data, 'armed', '') !== '1') { throw new DomainException('Подтвердите удаление повторным нажатием.'); }
				self::exec('UPDATE nj_669_solid3d_projects SET deleted_at=now(),published_revision_id=0,review_revision_id=0 WHERE id=:p', $params);
				self::exec("UPDATE nj_669_solid3d_revisions SET status=CASE WHEN status IN ('queued','running') THEN 'error' ELSE status END,
					source='',run_token='',error='Проект удалён' WHERE project_id=:p", $params);
				$remove = TRUE;
			}
			else { throw new DomainException('Неизвестное действие.'); }
			self::exec('INSERT INTO nj_669_solid3d_events (project_id,user_id,kind,note) VALUES (:p,:u,:k,:n)',
				$params + [':u' => (int) $uid, ':k' => $action, ':n' => mb_substr((string) Arr::get($data, 'note', ''), 0, 1000)]);
			$db->commit();
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
		if ($remove_revision !== NULL) { Service_Nj669_Workspace::clear_files($remove_revision[0], $remove_revision[1]); }
		if ($remove)
		{
			$dir = self::dir($code);
			if (is_dir($dir)) { foreach (glob($dir.'/*') as $f) { if (is_file($f)) { unlink($f); } } rmdir($dir); }
		}
	}

	/** Итоги токенов: генерация версий, ИИ-проверка и помощник (идеи, карточка). Фильтры списка не влияют. */
	public static function usage_totals($uid = NULL)
	{
		$params = $uid === NULL ? [] : [':u' => (int) $uid];
		$rev = self::row('SELECT COUNT(*) AS runs, COALESCE(SUM(r.tokens_in),0) AS tokens_in, COALESCE(SUM(r.tokens_out),0) AS tokens_out,
			COALESCE(SUM(r.vlm_tokens_in),0) AS vlm_tokens_in, COALESCE(SUM(r.vlm_tokens_out),0) AS vlm_tokens_out
			FROM nj_669_solid3d_revisions r JOIN nj_669_solid3d_projects p ON p.id=r.project_id
			WHERE p.deleted_at IS NULL'.($uid === NULL ? '' : ' AND r.user_id=:u'), $params);
		$task = self::row('SELECT COUNT(*) AS runs, COALESCE(SUM(tokens_in),0) AS tokens_in, COALESCE(SUM(tokens_out),0) AS tokens_out
			FROM nj_669_solid3d_tasks'.($uid === NULL ? '' : ' WHERE user_id=:u'), $params);
		$int = function ($row, $key) { return (int) ($row[$key] ?? 0); };
		return ['runs' => $int($rev, 'runs'), 'tokens_in' => $int($rev, 'tokens_in'), 'tokens_out' => $int($rev, 'tokens_out'),
			'vlm_tokens_in' => $int($rev, 'vlm_tokens_in'), 'vlm_tokens_out' => $int($rev, 'vlm_tokens_out'),
			'assist_runs' => $int($task, 'runs'), 'assist_tokens_in' => $int($task, 'tokens_in'), 'assist_tokens_out' => $int($task, 'tokens_out')];
	}

	public static function usage($uid = NULL, $search = '', $status = '', $page = 1)
	{
		$params = $uid === NULL ? [] : [':u' => (int) $uid];
		$where = 'p.deleted_at IS NULL'.($uid === NULL ? '' : ' AND r.user_id=:u');
		if ($search !== '') { $where .= ' AND (p.title ILIKE :q OR r.prompt ILIKE :q)'; $params[':q'] = '%'.mb_substr($search, 0, 100).'%'; }
		if (in_array($status, ['queued','running','done','error'], TRUE)) { $where .= ' AND r.status=:s'; $params[':s'] = $status; }
		return self::rows('SELECT r.id,r.project_id,r.prompt,r.status,r.job_id,r.model,r.vision_model,r.attempts,r.error,r.created_at,r.vlm_status,r.visual_ok,r.tokens_in,r.tokens_out,r.vlm_tokens_in,r.vlm_tokens_out,p.code,p.title
			FROM nj_669_solid3d_revisions r JOIN nj_669_solid3d_projects p ON p.id=r.project_id WHERE '.$where.' ORDER BY r.id DESC LIMIT 24 OFFSET '.(max(0, min(10000, (int) $page - 1)) * 24), $params);
	}

	/** Подпись и длина до записи на диск; STL только строгий binary, GLB v2. */
	public static function validate_file($kind, $raw)
	{
		if ( ! is_string($raw) OR $raw === '' OR strlen($raw) > (int) self::config('max_file_bytes')) { throw new DomainException('Нет корректного файла '.$kind); }
		if ($kind === 'glb')
		{
			if (strlen($raw) < 20 OR substr($raw, 0, 8) !== "glTF\x02\x00\x00\x00" OR unpack('V', substr($raw, 8, 4))[1] !== strlen($raw)) { throw new DomainException('Повреждён GLB.'); }
			$chunk = unpack('Vlength/Vtype', substr($raw, 12, 8));
			$bin = 20 + $chunk['length'];
			if ($chunk['type'] !== 0x4e4f534a OR $chunk['length'] % 4 !== 0 OR $bin + 8 > strlen($raw)) { throw new DomainException('Повреждён JSON-блок GLB.'); }
			$doc = json_decode(substr($raw, 20, $chunk['length']), TRUE);
			$binary = unpack('Vlength/Vtype', substr($raw, $bin, 8));
			if ( ! is_array($doc) OR Arr::path($doc, 'asset.version') !== '2.0'
				OR count((array) Arr::get($doc, 'buffers', [])) !== 1 OR isset($doc['buffers'][0]['uri'])
				OR count((array) Arr::get($doc, 'meshes', [])) !== 1
				OR $binary['type'] !== 0x004e4942 OR $bin + 8 + $binary['length'] !== strlen($raw)
				OR (int) Arr::path($doc, 'buffers.0.byteLength', 0) > $binary['length']) { throw new DomainException('GLB должен содержать одну встроенную сетку.'); }
			$primitives = (array) Arr::path($doc, 'meshes.0.primitives', []);
			if (count($primitives) < 1 OR count($primitives) > 24) { throw new DomainException('Недопустимое число материалов GLB.'); }
			foreach ($primitives as $primitive)
			{
				$index = Arr::path((array) $primitive, 'attributes.POSITION');
				$accessor = $index === NULL ? [] : Arr::path($doc, 'accessors.'.$index, []);
				if (Arr::get($accessor, 'type') !== 'VEC3' OR (int) Arr::get($accessor, 'count', 0) < 3
					OR (int) Arr::get($accessor, 'count', 0) > 12000000) { throw new DomainException('Некорректные вершины GLB.'); }
			}
		}
		elseif ($kind === 'stl')
		{
			if (strlen($raw) < 134 OR 84 + 50 * unpack('V', substr($raw, 80, 4))[1] !== strlen($raw)) { throw new DomainException('Повреждён STL.'); }
		}
		elseif (in_array($kind, ['png','png2','png3'], TRUE))
		{
			$info = @getimagesizefromstring($raw);
			if ( ! $info OR $info[2] !== IMAGETYPE_PNG OR $info[0] > 2048 OR $info[1] > 2048) { throw new DomainException('Повреждено превью.'); }
		}
		else { throw new DomainException('Неизвестный формат.'); }
	}

	/** Приёмная закрыта токеном очереди И одноразовым токеном версии; повтор завершённой приёмки идемпотентен. */
	public static function worker(array $body, $action)
	{
		$rid = (int) Arr::get($body, 'revision_id', 0);
		$token = (string) Arr::get($body, 'run_token', '');
		$ref = self::row('SELECT p.code FROM nj_669_solid3d_projects p JOIN nj_669_solid3d_revisions r ON r.project_id=p.id WHERE r.id=:r AND p.deleted_at IS NULL', [':r' => $rid]);
		if ( ! $ref) { throw new DomainException('Версия не найдена.'); }
		$db = Database::instance(); $db->begin(); $written = [];
		try
		{
			$p = self::project($ref['code'], TRUE);
			if ( ! $p) { throw new DomainException('Проект удалён.'); }
			self::expire($p['id'], TRUE);
			$r = self::revision($rid, $p['id']);
			if ($token === '' OR ! hash_equals($r['run_token'], $token)) { throw new DomainException('Токен версии не совпал.'); }
			if ($r['status'] === 'done') { $db->commit(); return ['ok' => TRUE, 'already_done' => TRUE]; }
			if ($r['status'] === 'error' AND $action === 'fail') { $db->commit(); return ['ok' => TRUE, 'already_failed' => TRUE]; }
			if ( ! in_array($r['status'], ['queued','running'], TRUE)) { throw new DomainException('Версия уже закрыта.'); }
			$params = [':r' => $rid];
			if ($action === 'context')
			{
				self::exec("UPDATE nj_669_solid3d_revisions SET status='running' WHERE id=:r", $params);
				$parent = self::revision($r['parent_id'], $p['id']);
				$photo = self::photo_for_worker($p);
				$db->commit();
				// Пересчёт размеров собирает код из pending_source; обычная версия — свой source или родителя.
				$source = $r['pending_source'] !== NULL ? $r['pending_source'] : ($r['source'] !== '' ? $r['source'] : ($parent ? $parent['source'] : ''));
				return ['ok' => TRUE, 'title' => $p['title'], 'description' => $p['summary'], 'prompt' => $r['prompt'],
					'source' => $source, 'resize' => $r['pending_source'] !== NULL,
					'model' => $r['model'], 'vision_model' => $r['vision_model'], 'photo' => $photo];
			}
			if ($action === 'fail')
			{
				$message = mb_substr((string) Arr::get($body, 'error', 'Ошибка воркера'), 0, 1000);
				$log = mb_substr((string) Arr::get($body, 'detail', ''), 0, 1500);
				if ($r['pending_source'] !== NULL)
				{
					// Пересчёт размеров: прежняя модель и её файлы остаются, ошибка видна у текущей версии.
					self::exec("UPDATE nj_669_solid3d_revisions SET status='done',error=:e,build_log=:l,pending_source=NULL,pending_params=NULL,finished_at=now() WHERE id=:r", $params + [':e' => $message, ':l' => $log]);
				}
				else
				{
					foreach (['stl','glb','png','png2','png3','scad'] as $kind)
					{
						$file = self::file($p['code'], $rid, $kind);
						if (is_file($file)) { unlink($file); }
					}
					self::exec("UPDATE nj_669_solid3d_revisions SET status='error',error=:e,build_log=:l,finished_at=now(),tokens_in=:ti,tokens_out=:tout WHERE id=:r", $params + [':e' => $message, ':l' => $log] + self::tokens_of($body));
				}
			}
			elseif ($action === 'upload')
			{
				$kind = (string) Arr::get($body, 'kind', '');
				$file = (array) Arr::get($_FILES, 'file', []);
				if ( ! in_array($kind, ['glb','stl','png','png2','png3'], TRUE) OR (int) Arr::get($file, 'error', -1) !== UPLOAD_ERR_OK
					OR ! is_uploaded_file($file['tmp_name']) OR filesize($file['tmp_name']) > (int) self::config('max_file_bytes')) { throw new DomainException('Некорректная загрузка файла.'); }
				$raw = file_get_contents($file['tmp_name']); self::validate_file($kind, $raw);
				self::mkdir(self::dir($p['code']));
				$path = self::file($p['code'], $rid, $kind); $written[] = $path;
				if (file_put_contents($path, $raw, LOCK_EX) !== strlen($raw)) { throw new RuntimeException('Файл не сохранён.'); }
				chmod($path, 0600);
			}
			elseif ($action === 'result')
			{
				$source = (string) Arr::get($body, 'source', ''); self::validate_source($source);
				$sizes = []; $bytes = [];
				foreach (['stl','glb','png','png2','png3'] as $kind)
				{
					$file = self::file($p['code'], $rid, $kind);
					if (in_array($kind, ['png2','png3'], TRUE) AND ! is_file($file)) { continue; }
					if ( ! is_file($file) OR filesize($file) > (int) self::config('max_file_bytes')) { throw new DomainException('Нет загруженного файла '.$kind); }
					$raw = file_get_contents($file); self::validate_file($kind, $raw);
					$sizes[$kind] = strlen($raw);
				}
				$bytes['scad'] = $source;
				self::mkdir(self::dir($p['code']));
				foreach ($bytes as $kind => $raw)
				{
					$file = self::file($p['code'], $rid, $kind); $written[] = $file;
					if (file_put_contents($file, $raw, LOCK_EX) !== strlen($raw)) { throw new RuntimeException('Файл не сохранён.'); }
					chmod($file, 0600);
				}
				// Новая версия и пересчёт размеров проходят одну ветку: у новой строки pending_* пусты,
				// params = {} означает «значения из кода». VLM-вердикт относился к прежней геометрии — сбрасываем.
				$tokens = $r['pending_source'] === NULL ? self::tokens_of($body) : [':ti' => (int) $r['tokens_in'], ':tout' => (int) $r['tokens_out']];
				self::exec("UPDATE nj_669_solid3d_revisions SET status='done',source=:s,attempts=:a,files=CAST(:f AS jsonb),params=CAST(:pa AS jsonb),
					error='',build_log='',visual_ok=FALSE,review='',vlm_status='none',vlm_error='',vlm_checked_at=NULL,
					pending_source=NULL,pending_params=NULL,finished_at=now(),tokens_in=:ti,tokens_out=:tout WHERE id=:r", $params + $tokens + [
					':s' => $source, ':a' => max(1, min(3, (int) Arr::get($body, 'attempts', 1))), ':f' => json_encode($sizes),
					':pa' => $r['pending_params'] !== NULL ? $r['pending_params'] : '{}']);
				self::exec('UPDATE nj_669_solid3d_projects SET current_revision_id=:r,updated_at=now() WHERE id=:p', $params + [':p' => $p['id']]);
			}
			else { throw new DomainException('Неизвестная операция воркера.'); }
			$db->commit();
			if (in_array($action, ['result','fail'], TRUE)) { Service_Nj669_Workspace::notify($p, $rid, $action === 'result', (string) Arr::get($body, 'error', '')); }
			// Первая удачная сборка: карточка подбирается автоматически (отдельная задача, своя квота).
			if ($action === 'result') { Service_Nj669_Assist::auto_caption($p['code'], (int) $p['user_id']); }
			return ['ok' => TRUE];
		}
		catch (Throwable $e) { $db->rollback(); foreach ($written as $f) { if (is_file($f)) { unlink($f); } } throw $e; }
	}
}
