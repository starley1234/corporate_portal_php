<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-667: приёмная python-воркера (/graph3d/w/*).
 *
 * Наследник Controller_Nj120_Api (как nj662/nj653/nj666): токен воркера в
 * заголовке X-Worker-Token, тело — JSON. Воркер не ходит в базу: он просит у
 * сайта контекст, отдает граф, саммари, вектор и вердикт пре-модерации.
 *
 * Точки:
 *   POST /graph3d/w/context     {project_id, mode, prompt, node_ids, depth} —
 *                               граф (или окрестность выделенного), лимиты,
 *                               справочники типов, похожие графы для RAG и
 *                               референс-фото (base64 JPEG с диска, не из
 *                               jsonb задачи — урок nj646);
 *   POST /graph3d/w/graph       {project_id, graph, merge, label} — принять
 *                               граф: почистить, проверить (AI-Guard),
 *                               сохранить ревизией;
 *   POST /graph3d/w/summary     {project_id, summary} — текстовое саммари;
 *   POST /graph3d/w/embed       {project_id, vector, model} — вектор саммари;
 *   POST /graph3d/w/moderation  {project_id, verdict, score, reason, bad} —
 *                               вердикт пре-модерации (в журнал);
 *   POST /graph3d/w/ideas       {theme, ideas, job_id} — пачка пресетов
 *                               для страницы «Создать»;
 *   POST /graph3d/w/caption     {project_id, title, summary, tags} —
 *                               название, описание и теги карточки;
 *   POST /graph3d/w/build       {build_id[, part, kind]} — .scad для рендера:
 *                               текст с диска проекта, а не из jsonb (файл и
 *                               есть задача); part — деталь к печати,
 *                               kind=parts — план всей пачки узлов;
 *   POST /graph3d/w/build_file  multipart {build_id, engine, file[, part]} — готовый
 *                               STL; проверяется на диске и в размере до того,
 *                               как мы его примем;
 *   POST /graph3d/w/build_result{build_id, ok, error, render_ms} — итог рендера.
 *
 * Проверка структуры (AI-Guard) живёт ЗДЕСЬ, а не в воркере: на сайте есть
 * граф целиком и лимиты, а воркер видит только то, что ему прислали.
 */
class Controller_Nj667_Worker extends Controller_Nj120_Api
{
	/** POST /graph3d/w/context — что знать воркеру об этом проекте. */
	public function action_context()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$opt = [
			'mode'         => (string) Arr::get($this->_body, 'mode', 'generate'),
			'prompt'       => (string) Arr::get($this->_body, 'prompt', ''),
			'node_ids'     => (array) Arr::get($this->_body, 'node_ids', []),
			'depth'        => (int) Arr::get($this->_body, 'depth', 1),
			'revision_id'  => (int) Arr::get($this->_body, 'revision_id', 0),
		];

		if (array_key_exists('rag', $this->_body))
		{
			$opt['rag'] = $this->_body['rag'];
		}

		$context = Service_Nj667_Graph3d::worker_context(
			(int) Arr::get($this->_body, 'project_id', 0), $opt);

		return $this->_respond($context, Arr::get($context, 'ok') ? 200 : 404);
	}

	/**
	 * POST /graph3d/w/graph — принять граф (целиком или достройку).
	 *
	 * merge=TRUE — достройка выделенного: новые узлы и связи приклеиваются к
	 * существующим по id. Иначе граф заменяется, но старый остаётся в истории
	 * ревизий — откатиться можно всегда.
	 */
	public function action_graph()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$project_id = (int) Arr::get($this->_body, 'project_id', 0);
		$graph      = Arr::get($this->_body, 'graph');

		if ($project_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду project_id'], 422);
		}

		if ( ! is_array($graph))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду graph (nodes и edges)'], 422);
		}

		$done = Service_Nj667_Graph3d::apply_graph($project_id, $graph,
			(bool) Arr::get($this->_body, 'merge', FALSE), 'ai',
			(string) Arr::get($this->_body, 'label', ''));

		if ( ! Arr::get($done, 'ok'))
		{
			// Отказ воркеру — не 500: это модель построила плохой граф, и
			// причина должна уехать в ошибку задачи, а не в «сайт сломался».
			return $this->_respond($done, 422);
		}

		$project = Service_Nj667_Graph3d::project_by_id($project_id);

		if ((string) Arr::get($this->_body, 'model', '') !== '')
		{
			Service_Nj667_Graph3d::set_model($project_id, (string) Arr::get($this->_body, 'model', ''));
		}

		// Только новый граф в одношаговом режиме запускает следующую очередь:
		// сборку из плоских палочек и все уникальные узлы для печати.
		$simple_flow = [];

		if ( ! (bool) Arr::get($this->_body, 'merge', FALSE))
		{
			$simple_flow = Service_Nj667_Graph3d::simple_flow_start($project_id);
		}

		return $this->_respond([
			'ok'          => TRUE,
			'nodes'       => (int) Arr::get($project, 'nodes_count'),
			'edges'       => (int) Arr::get($project, 'edges_count'),
			'ops'         => (int) Arr::get($done, 'ops', 0),
			'guard'       => Service_Nj667_Graph3d::graph_validate(Service_Nj667_Graph3d::graph_of($project)),
			'simple_flow' => $simple_flow,
		]);
	}

	/** POST /graph3d/w/summary — саммари графа (поиск и вектор). */
	public function action_summary()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$project_id = (int) Arr::get($this->_body, 'project_id', 0);
		$summary    = UTF8::substr(trim((string) Arr::get($this->_body, 'summary', '')), 0,
			(int) Service_Nj667_Graph3d::limits('max_summary', 1000));

		if ($project_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду project_id'], 422);
		}

		if ($summary === '')
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Пустое саммари'], 422);
		}

		Service_Nj667_Graph3d::set_summary($project_id, $summary);

		$rev_id = (int) Arr::get($this->_body, 'revision_id', 0);

		if ($rev_id > 0)
		{
			Service_Nj667_Graph3d::set_revision_summary($rev_id, $summary);
		}

		return $this->_respond(['ok' => TRUE, 'project_id' => $project_id, 'summary' => $summary]);
	}

	/** POST /graph3d/w/embed — вектор саммари (pgvector или real[]). */
	public function action_embed()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$project_id = (int) Arr::get($this->_body, 'project_id', 0);
		$vector     = (array) Arr::get($this->_body, 'vector', []);

		if ($project_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду project_id'], 422);
		}

		if ( ! $vector)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Пустой вектор'], 422);
		}

		$done = Service_Nj667_Graph3d::set_embedding($project_id, $vector,
			(string) Arr::get($this->_body, 'model', ''));

		if ( ! $done)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Вектор не сохранён (пустой?)'], 422);
		}

		return $this->_respond(['ok' => TRUE, 'project_id' => $project_id, 'dims' => count($vector)]);
	}

	/**
	 * POST /graph3d/w/moderation — вердикт пре-модерации.
	 *
	 * Модель НЕ публикует и НЕ отклоняет: она ставит пометку в журнал, а
	 * решение принимает человек на /graph3d/moderate. Зато reject от модели
	 * виден сразу — модератор тратит время на спорное, а не на мусор.
	 */
	public function action_moderation()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$project_id = (int) Arr::get($this->_body, 'project_id', 0);

		if ($project_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду project_id'], 422);
		}

		$verdict = strtolower(trim((string) Arr::get($this->_body, 'verdict', 'review')));

		if ( ! in_array($verdict, ['ok', 'review', 'reject'], TRUE))
		{
			$verdict = 'review';
		}

		$bad = [];

		foreach ((array) Arr::get($this->_body, 'bad', []) as $phrase)
		{
			$phrase = UTF8::substr(trim((string) $phrase), 0, 120);

			if ($phrase !== '')
			{
				$bad[] = $phrase;
			}

			if (count($bad) >= 12)
			{
				break;
			}
		}

		$note = UTF8::substr(trim((string) Arr::get($this->_body, 'reason', '')), 0, 300);

		if ($bad)
		{
			$note = UTF8::substr('Помечено: «'.implode('», «', array_slice($bad, 0, 3)).'». '.$note, 0, 480);
		}

		Service_Nj667_Graph3d::event($project_id, 'guard', 'пре-модерация: '.$verdict
			.($note !== '' ? ' — '.$note : ''));

		Service_Nj667_Graph3d::set_guard($project_id, $verdict,
			(float) Arr::get($this->_body, 'score', 0), $note);

		return $this->_respond(['ok' => TRUE, 'project_id' => $project_id, 'verdict' => $verdict]);
	}

	/** POST /graph3d/w/ideas — пачка идей для /graph3d/create. Без проекта. */
	public function action_ideas()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$done = Service_Nj667_Graph3d::idea_presets_save(
			(string) Arr::get($this->_body, 'theme', ''),
			(array) Arr::get($this->_body, 'ideas', []),
			(int) Arr::get($this->_body, 'job_id', 0));

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	/** POST /graph3d/w/caption — название, описание и теги карточки. */
	public function action_caption()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$done = Service_Nj667_Graph3d::set_caption(
			(int) Arr::get($this->_body, 'project_id', 0),
			(string) Arr::get($this->_body, 'title', ''),
			(string) Arr::get($this->_body, 'summary', ''),
			(string) Arr::get($this->_body, 'tags', ''),
			(int) Arr::get($this->_body, 'revision_id', 0));

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	/**
	 * POST /graph3d/w/build — отдать воркеру .scad сборки.
	 *
	 * Воркер не ходит в базу и не знает, что такое граф: он получает готовый
	 * текст модели вместе с лимитом размера ответа. Файл читается с диска —
	 * ровно тот, что человек мог скачать сам, поэтому «что увидит OpenSCAD» и
	 * «что на странице» не разъедутся.
	 */
	public function action_build()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$build_id = (int) Arr::get($this->_body, 'build_id', 0);
		$run_id   = (int) Arr::get($this->_body, 'parts_run_id', 0);
		// -1 = «узла нет»: типы нумеруются с нуля, и 0 — это первый узел.
		$part     = (int) Arr::get($this->_body, 'part', -1);

		// kind=parts — план пачки: воркер рендерит узлы по одному и сам решает,
		// когда остановиться (budget_s принадлежит сайту, иначе очередь и воркер
		// разойдутся во времени). Отдельный адрес для этого не нужен: контекст
		// сборки и контекст узла — один и тот же вопрос.
		if ((string) Arr::get($this->_body, 'kind', '') === 'parts')
		{
			$ctx = Service_Nj667_Graph3d::build_parts_context($build_id, $run_id);

			return $this->_respond($ctx, Arr::get($ctx, 'ok') ? 200 : 404);
		}

		$ctx = $part >= 0
			? Service_Nj667_Graph3d::build_part_scad_text($build_id, $part, $run_id > 0 ? $run_id : NULL)
			: Service_Nj667_Graph3d::build_scad_text($build_id);

		return $this->_respond($ctx, Arr::get($ctx, 'ok') ? 200 : 404);
	}

	/**
	 * POST /graph3d/w/build_file — принять STL (multipart).
	 *
	 * Проверка здесь, а не в воркере: сайт знает лимиты проекта и где лежит
	 * диск. Пустой или обрезанный STL хуже честной ошибки — человек отдал бы
	 * такую модель в цех.
	 */
	public function action_build_file()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$file = (array) Arr::get($_FILES, 'file', []);

		if (empty($file['tmp_name']) OR ! is_uploaded_file((string) $file['tmp_name']))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Файл не пришёл.'], 400);
		}

		$bytes    = (string) file_get_contents((string) $file['tmp_name']);
		$build_id = (int) Arr::get($this->_body, 'build_id', 0);
		$part     = (int) Arr::get($this->_body, 'part', -1);
		$run_id   = (int) Arr::get($this->_body, 'parts_run_id', 0);
		$engine   = UTF8::substr(trim((string) Arr::get($this->_body, 'engine', '')), 0, 80);

		// kind=glb — то, что человек крутит в браузере; stl (по умолчанию) —
		// то, что несут в слайсер. Узлы всегда STL: их и печатают, и смотрят
		// одним файлом, а второй формат на тип узла только множит отбраковку.
		$kind = (string) Arr::get($this->_body, 'kind', 'stl');

		// preview — PNG 128 px узла (картинка для таблицы деталей), не модель.
		if ($part >= 0 AND $kind === 'preview')
		{
			$done = Service_Nj667_Graph3d::build_part_attach_preview($build_id, $part, $bytes,
				$run_id > 0 ? $run_id : NULL);

			return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
		}

		$done = $part >= 0
			? Service_Nj667_Graph3d::build_part_attach_stl($build_id, $part, $bytes, $engine,
				$run_id > 0 ? $run_id : NULL)
			: ($kind === 'glb'
				? Service_Nj667_Graph3d::build_attach_glb($build_id, $bytes, $engine)
				: Service_Nj667_Graph3d::build_attach_stl($build_id, $bytes, $engine));

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	/**
	 * POST /graph3d/w/build_result — итог рендера.
	 *
	 * ok=TRUE без файла не бывает «успехом»: если STL не приехал, сборка
	 * уедет в error с понятным текстом, а не повиснет «в очереди навсегда».
	 */
	public function action_build_result()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$build_id = (int) Arr::get($this->_body, 'build_id', 0);
		$run_id   = (int) Arr::get($this->_body, 'parts_run_id', 0);
		$build    = Service_Nj667_Graph3d::build_get($build_id);

		if ( ! is_array($build))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сборка не найдена.'], 404);
		}

		$ok    = ! empty($this->_body['ok']);
		$error = (string) Arr::get($this->_body, 'error', '');

		// Пачка узлов: итог по каждому узлу пишет сайт (файл узла уже мог
		// приехать отдельным запросом), сборка получает done, если собрано
		// хоть что-то — недобранное видно по статусам строк.
		$parts = (array) Arr::get($this->_body, 'parts', []);

		if ($parts !== [] OR $run_id > 0)
		{
			$done = Service_Nj667_Graph3d::build_parts_result($build_id, $parts,
				(int) Arr::get($this->_body, 'render_ms', 0), $error,
				$run_id > 0 ? $run_id : NULL);

			if ( ! Arr::get($done, 'ok'))
			{
				return $this->_respond($done, Arr::get($done, 'stale') ? 409 : 422);
			}

			$run_id = (int) Arr::get($done, 'run_id', $run_id);

			// Остаток повторно планируем только из выбранных для этого запуска
			// строк; новый part_index не может расширить исходное окно из 30.
			if (Service_Nj667_Graph3d::build_parts_spec_count($build_id, $run_id) > 0)
			{
				$next = Arr::get($done, 'done', 0) > 0
					? Service_Nj667_Graph3d::build_parts_continue($build_id, $run_id)
					: ['ok' => FALSE, 'error' => (string) Arr::get($done, 'note', 'пачка не дала ни одного узла')];

				// «ok» без задачи — не успех: остаток без задачи висел бы «ждёт» вечно.
				// Скобки обязательны: у AND приоритет ниже «=» (см. AGENTS.md, «= … OR …»).
				$queued = (bool) Arr::get($next, 'ok') && (int) Arr::get($next, 'job_id', 0) > 0;

				if ( ! $queued)
				{
					Service_Nj667_Graph3d::build_parts_stop($build_id,
						(string) Arr::get($next, 'error', 'следующая пачка не встала в очередь'), $run_id);
				}

				$done['next'] = $queued ? 'queued' : 'stopped';
			}

			return $this->_respond($done, 200);
		}

		// Проверяем именно ожидаемый формат. Старые задачи до миграции 014
		// сохраняют совместимость: для них подходит любой из двух файлов.
		$render_kind = strtolower(trim((string) Arr::get($build, 'render_kind', 'legacy')));
		$have_glb    = trim((string) Arr::get($build, 'glb_path', '')) !== '';
		$have_stl    = trim((string) Arr::get($build, 'stl_path', '')) !== '';

		if ($render_kind === 'glb')
		{
			$have = $have_glb;
			$missing = 'Воркер сообщил об успехе, но GLB не прислал.';
		}
		elseif ($render_kind === 'stl')
		{
			$have = $have_stl;
			$missing = 'Воркер сообщил об успехе, но STL не прислал.';
		}
		else
		{
			$have = ($have_glb OR $have_stl);
			$missing = 'Воркер сообщил об успехе, но ни STL, ни GLB не прислал.';
		}

		if ($ok AND ! $have)
		{
			$ok    = FALSE;
			$error = $missing;
		}

		$done = Service_Nj667_Graph3d::build_mark($build_id, $ok ? 'done' : 'error',
			$error, (int) Arr::get($this->_body, 'render_ms', 0));

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}
}
