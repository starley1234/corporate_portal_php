<?php defined('SYSPATH') or die('No direct script access.');

/** Лёгкие LLM-задачи: идеи модератору и рекомендации карточки владельцу. */
class Service_Nj669_Assist
{
	public static function recent($kind, $pid = NULL)
	{
		Service_Nj669_Solid3d::exec("UPDATE nj_669_solid3d_tasks SET status='error',error='Истекло время помощника',finished_at=now()
			WHERE status IN ('queued','running') AND created_at < now()-interval '10 minutes'");
		return Service_Nj669_Solid3d::rows('SELECT * FROM nj_669_solid3d_tasks WHERE kind=:k'.($pid === NULL ? '' : ' AND project_id=:p').' ORDER BY id DESC LIMIT 10',
			$pid === NULL ? [':k' => $kind] : [':k' => $kind, ':p' => (int) $pid]);
	}

	public static function ideas($limit = 300)
	{
		return Service_Nj669_Solid3d::rows('SELECT * FROM nj_669_solid3d_ideas ORDER BY id DESC LIMIT '.max(1, min(300, (int) $limit)));
	}

	public static function idea_save($id, $body, $theme)
	{
		$body = trim($body); $theme = trim($theme);
		if ($body === '' OR mb_strlen($body) > 600 OR mb_strlen($theme) > 200) { throw new DomainException('Идея: 1–600 знаков; тема: до 200.'); }
		if ($id > 0) { Service_Nj669_Solid3d::exec('UPDATE nj_669_solid3d_ideas SET body=:b,theme=:t WHERE id=:id', [':b' => $body, ':t' => $theme, ':id' => (int) $id]); }
		else { Service_Nj669_Solid3d::exec('INSERT INTO nj_669_solid3d_ideas (body,theme) VALUES (:b,:t)', [':b' => $body, ':t' => $theme]); }
	}

	/**
	 * Постановка помощника. caption: auto — владелец после первой удачной сборки (его квота);
	 * иначе — администратор перегенерирует карточку (его квота). Результат caption сразу пишется в черновик.
	 */
	public static function queue($kind, $uid, $code = '', $theme = '', $moderator = FALSE, $auto = FALSE)
	{
		if ( ! in_array($kind, ['ideas','caption'], TRUE) OR $uid <= 0) { throw new DomainException('Неизвестный помощник.'); }
		if ($kind === 'ideas' AND (trim($theme) === '' OR mb_strlen($theme) > 200)) { throw new DomainException('Тема: 1–200 знаков.'); }
		// Очистка до транзакции, чтобы постановка не оставила просроченный блокер.
		self::recent($kind);
		$db = Database::instance(); $db->begin();
		try
		{
			Service_Nj669_Solid3d::row('SELECT pg_advisory_xact_lock(670,:u)', [':u' => $kind === 'ideas' ? 0 : (int) $uid]);
			Service_Nj669_Solid3d::row('SELECT pg_advisory_xact_lock(669,:u)', [':u' => (int) $uid]);
			$p = NULL;
			if ($kind === 'caption')
			{
				$p = Service_Nj669_Solid3d::project($code, TRUE);
				if ( ! $p OR ($auto ? (int) $p['user_id'] !== (int) $uid : ! $moderator)) { throw new DomainException('Нет доступа к карточке.'); }
				if ( ! $auto) { Service_Nj669_Solid3d::withdraw($p, $uid, 'Карточка перегенерирована: деталь снята с каталога.'); }
			}
			// Оба помощника вызывают языковую модель: запуск списывает суточную квоту.
			$limits = Service_Nj669_Solid3d::limits($uid);
			if ((int) $limits['runs_used'] >= $limits['daily_runs']) { throw new DomainException('Исчерпан суточный лимит запусков.'); }
			$active = Service_Nj669_Solid3d::row("SELECT id FROM nj_669_solid3d_tasks WHERE kind=:k AND status IN ('queued','running')".($p ? ' AND project_id=:p' : ''),
				$p ? [':k' => $kind, ':p' => $p['id']] : [':k' => $kind]);
			if ($active) { throw new DomainException('Помощник уже работает. Дождитесь результата.'); }
			$r = $p ? Service_Nj669_Solid3d::revision($p['current_revision_id'], $p['id']) : NULL;
			// Карточка по коду: хватает начала исходника (комментарии и параметры); название владельца — подсказка только для авто.
			$context = $p ? ['source' => $r ? mb_substr($r['source'], 0, 4000) : '', 'auto' => (bool) $auto]
				+ ($auto ? ['title' => $p['title'], 'summary' => $p['summary'], 'tags' => Arr::get($p, 'tags', '')] : [])
				: ['theme' => trim($theme)];
			$s = Service_Nj669_Solid3d::settings();
			$t = Service_Nj669_Solid3d::row('INSERT INTO nj_669_solid3d_tasks (kind,project_id,user_id,run_token,model,context)
				VALUES (:k,:p,:u,:token,:m,CAST(:c AS jsonb)) RETURNING *', [':k' => $kind, ':p' => $p ? $p['id'] : NULL,
				':u' => (int) $uid, ':token' => bin2hex(random_bytes(24)), ':m' => $s['light_model'], ':c' => json_encode($context, JSON_UNESCAPED_UNICODE)]);
			$job = Service_Nj120_JobQueue::push('nj669.'.$kind, ['task_id' => (int) $t['id'], 'run_token' => $t['run_token']],
				['module' => 'nj669', 'created_by' => (int) $uid, 'visitor_id' => 'u:'.(int) $uid, 'max_attempts' => 1, 'timeout_sec' => 540]);
			if ( ! $job) { throw new RuntimeException('Очередь не приняла задачу помощника.'); }
			Service_Nj669_Solid3d::exec('UPDATE nj_669_solid3d_tasks SET job_id=:j WHERE id=:id', [':j' => (int) $job, ':id' => $t['id']]);
			$db->commit(); return (int) $t['id'];
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
	}

	public static function worker(array $body, $action)
	{
		$db = Database::instance(); $db->begin();
		try
		{
			$t = Service_Nj669_Solid3d::row('SELECT * FROM nj_669_solid3d_tasks WHERE id=:id FOR UPDATE', [':id' => (int) Arr::get($body, 'task_id', 0)]);
			if ( ! $t OR ! hash_equals($t['run_token'], (string) Arr::get($body, 'run_token', ''))) { throw new DomainException('Нет доступа к задаче помощника.'); }
			if ($t['status'] === 'done') { $db->commit(); return ['ok' => TRUE, 'already_done' => TRUE]; }
			if ($t['status'] === 'error' AND $action === 'assist_fail') { $db->commit(); return ['ok' => TRUE]; }
			if ( ! in_array($t['status'], ['queued','running'], TRUE) OR strtotime($t['created_at']) < time()-600) { throw new DomainException('Помощник уже остановлен.'); }
			if ($t['project_id'] AND ! Service_Nj669_Solid3d::row('SELECT id FROM nj_669_solid3d_projects WHERE id=:p AND deleted_at IS NULL', [':p' => $t['project_id']])) { throw new DomainException('Проект удалён.'); }
			$params = [':id' => $t['id']];
			if ($action === 'assist_context')
			{
				Service_Nj669_Solid3d::exec("UPDATE nj_669_solid3d_tasks SET status='running' WHERE id=:id", $params);
				$db->commit(); return ['ok' => TRUE, 'kind' => $t['kind'], 'model' => $t['model'], 'context' => json_decode($t['context'], TRUE)];
			}
			if ($action === 'assist_fail')
			{
				Service_Nj669_Solid3d::exec("UPDATE nj_669_solid3d_tasks SET status='error',error=:e,finished_at=now(),tokens_in=:ti,tokens_out=:tout WHERE id=:id", $params + Service_Nj669_Solid3d::tokens_of($body) + [':e' => mb_substr((string) Arr::get($body, 'error', 'Ошибка помощника'), 0, 1000)]);
			}
			elseif ($action === 'assist_result')
			{
				$result = (array) Arr::get($body, 'result', []);
				if ($t['kind'] === 'ideas')
				{
					$ideas = Arr::get($result, 'ideas');
					if ( ! is_array($ideas) OR count($ideas) < 1 OR count($ideas) > 30) { throw new DomainException('Некорректная пачка идей.'); }
					$context = json_decode($t['context'], TRUE);
					$seen = [];
					foreach ($ideas as $idea)
					{
						if ( ! is_string($idea) OR trim($idea) === '' OR mb_strlen($idea) > 600) { throw new DomainException('Некорректная идея.'); }
						$idea = trim($idea); if (isset($seen[$idea])) { continue; } $seen[$idea] = TRUE;
						Service_Nj669_Solid3d::exec('INSERT INTO nj_669_solid3d_ideas (task_id,theme,body) VALUES (:id,:t,:b)', $params + [':t' => $context['theme'], ':b' => trim($idea)]);
					}
				}
				else
				{
					// Карточка пишется прямо в черновик; публикация берёт свой снимок при отправке на модерацию.
					$result = Service_Nj669_Workspace::metadata($result);
					if ( ! $t['project_id']) { throw new DomainException('Карточка без проекта.'); }
					Service_Nj669_Solid3d::exec('UPDATE nj_669_solid3d_projects SET title=:t,summary=:s,tags=:g,updated_at=now() WHERE id=:p',
						[':p' => $t['project_id'], ':t' => $result['title'], ':s' => $result['summary'], ':g' => $result['tags']]);
				}
				Service_Nj669_Solid3d::exec("UPDATE nj_669_solid3d_tasks SET status='done',result=CAST(:result AS jsonb),finished_at=now(),tokens_in=:ti,tokens_out=:tout WHERE id=:id", $params + Service_Nj669_Solid3d::tokens_of($body) + [':result' => json_encode($result, JSON_UNESCAPED_UNICODE)]);
			}
			else { throw new DomainException('Неизвестная операция помощника.'); }
			$db->commit(); return ['ok' => TRUE];
		}
		catch (Throwable $e) { $db->rollback(); throw $e; }
	}

	public static function auto_caption($code, $uid)
	{
		// Вызывается после первой удачной сборки. Ошибка помощника не должна ломать сборку.
		try
		{
			$p = Service_Nj669_Solid3d::project($code);
			if ( ! $p OR (int) $p['user_id'] !== (int) $uid) { return; }
			if (Service_Nj669_Solid3d::row("SELECT id FROM nj_669_solid3d_tasks WHERE kind='caption' AND project_id=:p LIMIT 1", [':p' => $p['id']])) { return; }
			self::queue('caption', (int) $uid, $code, '', FALSE, TRUE);
		}
		catch (Throwable $e) { Kohana::$log->add(Log::WARNING, 'nj669: авто-карточка: :e', [':e' => $e->getMessage()]); }
	}
}
