<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Временный STL под свои размеры.
 *
 * Любой залогиненный пользователь может собрать файл из копии кода версии с его значениями ползунков.
 * Сборка не меняет версию, каталог и историю, квоту и LLM не использует. Файл живёт час после готовности
 * и удаляется чисткой (purge) вместе со строкой таблицы nj_669_solid3d_exports.
 */
class Service_Nj669_Export
{
	const LIFETIME = "interval '1 hour'";
	const STALE = "interval '20 minutes'";

	/** Постановка сборки. Одна активная сборка на пользователя и проект. */
	public static function queue($code, $uid, $rid, array $values, $moderator = FALSE)
	{
		if ((int) $uid <= 0) { throw new DomainException('Войдите, чтобы собрать STL под свои размеры.'); }
		$db = Database::instance(); $db->begin();
		try
		{
			self::row('SELECT pg_advisory_xact_lock(669,:u)', [':u' => (int) $uid]);
			$p = Service_Nj669_Solid3d::project($code, TRUE);
			if ( ! $p OR ! Service_Nj669_Solid3d::can_read_revision($p, $rid, $uid, $moderator)) { throw new DomainException('Деталь не найдена.'); }
			self::purge();
			if (self::row("SELECT id FROM nj_669_solid3d_exports WHERE project_id=:p AND user_id=:u AND status IN ('queued','running') LIMIT 1", [':p' => $p['id'], ':u' => (int) $uid]))
			{
				throw new DomainException('Прошлый STL ещё собирается. Дождитесь готовности.');
			}
			$r = Service_Nj669_Solid3d::revision($rid, $p['id']);
			if ( ! $r OR $r['status'] !== 'done' OR trim((string) $r['source']) === '') { throw new DomainException('Сначала соберите деталь.'); }
			$source = Service_Nj669_Parameters::apply($r['source'], $values);
			Service_Nj669_Solid3d::validate_source($source);
			$stored = Service_Nj669_Parameters::normalize($r['source'], $values);
			$token = bin2hex(random_bytes(24));
			$x = self::row('INSERT INTO nj_669_solid3d_exports (code,project_id,revision_id,user_id,run_token,params,source,expires_at)
				VALUES (:c,:p,:r,:u,:t,CAST(:pp AS jsonb),:src,now()+'.self::LIFETIME.') RETURNING id',
				[':c' => $p['code'], ':p' => $p['id'], ':r' => (int) $r['id'], ':u' => (int) $uid, ':t' => $token,
					':pp' => json_encode($stored), ':src' => $source]);
			$job = Service_Nj120_JobQueue::push('nj669.export', [
				'export_id' => (int) $x['id'], 'revision_id' => (int) $r['id'], 'run_token' => $token,
			], ['module' => 'nj669', 'created_by' => (int) $uid, 'visitor_id' => 'u:'.(int) $uid,
				'max_attempts' => 1, 'timeout_sec' => (int) Service_Nj669_Solid3d::config('export_timeout_sec')]);
			if ( ! $job) { throw new RuntimeException('Очередь не приняла задачу.'); }
			self::exec('UPDATE nj_669_solid3d_exports SET job_id=:j WHERE id=:id', [':j' => (int) $job, ':id' => (int) $x['id']]);
			$db->commit();
			return (int) $x['id'];
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
	}

	/** Приёмка воркера: context → upload → result (или fail). Токен сборки обязателен. */
	public static function worker(array $body, $action)
	{
		$eid = (int) Arr::get($body, 'export_id', 0);
		$token = (string) Arr::get($body, 'run_token', '');
		$db = Database::instance(); $db->begin();
		try
		{
			$x = self::row('SELECT * FROM nj_669_solid3d_exports WHERE id=:id FOR UPDATE', [':id' => $eid]);
			if ( ! $x OR $token === '' OR ! hash_equals((string) $x['run_token'], $token)) { throw new DomainException('Токен сборки STL не совпал.'); }
			if ($action === 'export_result' AND $x['status'] === 'done') { $db->commit(); return ['ok' => TRUE, 'already_done' => TRUE]; }
			if ($action === 'export_fail' AND $x['status'] === 'error') { $db->commit(); return ['ok' => TRUE, 'already_failed' => TRUE]; }
			if ( ! in_array($x['status'], ['queued', 'running'], TRUE)) { throw new DomainException('Сборка уже закрыта.'); }
			if ($action === 'export_context')
			{
				self::exec("UPDATE nj_669_solid3d_exports SET status='running' WHERE id=:id", [':id' => $eid]);
				$db->commit();
				return ['ok' => TRUE, 'source' => (string) $x['source'], 'revision_id' => (int) $x['revision_id']];
			}
			if ($action === 'export_upload')
			{
				$file = (array) Arr::get($_FILES, 'file', []);
				if ((int) Arr::get($file, 'error', -1) !== UPLOAD_ERR_OK OR ! is_uploaded_file((string) Arr::get($file, 'tmp_name', ''))
					OR filesize($file['tmp_name']) > (int) Service_Nj669_Solid3d::config('max_file_bytes')) { throw new DomainException('Некорректная загрузка STL.'); }
				$raw = file_get_contents($file['tmp_name']);
				Service_Nj669_Solid3d::validate_file('stl', $raw);
				Service_Nj669_Solid3d::mkdir(Service_Nj669_Solid3d::dir($x['code']));
				$path = self::path($x['code'], $eid);
				if (file_put_contents($path, $raw, LOCK_EX) !== strlen($raw)) { throw new RuntimeException('STL не сохранён.'); }
				chmod($path, 0600);
				$db->commit();
				return ['ok' => TRUE];
			}
			if ($action === 'export_result')
			{
				$path = self::path($x['code'], $eid);
				if ( ! is_file($path)) { throw new DomainException('Нет загруженного STL.'); }
				self::exec("UPDATE nj_669_solid3d_exports SET status='done', size_bytes=:s, source='', finished_at=now(),
					expires_at=now()+".self::LIFETIME." WHERE id=:id", [':s' => (int) filesize($path), ':id' => $eid]);
				$db->commit();
				return ['ok' => TRUE];
			}
			if ($action === 'export_fail')
			{
				self::exec("UPDATE nj_669_solid3d_exports SET status='error', error=:e, detail=:d, source='', finished_at=now(),
					expires_at=now()+".self::LIFETIME.' WHERE id=:id', [
					':e' => mb_substr((string) Arr::get($body, 'error', 'Ошибка воркера'), 0, 1000),
					':d' => mb_substr((string) Arr::get($body, 'detail', ''), 0, 1500), ':id' => $eid]);
				$db->commit();
				return ['ok' => TRUE];
			}
			throw new DomainException('Неизвестное действие сборки STL.');
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
	}

	/** Путь к готовому файлу для скачивания или NULL: нет доступа, срок вышел или сборка не готова. */
	public static function download($code, $eid, $uid, $moderator)
	{
		$p = Service_Nj669_Solid3d::project($code);
		if ( ! $p) { return NULL; }
		$x = self::row("SELECT * FROM nj_669_solid3d_exports WHERE id=:id AND project_id=:p AND status='done' AND expires_at > now()",
			[':id' => (int) $eid, ':p' => $p['id']]);
		if ( ! $x OR ( ! $moderator AND (int) $x['user_id'] !== (int) $uid)) { return NULL; }
		$path = self::path($code, $eid);
		return is_file($path) ? $path : NULL;
	}

	/** Последняя сборка пользователя в проекте: для вывода статуса и опроса. alive — файл ещё доступен. */
	public static function latest($pid, $uid)
	{
		if ((int) $uid <= 0) { return NULL; }
		return self::row('SELECT *, (expires_at > now()) AS alive FROM nj_669_solid3d_exports WHERE project_id=:p AND user_id=:u ORDER BY id DESC LIMIT 1',
			[':p' => (int) $pid, ':u' => (int) $uid]);
	}

	/**
	 * Чистка: зависшие сборки закрываются ошибкой, истёкшие файлы и строки удаляются.
	 * Вызывается перед постановкой и при открытии страницы детали.
	 */
	public static function purge()
	{
		self::exec("UPDATE nj_669_solid3d_exports SET status='error', error='Сборка не завершилась за отведённое время. Попробуйте ещё раз.',
			finished_at=now(), expires_at=now()+".self::LIFETIME." WHERE status IN ('queued','running') AND created_at < now() - ".self::STALE);
		$old = self::rows('SELECT id, code FROM nj_669_solid3d_exports WHERE expires_at <= now() ORDER BY id LIMIT 500');
		foreach ($old as $x)
		{
			$path = self::path($x['code'], $x['id']);
			if (is_file($path)) { unlink($path); }
		}
		if ($old) { self::exec('DELETE FROM nj_669_solid3d_exports WHERE expires_at <= now()'); }
	}

	/** Путь файла: dir/x{id}.stl. Код проверяет Service_Nj669_Solid3d::dir. */
	public static function path($code, $eid)
	{
		return Service_Nj669_Solid3d::dir($code).'/x'.(int) $eid.'.stl';
	}

	private static function row($sql, array $params = []) { return Service_Nj669_Solid3d::row($sql, $params); }
	private static function rows($sql, array $params = []) { return Service_Nj669_Solid3d::rows($sql, $params); }
	private static function exec($sql, array $params = []) { return Service_Nj669_Solid3d::exec($sql, $params); }
}
