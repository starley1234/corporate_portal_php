<?php defined('SYSPATH') or die('No direct script access.');

/** Публикационные карточки, контрольные точки и живой статус, без чужих таблиц. */
class Service_Nj669_Workspace
{
	public static function metadata(array $data)
	{
		foreach (['title','summary','tags'] as $key) { if ( ! is_string(Arr::get($data, $key, ''))) { throw new DomainException('Поля карточки должны быть текстом.'); } }
		$title = trim((string) Arr::get($data, 'title', ''));
		$summary = trim((string) Arr::get($data, 'summary', ''));
		$tags = trim((string) Arr::get($data, 'tags', ''));
		if ($title === '' OR mb_strlen($title) > 200 OR mb_strlen($summary) > 2000 OR mb_strlen($tags) > 300)
		{
			throw new DomainException('Название: 1–200 знаков; описание: до 2000; теги: до 300.');
		}
		return ['title' => $title, 'summary' => $summary, 'tags' => $tags];
	}

	public static function public_project(array $p, $review = FALSE)
	{
		$meta = json_decode((string) Arr::get($p, $review ? 'review_meta' : 'published_meta', '{}'), TRUE);
		foreach (['title','summary','tags'] as $key) { if (is_array($meta) AND array_key_exists($key, $meta)) { $p[$key] = (string) $meta[$key]; } }
		return $p;
	}

	/** Служебная правка карточки владельцем (восстановление, тесты). В HTTP не выставлена: карточку пишет помощник. */
	public static function edit($code, $uid, array $data)
	{
		$meta = self::metadata($data);
		$db = Database::instance(); $db->begin();
		try
		{
			$p = Service_Nj669_Solid3d::project($code, TRUE);
			if ( ! $p OR (int) $p['user_id'] !== (int) $uid OR $uid <= 0) { throw new DomainException('Нет доступа к карточке.'); }
			Service_Nj669_Solid3d::withdraw($p, $uid, 'Карточка изменена: деталь снята с каталога.');
			Service_Nj669_Solid3d::exec('UPDATE nj_669_solid3d_projects SET title=:t,summary=:s,tags=:tags,updated_at=now() WHERE id=:p',
				[':p' => $p['id'], ':t' => $meta['title'], ':s' => $meta['summary'], ':tags' => $meta['tags']]);
			$db->commit();
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
	}

	public static function checkpoint($code, $uid, $rid, $label, $milestone)
	{
		$label = trim($label);
		if (mb_strlen($label) > 160 OR ($milestone AND $label === '')) { throw new DomainException('Назовите контрольную точку (до 160 знаков).'); }
		$db = Database::instance(); $db->begin();
		try
		{
			$p = Service_Nj669_Solid3d::project($code, TRUE);
			if ( ! $p OR (int) $p['user_id'] !== (int) $uid OR $uid <= 0) { throw new DomainException('Нет доступа к истории.'); }
			$r = Service_Nj669_Solid3d::revision($rid, $p['id']);
			if ( ! $r OR $r['status'] !== 'done') { throw new DomainException('Контрольную точку можно поставить на готовой версии.'); }
			Service_Nj669_Solid3d::exec('UPDATE nj_669_solid3d_revisions SET label=:label,milestone=:m WHERE id=:r',
				[':r' => (int) $rid, ':label' => $label, ':m' => $milestone ? 'true' : 'false']);
			$db->commit();
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
	}

	public static function queue_state($job_id)
	{
		if ($job_id <= 0) { return []; }
		try { return Service_Nj120_JobQueue::instance()->status_of($job_id); }
		catch (Throwable $e)
		{
			Log_Plain::event(Log::ERROR, 'NJ669: статус задачи :id недоступен: :e', [':id' => (int) $job_id, ':e' => $e->getMessage()]);
			return [];
		}
	}

	public static function clear_files($code, $rid)
	{
		foreach (['stl','glb','scad','png','png2','png3'] as $kind)
		{
			$file = Service_Nj669_Solid3d::file($code, $rid, $kind);
			if (is_file($file)) { unlink($file); }
		}
	}

	/** После отмены/смерти очереди закрываем приёмную сразу, не ждём 35 минут. */
	public static function close_revision($code, $rid, $reason, $uid = NULL)
	{
		$db = Database::instance(); $db->begin();
		try
		{
			$p = Service_Nj669_Solid3d::project($code, TRUE);
			if ( ! $p OR ($uid !== NULL AND ((int) $p['user_id'] !== (int) $uid OR $uid <= 0))) { throw new DomainException('Нет доступа к задаче.'); }
			$r = Service_Nj669_Solid3d::revision($rid, $p['id']);
			if ($r AND in_array($r['status'], ['queued','running'], TRUE))
			{
				if ($uid !== NULL) { Service_Nj120_JobQueue::instance()->cancel((int) $r['job_id'], TRUE); }
				if ($r['pending_source'] !== NULL)
				{
					// Пересчёт размеров: версия остаётся с прежними файлами, отменяется только новая сборка.
					Service_Nj669_Solid3d::exec("UPDATE nj_669_solid3d_revisions SET status='done',error=:e,pending_source=NULL,pending_params=NULL,finished_at=now() WHERE id=:r",
						[':r' => (int) $rid, ':e' => mb_substr($reason, 0, 1000)]);
				}
				else
				{
					Service_Nj669_Solid3d::exec("UPDATE nj_669_solid3d_revisions SET status='error',error=:e,finished_at=now() WHERE id=:r",
						[':r' => (int) $rid, ':e' => mb_substr($reason, 0, 1000)]);
					self::clear_files($code, $rid);
				}
			}
			$db->commit();
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
	}

	public static function live(array $p, array $history)
	{
		foreach ($history as $r)
		{
			if ( ! in_array($r['status'], ['queued','running'], TRUE)) { continue; }
			$job = self::queue_state((int) $r['job_id']);
			if (in_array(Arr::get($job, 'status'), ['failed','cancelled','done'], TRUE))
			{
				$why = (string) Arr::get($job, 'error', '');
				self::close_revision($p['code'], $r['id'], $why ?: 'Задача завершилась без приёмки модели. Можно повторить запуск.');
				return ['busy' => FALSE, 'revision_id' => 0, 'stage' => '', 'elapsed_sec' => 0];
			}
			return ['busy' => TRUE, 'revision_id' => (int) $r['id'], 'job_id' => (int) $r['job_id'],
				'stage' => ((string) Arr::get($job, 'stage', '') ?: ($r['status'] === 'queued' ? 'В очереди' : (in_array($r['resizing'] ?? FALSE, [TRUE, 't', 'true', 1, '1'], TRUE) ? 'Пересчёт размеров' : 'Сборка детали'))),
				'elapsed_sec' => (int) Arr::get($job, 'elapsed_sec', max(0, time() - strtotime((string) Arr::get($r, 'queued_at', $r['created_at'])))),
				'silent_sec' => Arr::get($job, 'silent_sec')];
		}
		return ['busy' => FALSE, 'revision_id' => 0, 'stage' => '', 'elapsed_sec' => 0];
	}

	public static function notify(array $p, $rid, $ok, $error = '')
	{
		try
		{
			$event = $ok ? 'nj669.build.done' : 'nj669.build.error';
			$href = '/solid3d/p/'.$p['code'].'?revision='.(int) $rid;
			Service_Nj597_Notify::emit($event, ['user_id' => (int) $p['user_id'],
				'title' => ($ok ? 'Деталь готова: ' : 'Не удалось собрать: ').mb_substr($p['title'], 0, 120),
				'body' => $ok ? 'Готовы модель для просмотра и STL для печати. Проверьте деталь перед печатью.' : mb_substr($error, 0, 300),
				'href' => $href, 'dedup_key' => $event.':'.(int) $rid, 'self' => (bool) $ok,
				'data' => ['title' => $p['title'], 'href' => $href, 'error' => mb_substr($error, 0, 300)]]);
		}
		catch (Throwable $e) { Log_Plain::event(Log::ERROR, 'NJ669: уведомление версии :id: :e', [':id' => (int) $rid, ':e' => $e->getMessage()]); }
	}
}
