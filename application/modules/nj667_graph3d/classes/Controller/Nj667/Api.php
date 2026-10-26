<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-667 «Графовый 3D-конструктор» — JSON API для страницы.
 *
 *   POST /graph3d/api/create     — новый проект и сразу сборка по описанию;
 *   POST /graph3d/api/generate   — пересобрать граф по описанию (nj667.generate);
 *   POST /graph3d/api/inpaint    — достроить выделенное (nj667.inpaint);
 *   POST /graph3d/api/save       — сохранить граф после ручной правки;
 *   GET  /graph3d/api/history    — ревизии проекта (undo/redo и откат);
 *   POST /graph3d/api/revision   — контрольная точка или откат к ревизии;
	 *   POST /graph3d/api/publish    — в каталог (без новой ревизии; пре-модерация
	 *                                 только если NJ667_PREMODERATE=1);
 *   POST /graph3d/api/moderate   — вердикт модератора (право nj667.moderate);
 *   POST /graph3d/api/caption    — перегенерировать название и описание
 *                                 (nj667.caption, право nj667.moderate);
 *   GET  /graph3d/api/caption    — статус этой задачи и текущая карточка;
 *   POST /graph3d/api/remix      — скопировать чужой граф себе;
 *   POST /graph3d/api/delete     — удалить проект;
 *   GET  /graph3d/api/status     — живое состояние проекта (поллинг);
 *   GET  /graph3d/api/search     — поиск по каталогу (текст);
 *   GET  /graph3d/api/catalog    — витрина каталога;
 *   GET  /graph3d/api/ideas      — три случайных пресета для «Создать»;
 *   GET  /graph3d/api/preview    — превью графа картинкой (SVG, считается
 *                                 на месте по узлам);
 *   POST /graph3d/api/build      — пересчитать спецификацию и (по желанию)
 *                                 поставить рендер STL в очередь;
 *   GET  /graph3d/api/build_status — состояние рендера для поллинга.
 *
 * Начальная LLM-генерация требует аккаунта и `nj667.start` (роль login, vip
 * или admin); правки существующего графа — ACL `nj667.moderate`. Читать
 * каталог и превью можно всем.
 */
class Controller_Nj667_Api extends Controller
{
	public $auto_render = FALSE;

	protected $_body = [];

	public function before()
	{
		parent::before();

		$this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

		$raw     = $this->request->body();
		$decoded = $raw ? json_decode($raw, TRUE) : NULL;

		$this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();

		if ( ! $this->_body AND $this->request->query())
		{
			$this->_body = (array) $this->request->query();
		}
	}

	/** POST /graph3d/api/create — новый проект + сборка по описанию. */
	public function action_create()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$gate = $this->_gate(TRUE);

		if ($gate !== NULL)
		{
			return $gate;
		}

		$user = Auth::instance()->get_user();

		$prompt = UTF8::substr(trim((string) Arr::get($this->_body, 'prompt', '')), 0,
			(int) Service_Nj667_Graph3d::limits('max_prompt', 1000));
		$images      = Arr::get($this->_body, 'images', []);
		$photo_limit = $this->_photo_limit();

		if (is_array($images) AND count($images) > $photo_limit)
		{
			return $this->_respond([
				'ok' => FALSE,
				'error' => 'Можно прикрепить не больше '.$photo_limit.($photo_limit === 1 ? ' изображения.' : ' изображений.'),
			], 422);
		}

		$jpegs = is_array($images) ? Service_Nj667_Graph3d::prepare_payload($images, $photo_limit) : [];

		if ($prompt === '' AND ! $jpegs)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Опишите изделие или приложите фото.'], 422);
		}

		$quota = Service_Nj667_Graph3d::llm_quota_status($user ? (int) $user->id : 0);

		if (empty($quota['ready']))
		{
			return $this->_respond(['ok' => FALSE, 'error' => (string) Arr::get($quota, 'error',
				'Дневной учёт LLM-операций недоступен.'), 'quota' => $quota], 503);
		}

		if (empty($quota['allowed']))
		{
			return $this->_respond(['ok' => FALSE,
				'error' => 'Дневной лимит LLM-операций исчерпан: '.(int) $quota['used'].' из '.(int) $quota['limit']
					.'. Счётчик обновится после полуночи UTC.', 'quota' => $quota], 429);
		}

		$done = Service_Nj667_Graph3d::create($user ? (int) $user->id : 0, array_merge($this->_body, [
			'has_photos' => (bool) $jpegs,
		]));

		if ( ! Arr::get($done, 'ok'))
		{
			return $this->_respond($done, 422);
		}

		if ($jpegs)
		{
			Service_Nj667_Graph3d::write_photos((string) Arr::get($done, 'code', ''), $jpegs);
		}

		Service_Nj667_Graph3d::sweep();

		$project = Service_Nj667_Graph3d::project_by_id((int) Arr::get($done, 'id'));

		return $this->_start_generate($project, $prompt, $done);
	}

	/**
	 * POST /graph3d/api/generate — (пере)собрать граф по описанию.
	 *
	 * Повторная сборка — НОВЫЙ граф, поэтому старую историю не трогаем: она
	 * остаётся, и к ней можно откатиться. Новый граф пишется как обычная
	 * ревизия с подписью «по описанию».
	 */
	public function action_generate()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$project = $this->_project();

		if ( ! is_array($project))
		{
			return $project;
		}

		if ( ! $this->_can_moderate())
		{
			return $this->_respond(['ok' => FALSE,
				'error' => 'Повторно менять граф с помощью LLM могут только модераторы и администраторы.'], 403);
		}

		$rate = $this->_rate_gate();

		if ($rate !== NULL)
		{
			return $rate;
		}

		$prompt = UTF8::substr(trim((string) Arr::get($this->_body, 'prompt', '')), 0,
			(int) Service_Nj667_Graph3d::limits('max_prompt', 1000));
		$code   = (string) Arr::get($project, 'code', '');
		$images = Arr::get($this->_body, 'images', NULL);

		if (is_array($images) AND $images)
		{
			$photo_limit = $this->_photo_limit();

			if (count($images) > $photo_limit)
			{
				return $this->_respond([
					'ok' => FALSE,
					'error' => 'Можно прикрепить не больше '.$photo_limit.($photo_limit === 1 ? ' изображения.' : ' изображений.'),
				], 422);
			}

			Service_Nj667_Graph3d::replace_photos_from_payload($code, $images, $photo_limit);
		}

		if ($prompt === '' AND Service_Nj667_Graph3d::photos_count($code) <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Опишите изделие или приложите фото.'], 422);
		}

		return $this->_start_generate($project, $prompt, ['code' => Arr::get($project, 'code')]);
	}

	/** Постановка nj667.generate с ответом странице. */
	protected function _start_generate(array $project, $prompt, array $extra = [])
	{
		$done = Service_Nj667_Graph3d::queue(Service_Nj667_Graph3d::CH_GENERATE, $project, [
			'prompt' => $prompt,
			'rag'    => Service_Nj667_Graph3d::rag_from($this->_body),
		]);

		if ( ! Arr::get($done, 'ok'))
		{
			if (Arr::get($done, 'quota_rejected') AND (int) Arr::get($extra, 'id', 0) > 0)
			{
				$user = Auth::instance()->get_user();
				Service_Nj667_Graph3d::delete((string) Arr::get($extra, 'code', ''),
					$user ? (int) $user->id : 0, class_exists('Visitor') ? (string) Visitor::uid() : '');
			}

			$body = $done;
			$status = (int) Arr::get($body, 'http_status', 502);
			unset($body['http_status'], $body['quota_rejected']);

			return $this->_respond($body, $status);
		}

		return $this->_respond($extra + [
			'ok'     => TRUE,
			'job_id' => (int) Arr::get($done, 'job_id'),
			'status' => 'generate',
			'quota'  => Arr::get($done, 'quota'),
		]);
	}

	/**
	 * POST /graph3d/api/inpaint — достроить ВЫДЕЛЕННОЕ.
	 *
	 * В модель уезжает не весь проект, а окрестность выбранных узлов: правят
	 * обычно одно место («сделай спортивный руль-баран»), а контекст стоит
	 * денег и места в окне модели.
	 */
	public function action_inpaint()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$project = $this->_project();

		if ( ! is_array($project))
		{
			return $project;
		}

		if ( ! $this->_can_moderate())
		{
			return $this->_respond(['ok' => FALSE,
				'error' => 'Править граф с помощью LLM могут только модераторы и администраторы.'], 403);
		}

		$rate = $this->_rate_gate();

		if ($rate !== NULL)
		{
			return $rate;
		}

		$prompt = UTF8::substr(trim((string) Arr::get($this->_body, 'prompt', '')), 0,
			(int) Service_Nj667_Graph3d::limits('max_prompt', 1000));

		if ($prompt === '')
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Напишите, что сделать с выделенным.'], 422);
		}

		$ids = [];

		foreach ((array) Arr::get($this->_body, 'node_ids', []) as $id)
		{
			$id = trim((string) $id);

			if ($id !== '')
			{
				$ids[] = UTF8::substr($id, 0, 64);
			}

			if (count($ids) >= 16)
			{
				break;
			}
		}

		if ( ! $ids)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Выделите узел на холсте.'], 422);
		}

		$known = [];

		foreach (Arr::get(Service_Nj667_Graph3d::graph_of($project), 'nodes', []) as $node)
		{
			$known[(string) Arr::get($node, 'id')] = TRUE;
		}

		$ids = array_values(array_filter($ids, function ($id) use ($known) { return isset($known[$id]); }));

		if ( ! $ids)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Такого узла в графе нет.'], 422);
		}

		$done = Service_Nj667_Graph3d::queue(Service_Nj667_Graph3d::CH_INPAINT, $project, [
			'prompt'   => $prompt,
			'node_ids' => $ids,
			'depth'    => max(1, min(3, (int) Arr::get($this->_body, 'depth', 1))),
		]);

		if ( ! Arr::get($done, 'ok'))
		{
			$body = $done;
			$status = (int) Arr::get($body, 'http_status', 502);
			unset($body['http_status'], $body['quota_rejected']);

			return $this->_respond($body, $status);
		}

		return $this->_respond(['ok' => TRUE, 'job_id' => (int) Arr::get($done, 'job_id'),
			'status' => 'inpaint', 'node_ids' => $ids,
			'quota' => Arr::get($done, 'quota')]);
	}

	/** POST /graph3d/api/save — сохранить граф после ручной правки. */
	public function action_save()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$project = $this->_project();

		if ( ! is_array($project))
		{
			return $project;
		}

		$graph = Arr::get($this->_body, 'graph');

		if ( ! is_array($graph))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду граф (nodes и edges).'], 422);
		}

		$done = Service_Nj667_Graph3d::save_graph((int) Arr::get($project, 'id'), $graph, 'user',
			UTF8::substr(trim((string) Arr::get($this->_body, 'label', '')), 0, 200));

		if ( ! Arr::get($done, 'ok'))
		{
			return $this->_respond($done, 422);
		}

		return $this->_respond([
			'ok'     => TRUE,
			'ops'    => (int) Arr::get($done, 'ops', 0),
			'no'     => (int) Arr::get($done, 'no', 0),
			'graph'  => Arr::get($done, 'graph'),
		]);
	}

	/** GET|POST /graph3d/api/history — ревизии проекта. */
	public function action_history()
	{
		$project = $this->_project();

		if ( ! is_array($project))
		{
			return $project;
		}

		$rows = Service_Nj667_Graph3d::revisions((int) Arr::get($project, 'id'));

		foreach ($rows as $i => $row)
		{
			$patch = json_decode((string) Arr::get($row, 'patch_json', '[]'), TRUE);
			$rows[$i]['ops'] = is_array($patch) ? count($patch) : 0;
			unset($rows[$i]['graph_json'], $rows[$i]['patch_json']);
		}

		return $this->_respond(['ok' => TRUE, 'revisions' => $rows]);
	}

	/** POST /graph3d/api/revision — контрольная точка или откат. */
	public function action_revision()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$project = $this->_project();

		if ( ! is_array($project))
		{
			return $project;
		}

		$id      = (int) Arr::get($this->_body, 'id', 0);
		$project_id = (int) Arr::get($project, 'id');

		$action = (string) Arr::get($this->_body, 'action', '');

		if ($action === 'milestone')
		{
			$done = Service_Nj667_Graph3d::milestone($project_id,
				(string) Arr::get($this->_body, 'label', ''));

			return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
		}

		if ($id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Укажите ревизию.'], 422);
		}

		if ($action === 'delete' OR $action === 'forget')
		{
			$done = Service_Nj667_Graph3d::delete_revision($project_id, $id);

			return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 404);
		}

		$done = Service_Nj667_Graph3d::restore($project_id, $id);

		if ( ! Arr::get($done, 'ok'))
		{
			return $this->_respond($done, 422);
		}

		return $this->_respond(['ok' => TRUE, 'graph' => Arr::get($done, 'graph'),
			'revisions' => Service_Nj667_Graph3d::revisions($project_id)]);
	}

	/**
	 * POST /graph3d/api/publish — заявка на публикацию.
	 *
	 * Порядок: сначала AI-Guard по структуре (граф без связей не поедет),
	 * потом саммари и вектор. Канал nj667.moderate — только при пре-модерации.
	 */
	public function action_publish()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$project = $this->_project();

		if ( ! is_array($project))
		{
			return $project;
		}

		$done = Service_Nj667_Graph3d::submit((int) Arr::get($project, 'id'),
			Auth::instance()->get_user() ? (int) Auth::instance()->get_user()->id : 0);

		if ( ! Arr::get($done, 'ok'))
		{
			return $this->_respond($done, 422);
		}

		$jobs     = [];
		$channels = [Service_Nj667_Graph3d::CH_SUMMARY, Service_Nj667_Graph3d::CH_EMBED];

		if (Service_Nj667_Graph3d::premoderate())
		{
			$channels[] = Service_Nj667_Graph3d::CH_MODERATE;
		}

		foreach ($channels as $channel)
		{
			$queued = Service_Nj667_Graph3d::queue($channel, $project);

			// Упавшая постановка саммари не отменяет заявку: граф уже в каталоге
			// или в очереди к модератору, а вектор можно посчитать и потом.
			$jobs[$channel] = Arr::get($queued, 'ok') ? (int) Arr::get($queued, 'job_id') : 0;
		}

		$status = (string) Arr::get($done, 'status', Service_Nj667_Graph3d::S_PUBLISHED);

		return $this->_respond(['ok' => TRUE, 'status' => $status,
			'note' => Arr::get($done, 'note', ''), 'jobs' => $jobs,
			'review' => $status === Service_Nj667_Graph3d::S_REVIEW,
			'published' => $status === Service_Nj667_Graph3d::S_PUBLISHED]);
	}

	/** POST /graph3d/api/moderate — вердикт модератора. */
	public function action_moderate()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		if ( ! Gate::logged_in())
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Нужно войти на сайт.'], 401);
		}

		if ( ! $this->_can_moderate())
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Модерировать каталог могут администраторы.'], 403);
		}

		if ( ! Security::check((string) Arr::get($this->_body, 'csrf', '')))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела, обновите страницу.'], 403);
		}

		$project = Service_Nj667_Graph3d::project((string) Arr::get($this->_body, 'code', ''));

		if ($project === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Проект не найден.'], 404);
		}

		$user = Auth::instance()->get_user();

		$done = Service_Nj667_Graph3d::moderate((int) Arr::get($project, 'id'),
			(string) Arr::get($this->_body, 'verdict', ''),
			(string) Arr::get($this->_body, 'note', ''),
			$user ? (int) $user->id : 0,
			(int) Arr::get($this->_body, 'revision_id', 0));

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	/**
	 * POST /graph3d/api/caption — перегенерировать название и описание.
	 * GET  — статус задачи и текущие поля карточки.
	 *
	 * Право nj667.moderate: модератор правит карточку заявки, не владелец.
	 * Задача не занимает job_id холста (владелец, 11.10.2026).
	 */
	public function action_caption()
	{
		if ( ! Gate::logged_in())
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Нужно войти на сайт.'], 401);
		}

		if ( ! $this->_can_moderate())
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Карточку каталога могут переписать администраторы.'], 403);
		}

		$project = Service_Nj667_Graph3d::project((string) Arr::get($this->_body, 'code', ''));

		if ($project === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Проект не найден.'], 404);
		}

		$revision_id = (int) Arr::get($this->_body, 'revision_id', 0);

		if ($this->request->method() === Request::GET)
		{
			$job_id = (int) Arr::get($this->_body, 'job', 0);
			$job    = Service_Nj667_Graph3d::job_state($job_id);
			$title  = (string) Arr::get($project, 'title');
			$summary = (string) Arr::get($project, 'summary');
			$tags   = (string) Arr::get($project, 'tags');

			if ($revision_id > 0)
			{
				$rev = Service_Nj667_Graph3d::revision_of((int) Arr::get($project, 'id'), $revision_id);

				if (is_array($rev))
				{
					foreach (['title', 'summary', 'tags'] as $field)
					{
						$val = trim((string) Arr::get($rev, $field, ''));

						if ($val !== '')
						{
							if ($field === 'title') { $title = $val; }
							elseif ($field === 'summary') { $summary = $val; }
							else { $tags = $val; }
						}
					}
				}
			}

			$failed = Service_Nj667_Graph3d::job_failed($job);
			$error  = $failed ? Service_Nj667_Graph3d::job_error_text($job) : '';

			return $this->_respond([
				'ok'      => TRUE,
				'job_id'  => $job_id,
				'job'     => $job,
				'title'   => $title,
				'summary' => $summary,
				'tags'    => $tags,
				'failed'  => $failed,
				'error'   => $error,
			]);
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST или GET.'], 405);
		}

		if ( ! Security::check((string) Arr::get($this->_body, 'csrf', '')))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела, обновите страницу.'], 403);
		}

		$queued = Service_Nj667_Graph3d::queue(Service_Nj667_Graph3d::CH_CAPTION, $project, [
			'revision_id' => $revision_id,
		]);

		return $this->_respond($queued, Arr::get($queued, 'ok') ? 200 : 422);
	}

	/** POST /graph3d/api/remix — скопировать чужой опубликованный граф себе. */
	public function action_remix()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$gate = $this->_gate();

		if ($gate !== NULL)
		{
			return $gate;
		}

		$user = Auth::instance()->get_user();

		$done = Service_Nj667_Graph3d::remix((string) Arr::get($this->_body, 'code', ''),
			$user ? (int) $user->id : 0,
			(int) Arr::get($this->_body, 'r', 0));

		if ( ! Arr::get($done, 'ok'))
		{
			return $this->_respond($done, 422);
		}

		return $this->_respond($done + ['url' => '/graph3d/p/'.Arr::get($done, 'code')]);
	}

	/** POST /graph3d/api/delete — удалить проект. */
	public function action_delete()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$gate = $this->_gate();

		if ($gate !== NULL)
		{
			return $gate;
		}

		$user = Auth::instance()->get_user();
		$uid  = class_exists('Visitor') ? (string) Visitor::uid() : '';

		// Этот контроллер — наследник Controller, не Erp100_Base: метода
		// can() здесь нет (TypeError → «удалить не работает»). Право
		// модератора проверяем своим _can_moderate().
		$done = Service_Nj667_Graph3d::delete((string) Arr::get($this->_body, 'code', ''),
			$user ? (int) $user->id : 0, $uid, (bool) $this->_can_moderate());

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 404);
	}

	/** GET|POST /graph3d/api/status — живое состояние проекта (поллинг). */
	public function action_status()
	{
		$project = $this->_project();

		if ( ! is_array($project))
		{
			return $project;
		}

		$user   = Auth::instance()->get_user();
		$job_id = (int) Arr::get($project, 'job_id', 0);
		$job    = Service_Nj667_Graph3d::job_state($job_id);

		if ($job_id > 0 AND ! Service_Nj667_Graph3d::job_live($job))
		{
			Service_Nj667_Graph3d::clear_job((int) Arr::get($project, 'id', 0));
			$job_id = 0;
		}

		if (Service_Nj667_Graph3d::job_failed($job))
		{
			$job['error'] = Service_Nj667_Graph3d::job_error_text($job);
		}
		elseif (trim((string) Arr::get($job, 'error', '')) !== '')
		{
			$job['error'] = Service_Nj667_Graph3d::job_error_text($job);
		}

		return $this->_respond([
			'ok'           => TRUE,
			'status'       => (string) Arr::get($project, 'status'),
			'job_id'       => $job_id,
			'job'          => $job,
			'nodes'        => (int) Arr::get($project, 'nodes_count'),
			'edges'        => (int) Arr::get($project, 'edges_count'),
			'summary'      => (string) Arr::get($project, 'summary'),
			'graph'        => Service_Nj667_Graph3d::graph_of($project),
			'simple_flow'  => Service_Nj667_Graph3d::simple_flow_state($project),
			'quota'        => Service_Nj667_Graph3d::llm_quota_status(
				$user ? (int) $user->id : 0),
			'note'         => (string) Arr::get($project, 'moderation_note'),
			'updated'      => (string) Arr::get($project, 'updated_at'),
		]);
	}

	/** GET /graph3d/api/search — поиск по каталогу (текст + теги). */
	public function action_search()
	{
		$query = UTF8::substr(trim((string) Arr::get($this->_body, 'q', '')), 0, 200);

		$rows = Service_Nj667_Graph3d::search_text($query, (int) Arr::get(self::_rag(), 'catalog_top', 24));

		return $this->_respond(['ok' => TRUE, 'query' => $query, 'items' => $this->_cards($rows)]);
	}

	/** GET /graph3d/api/catalog — витрина каталога. */
	public function action_catalog()
	{
		$tag    = UTF8::substr(trim((string) Arr::get($this->_body, 'tag', '')), 0, 60);
		$offset = max(0, (int) Arr::get($this->_body, 'offset', 0));

		$rows = Service_Nj667_Graph3d::catalog((int) Arr::get(self::_rag(), 'catalog_top', 24), $offset, $tag);

		return $this->_respond(['ok' => TRUE, 'tag' => $tag, 'items' => $this->_cards($rows)]);
	}

	/** GET /graph3d/api/ideas — три случайных пресета, без аккаунта. */
	public function action_ideas()
	{
		$n = max(1, min(6, (int) Arr::get($this->_body, 'n', 3)));

		return $this->_respond(['ok' => TRUE, 'ideas' => Service_Nj667_Graph3d::idea_presets_pick($n)]);
	}

	/**
	 * GET /graph3d/api/preview — превью графа картинкой (SVG).
	 *
	 * Изометрия 30°. ?theme=night — палитра ночного холста. Никакой модели —
	 * каталог обязан открываться, даже если воркер лежит.
	 */
	public function action_preview()
	{
		$project = Service_Nj667_Graph3d::project((string) Arr::get($this->_body, 'code', ''));

		if ($project === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Проект не найден.'], 404);
		}

		$user = Auth::instance()->get_user();
		$uid  = class_exists('Visitor') ? (string) Visitor::uid() : '';

		if ( ! Service_Nj667_Graph3d::can_read($project, $user ? (int) $user->id : 0, $uid))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Этот граф ещё не опубликован.'], 403);
		}

		$no    = (int) Arr::get($this->_body, 'r', 0);
		$graph = Service_Nj667_Graph3d::public_graph($project, $no);
		$theme = strtolower(trim((string) Arr::get($this->_body, 'theme', 'day')));
		$night = ($theme === 'night' OR $theme === 'dark');

		$this->response->headers('Content-Type', 'image/svg+xml; charset='.Kohana::$charset)
			->headers('Cache-Control', 'public, max-age=3600')
			->headers('X-Content-Type-Options', 'nosniff')
			->body(Service_Nj667_Graph3d::svg_preview($graph, 480, 320, $night));

		return NULL;
	}

	// ------------------------------------------------------------------

	/** Карточки каталога: только то, что показывает витрина. */
	protected function _cards(array $rows)
	{
		$out = [];

		foreach ($rows as $row)
		{
			$out[] = [
				'code'    => (string) Arr::get($row, 'code'),
				'title'   => (string) Arr::get($row, 'title'),
				'summary' => UTF8::substr((string) Arr::get($row, 'summary'), 0, 240),
				'tags'    => (string) Arr::get($row, 'tags'),
				'nodes'   => (int) Arr::get($row, 'nodes_count'),
				'edges'   => (int) Arr::get($row, 'edges_count'),
				'featured' => (int) Arr::get($row, 'featured'),
				'revision_no' => (int) Arr::get($row, 'revision_no', 0),
				'remixes' => (int) Arr::get($row, 'remixes'),
				'views'   => (int) Arr::get($row, 'views'),
				'url'     => Service_Nj667_Graph3d::catalog_href($row),
			];
		}

		return $out;
	}

	protected static function _rag()
	{
		return (array) Arr::get(Service_Nj667_Graph3d::config(), 'rag', []);
	}

	/**
	 * POST /graph3d/api/build — пересобрать изделие без перезагрузки страницы.
	 *
	 * Считает спецификацию и пишет сборку; STL попросит воркер, только если
	 * пришёл render=1. Право на запуск и лимит нужны: минуты CPU на сервере —
	 * это та же монета, что и вызов модели.
	 */
	public function action_build()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$project = $this->_project(TRUE);

		if ( ! is_array($project))
		{
			return $project;
		}

		$user = Auth::instance()->get_user();
		$opt  = Service_Nj667_Graph3d::build_opt($this->_body, Service_Nj667_Graph3d::build_prefs($project));
		$made = Service_Nj667_Graph3d::build_create((int) Arr::get($project, 'id'),
			$user ? (int) $user->id : 0, Service_Nj667_Graph3d::graph_of($project), $opt, 'панель сборки');

		if ( ! Arr::get($made, 'ok'))
		{
			return $this->_respond(['ok' => FALSE, 'error' => (string) Arr::get($made, 'error', 'Сборка не удалась.')], 422);
		}

		$build_id = (int) Arr::get($made, 'build_id', 0);
		$job_id   = 0;
		$note     = '';

		Service_Nj667_Graph3d::set_build_prefs((int) Arr::get($project, 'id'), $opt);

		if ( ! empty($this->_body['render']))
		{
			$queue_opt = $opt;
			$queue_opt['kind'] = 'glb';
			$queued = Service_Nj667_Graph3d::build_queue($build_id, $project, $queue_opt);

			// Очередь отказала — не потеря: спецификация уже посчитана, человек
			// может забрать .scad и собрать сам. Об этом и пишем.
			if ( ! Arr::get($queued, 'ok'))
			{
				$note = (string) Arr::get($queued, 'error', 'Рендер не встал в очередь.');
			}
			else
			{
				$job_id = (int) Arr::get($queued, 'job_id', 0);
			}
		}

		// «Готовые к печати узлы» — вторая очередь на ту же сборку: пачка
		// рендерится одной задачей, и человек забирает детали по одной кнопке.
		$parts_job = 0;
		$parts_n   = 0;
		$parts_skip = 0;

		if ( ! empty($this->_body['parts']))
		{
			$done_parts = Service_Nj667_Graph3d::build_parts_render($build_id, $project,
				Service_Nj667_Graph3d::graph_of($project), $opt);

			if ( ! Arr::get($done_parts, 'ok'))
			{
				$note = (string) Arr::get($done_parts, 'error', 'Узлы не встали в очередь.');
			}
			else
			{
				$parts_job  = (int) Arr::get($done_parts, 'job_id', 0);
				$parts_n    = (int) Arr::get($done_parts, 'parts', 0);
				$parts_skip = (int) Arr::get($done_parts, 'skipped', 0);
			}
		}

		$geom = (array) Arr::get($made, 'geom', []);

		return $this->_respond([
			'ok'           => TRUE,
			'build_id'     => $build_id,
			'job_id'       => $job_id,
			'parts_job'    => $parts_job,
			'parts'        => $parts_n,
			'parts_skipped' => $parts_skip,
			'note'         => $note,
			'spec'        => Service_Nj667_Graph3d::build_spec_pack($geom),
			'issues'      => (array) Arr::get($geom, 'issues', []),
			'scad_kb'     => round(strlen((string) Arr::get($made, 'scad', '')) / 1024, 1),
			'type'        => $opt['type'],
			'quality'     => $opt['quality'],
		]);
	}

	/**
	 * GET /graph3d/api/build_status — чем занята сборка (поллинг страницы).
	 *
	 * Отвечаем и когда STL готов, и когда воркер сдался: страница по этому
	 * ответу либо показывает модель, либо пишет причину.
	 */
	public function action_build_status()
	{
		// Статус читают и для публичного чужого графа, когда пользователь
		// поставил собственный GLB в очередь. Мутации по-прежнему используют
		// _project() и требуют владельца; поллинг проверяет только чтение графа.
		$gate = $this->_gate();

		if ($gate !== NULL)
		{
			return $gate;
		}

		$project = Service_Nj667_Graph3d::project((string) Arr::get($this->_body, 'code', ''));

		if ($project === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Проект не найден.'], 404);
		}

		$user    = Auth::instance()->get_user();
		$user_id = $user ? (int) $user->id : 0;
		$uid     = class_exists('Visitor') ? (string) Visitor::uid() : '';

		if ( ! Service_Nj667_Graph3d::can_read($project, $user_id, $uid))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Этот граф ещё не опубликован.'], 403);
		}

		$build_id = (int) Arr::get($this->_body, 'build_id', 0);
		$build    = $build_id > 0
			? Service_Nj667_Graph3d::build_get($build_id)
			: Service_Nj667_Graph3d::build_current((int) Arr::get($project, 'id'), $user_id);

		if ( ! is_array($build) OR (int) Arr::get($build, 'project_id', 0) !== (int) Arr::get($project, 'id', 0))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сборка не найдена.'], 404);
		}

		if ( ! Service_Nj667_Graph3d::can_read_build($project, $build,
			$user_id, $uid, Gate::logged_in()))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Эта сборка ещё не опубликована.'], 403);
		}

		$job_id = (int) Arr::get($build, 'job_id', 0);
		$job    = Service_Nj667_Graph3d::job_state($job_id);

		// Задача пачки — своя: строки «queued» несут её job_id. Страница держит
		// кнопку занятой по ней, а не по тому, что успели отразить строки.
		$parts_job_id      = Service_Nj667_Graph3d::build_parts_job((int) $build['id']);
		$parts_job         = Service_Nj667_Graph3d::job_state($parts_job_id);
		$parts_job_state   = $parts_job_id > 0 ? (string) Arr::get($parts_job, 'state', '') : '';
		$parts_job_elapsed = $parts_job_id > 0 ? (int) Arr::get($parts_job, 'elapsed_sec', 0) : 0;

		return $this->_respond([
			'ok'        => TRUE,
			'build_id'  => (int) $build['id'],
			'status'    => (string) Arr::get($build, 'status', 'spec'),
			'error'     => (string) Arr::get($build, 'error', ''),
			'render_ms' => (int) Arr::get($build, 'render_ms', 0),
			'stl_size'  => (int) Arr::get($build, 'stl_size', 0),
			'stl_sha'   => (string) Arr::get($build, 'stl_sha', ''),
			// Просмотр: >0 — страница грузит glTF, 0 — ей оставлен STL.
			'glb_size'  => (int) Arr::get($build, 'glb_size', 0),
			'done_at'   => (string) Arr::get($build, 'done_at', ''),
			'job'       => $job_id > 0 ? (string) Arr::get($job, 'state', '') : '',
			// Секундомер задачи с сервера: страница держит время от настоящего старта.
			'job_elapsed' => $job_id > 0 ? (int) Arr::get($job, 'elapsed_sec', 0) : 0,
			'job_error' => Service_Nj667_Graph3d::job_failed($job)
				? Service_Nj667_Graph3d::job_error_text($job) : '',
			'parts_job'         => $parts_job_state,
			'parts_job_elapsed' => $parts_job_elapsed,
			// Узлы — отдельной пачкой: JS обновляет таблицу строк, а не всю
			// страницу (человек в этот момент может крутить модель).
			'parts'     => array_map(function ($row) {
				return [
					'part'   => (int) Arr::get($row, 'part_index'),
					'status' => (string) Arr::get($row, 'status', 'spec'),
					'size'   => (int) Arr::get($row, 'stl_size', 0),
					// Время рендера узла: в строке «в 3D» и в мете окна (без перезагрузки).
					'render_ms' => (int) Arr::get($row, 'render_ms', 0),
					'error'  => (string) Arr::get($row, 'error', ''),
					'copies' => (int) Arr::get($row, 'copies', 1),
				];
			}, Service_Nj667_Graph3d::build_parts((int) $build['id'])),
		]);
	}

	/**
	 * Общая проверка: аккаунт + CSRF. NULL — всё в порядке.
	 *
	 * $need_run=TRUE добавляет право nj667.start: сборка стоит денег, а править
	 * свой граф (сохранение, история, удаление) может любой владелец — иначе
	 * человек не смог бы допилить уже построенное.
	 */
	protected function _gate($need_run = FALSE)
	{
		if ( ! Gate::logged_in())
		{
			return $this->_respond([
				'ok'    => FALSE,
				'error' => 'Конструктор доступен по аккаунту. Войдите на сайт и повторите запрос.',
			], 401);
		}

		if ( ! Security::check((string) Arr::get($this->_body, 'csrf', '')))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела, обновите страницу.'], 403);
		}

		if ($need_run)
		{
			if ( ! $this->_can_start())
			{
				return $this->_respond([
					'ok'    => FALSE,
					'error' => 'Для создания графа нужна роль login, vip или admin: '
						.'каждая сборка — это работа модели.',
				], 403);
			}

			if ( ! Service_Nj667_Graph3d::rate_ok())
			{
				return $this->_respond([
					'ok'    => FALSE,
					'error' => 'Слишком часто. Лимит: '.Service_Nj667_Graph3d::rate_text().'.',
				], 429);
			}
		}

		return NULL;
	}

	/** Частотный limiter для модераторских LLM-правок, у которых нет nj667.start. */
	protected function _rate_gate()
	{
		if (Service_Nj667_Graph3d::rate_ok())
		{
			return NULL;
		}

		return $this->_respond([
			'ok'    => FALSE,
			'error' => 'Слишком часто. Лимит: '.Service_Nj667_Graph3d::rate_text().'.',
		], 429);
	}

	/**
	 * Право nj667.start из ACL (login, vip или admin).
	 *
	 * Роли достаём сами: этот контроллер — наследник Controller, а не
	 * Controller_Erp100_Base, поэтому готового списка ролей сессии здесь нет.
	 */
	protected function _can_start()
	{
		return $this->_allows('nj667.start');
	}

	/** Право nj667.moderate по ACL; администратор получает все права. */
	protected function _can_moderate()
	{
		return $this->_allows('nj667.moderate');
	}

	/** Фото обычного пользователя ограничены одним; модератор может передать максимум четыре. */
	protected function _photo_limit()
	{
		return $this->_can_moderate() ? Service_Nj667_Graph3d::PHOTO_MAX : 1;
	}

	/** Проверка права по ACL: админ имеет всё, остальное — по конфигу acl.php. */
	protected function _allows($ability)
	{
		if ( ! Gate::logged_in())
		{
			return FALSE;
		}

		$user = Auth::instance()->get_user();

		if ($user === NULL)
		{
			return FALSE;
		}

		$roles = [];

		try
		{
			foreach ($user->roles->find_all() as $role)
			{
				$roles[] = (string) $role->name;
			}
		}
		catch (Throwable $e)
		{
			// Роли недоступны — значит права нет: молча пускать нельзя.
			return FALSE;
		}

		// Админ сайта имеет всё; роли берём из базы, а не из метода модели (его нет).
		if (in_array('admin', $roles, TRUE))
		{
			return TRUE;
		}

		return (bool) ACL::check($roles, (string) $ability);
	}

	/** Проект по коду из запроса + право на него. Массив — ок, иначе ответ. */
	protected function _project($need_run = FALSE)
	{
		$gate = $this->_gate($need_run);

		if ($gate !== NULL)
		{
			return $gate;
		}

		$project = Service_Nj667_Graph3d::project((string) Arr::get($this->_body, 'code', ''));

		if ($project === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Проект не найден.'], 404);
		}

		$user = Auth::instance()->get_user();
		$uid  = class_exists('Visitor') ? (string) Visitor::uid() : '';

		if ( ! Service_Nj667_Graph3d::owns($project, $user ? (int) $user->id : 0, $uid))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Это чужой проект.'], 403);
		}

		return $project;
	}

	protected function _respond(array $data, $status = 200)
	{
		return $this->response->status((int) $status)
			->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
}
