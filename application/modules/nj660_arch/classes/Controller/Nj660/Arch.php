<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-660 «Конструктор архитектуры ИИ»: страницы.
 *
 * Контроллер тонкий: математика — Service_Nj660_Arch, LLM — Service_Nj660_Expert.
 * LLM-операции (review/simulate) — только для залогиненных (решение владельца
 * 06.10.2026: реальная LLM не должна быть анонимным бесплатным ресурсом);
 * конструктор, калькулятор и просмотр — публичны.
 */
class Controller_Nj660_Arch extends Controller_Erp100_Base {

	public function before()
	{
		parent::before();

		$this->set_title('Конструктор архитектуры ИИ — спроектируй и смоделируй нейросеть');
		$this->set_description('Визуальный конструктор архитектур нейросетей: MoE, GQA/MLA, RoPE, SSM/Mamba, мультимодальные входы, хуки в середину модели. Живой расчёт параметров, VRAM и бюджета обучения, экспертиза и симуляция поведения реальной LLM, экспорт PyTorch-скелета.');
		$this->set_keywords('конструктор архитектуры нейросети transformer moe эксперты llama gpt параметры vram flops обучение диффузия mamba мультимодальность');
		$this->add_script('/assets/js/nj660-arch.js', TRUE);

		$this->set_module_nav([
			['title' => 'Витрина', 'href' => '/arch', 'icon' => 'fas fa-diagram-project'],
			['title' => 'Конструктор', 'href' => '/arch/new', 'icon' => 'fas fa-plus'],
		]);
	}

	/** GET /arch — мои проекты, пресеты, зоопарк. */
	public function action_index()
	{
		$f = 'Service_Nj660_Arch';
		$designs = $f::mine(Visitor::uid(), $this->_current_user ? (int) $this->_current_user->id : NULL, 50);

		// к строкам добавляем метрики из кэша (пересчитывать всё — дорого)
		$rows = [];

		foreach ($designs as $row)
		{
			$metrics = json_decode((string) $row['metrics'], TRUE);
			$row['totals'] = (array) Arr::get($metrics, 'totals', []);
			$row['params'] = Arr::get($row['totals'], 'params_total', 0);
			$rows[] = $row;
		}

		$this->template->content = View::factory('660/index', [
			'rows'    => $rows,
			'presets' => $f::presets(),
			'zoo'     => $f::zoo(),
			'fmt'     => [$f, 'fmt_params'],
			'notice'  => (string) $this->request->query('notice'),
			'error'   => (string) $this->request->query('error'),
		]);
	}

	/** GET /arch/new(?preset=…) — конструктор с нуля или с заготовкой. */
	public function action_new()
	{
		$f = 'Service_Nj660_Arch';
		// ВАЖНО: query($key, $default) в Kohana — СЕТТЕР (второй аргумент не NULL),
		// поэтому читаем одним аргументом (NULL, если параметра нет)
		$preset_key = (string) $this->request->query('preset');
		$preset = NULL;

		if ($preset_key !== '')
		{
			$preset = $f::preset($preset_key);
		}

		$dsl = $preset ? $preset['dsl'] : [
			'family' => 'decoder',
			'config' => ['d' => 1024, 'S' => 4096, 'V' => 32000],
			'stack' => [
				['t' => 'emb', 'V' => 32000, 'tied' => TRUE],
				['t' => 'pos', 'v' => 'rope'],
				['t' => 'norm', 'v' => 'rms', 'n' => 8],
				['t' => 'attn', 'v' => 'gqa', 'H' => 16, 'KV' => 4, 'n' => 8],
				['t' => 'norm', 'v' => 'rms', 'n' => 8],
				['t' => 'ffn', 'v' => 'swiglu', 'h' => 2816, 'n' => 8],
				['t' => 'head', 'v' => 'lm'],
			],
		];

		$this->_editor($dsl, $preset ? $preset['title'] : '', '');
	}

	/** GET /arch/edit/<code> — конструктор со своим проектом. */
	public function action_edit()
	{
		$f = 'Service_Nj660_Arch';
		$design = $f::find((string) $this->request->param('code', ''));

		if ($design === NULL)
		{
			throw HTTP_Exception::factory(404, 'Проект не найден');
		}

		$user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

		if ( ! $this->_owns($design, $user_id))
		{
			throw HTTP_Exception::factory(403, 'Это не ваш проект — экспортируйте JSON и соберите свой');
		}

		$this->_editor(json_decode((string) $design['dsl'], TRUE), (string) $design['title'], (string) $design['code']);
	}

	/** GET /arch/view/<code> — страница модели. */
	public function action_view()
	{
		$f = 'Service_Nj660_Arch';
		$code = (string) $this->request->param('code', '');
		$design = $f::find($code);

		if ($design === NULL)
		{
			throw HTTP_Exception::factory(404, 'Проект не найден');
		}

		$dsl = json_decode((string) $design['dsl'], TRUE);
		$eval = $f::evaluate(is_array($dsl) ? $dsl : []);

		if (isset($eval['errors']))
		{
			throw HTTP_Exception::factory(500, 'DSL проекта невалиден: '.implode(' ', $eval['errors']));
		}

		$user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;
		$mine = $this->_owns($design, $user_id);
		$logged = Auth::instance()->logged_in();

		$reviews = [];
		$chats = [];

		foreach ($f::runs_for((int) $design['id'], 24) as $run)
		{
			if ($run['kind'] === 'review')
			{
				$run['parsed'] = json_decode((string) $run['output'], TRUE);
				$run['raw'] = (string) $run['output'];
				$reviews[] = $run;
			}
			else
			{
				$chats[] = $run;
			}
		}

		$this->template->content = View::factory('660/view', [
			'design'  => $design,
			'dsl'     => $dsl,
			'eval'    => $eval,
			'mine'    => $mine,
			'logged'  => $logged,
			'reviews' => $reviews,
			'chats'   => array_reverse($chats), // хронология: старые сверху
			'csrf'    => Security::token(),
			'engine'  => Service_Nj660_Expert::engine_model(),
			'llm_left' => $logged ? max(0, (int) Arr::get((array) Arr::get($f::config(), 'llm', []), 'simulate_per_hour', 20) - $f::runs_recent(Visitor::uid(), 'simulate')) : 0,
			'flash_saved'    => (bool) $this->request->query('saved'),
			'flash_reviewed' => (bool) $this->request->query('reviewed'),
			'error'   => (string) $this->request->query('error'),
		]);
	}

	/** POST /arch/save — создать/обновить проект. */
	public function action_save()
	{
		if ($this->request->method() !== Request::POST)
		{
			throw HTTP_Exception::factory(405, 'Только POST');
		}

		if ( ! Security::check((string) $this->request->post('csrf')))
		{
			throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
		}

		$f = 'Service_Nj660_Arch';
		$dsl = json_decode((string) Arr::get($_POST, 'dsl', ''), TRUE);

		if ( ! is_array($dsl))
		{
			throw HTTP_Exception::factory(400, 'Некорректный DSL');
		}

		$user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;
		$result = $f::save($dsl, (string) Arr::get($_POST, 'title', ''), Visitor::uid(), $user_id,
			(string) Arr::get($_POST, 'code', ''));

		if (isset($result['error']))
		{
			HTTP::redirect('/arch/new?error='.rawurlencode($result['error']), 303);
		}

		HTTP::redirect('/arch/view/'.$result['code'].'?saved=1', 303);
	}

	/** POST /arch/delete — удалить свой проект. */
	public function action_delete()
	{
		if ($this->request->method() !== Request::POST)
		{
			throw HTTP_Exception::factory(405, 'Только POST');
		}

		if ( ! Security::check((string) $this->request->post('csrf')))
		{
			throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
		}

		$f = 'Service_Nj660_Arch';
		$user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;

		if ($f::delete((string) Arr::get($_POST, 'code', ''), Visitor::uid(), $user_id))
		{
			HTTP::redirect('/arch?notice='.rawurlencode('Проект удалён'), 303);
		}

		HTTP::redirect('/arch?error='.rawurlencode('Проект не найден или не ваш'), 303);
	}

	/** POST /arch/review — LLM-экспертиза (требуется вход). */
	public function action_review()
	{
		if ($this->request->method() !== Request::POST)
		{
			throw HTTP_Exception::factory(405, 'Только POST');
		}

		if ( ! Auth::instance()->logged_in())
		{
			HTTP::redirect('/auth/login', 302);
		}

		if ( ! Security::check((string) $this->request->post('csrf')))
		{
			throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
		}

		$f = 'Service_Nj660_Arch';
		$code = (string) Arr::get($_POST, 'code', '');
		$design = $f::find($code);

		if ($design === NULL)
		{
			throw HTTP_Exception::factory(404, 'Проект не найден');
		}

		$cfg = (array) Arr::get($f::config(), 'llm', []);

		if ($f::runs_recent(Visitor::uid(), 'review') >= (int) Arr::get($cfg, 'review_per_hour', 6))
		{
			HTTP::redirect('/arch/view/'.$code.'?error='.rawurlencode('Лимит экспертиз: '.Arr::get($cfg, 'review_per_hour', 6).' в час — чуть позже'), 303);
		}

		$dsl = json_decode((string) $design['dsl'], TRUE);
		$eval = $f::evaluate(is_array($dsl) ? $dsl : []);

		if (isset($eval['errors']))
		{
			HTTP::redirect('/arch/view/'.$code.'?error='.rawurlencode('DSL невалиден'), 303);
		}

		$user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;
		$result = Service_Nj660_Expert::review($design, $eval);

		$f::log_run((int) $design['id'], 'review', Visitor::uid(), $user_id, '',
			(string) Arr::get($result, 'model', ''), (int) Arr::get($result, 'ms', 0),
			isset($result['review']) && $result['review'] !== NULL
				? json_encode($result['review'], JSON_UNESCAPED_UNICODE)
				: (string) Arr::get($result, 'answer', ''),
			(string) Arr::get($result, 'error', ''));

		if (empty($result['ok']))
		{
			HTTP::redirect('/arch/view/'.$code.'?error='.rawurlencode('Движок не ответил: '.Arr::get($result, 'error', '')), 303);
		}

		HTTP::redirect('/arch/view/'.$code.'?reviewed=1', 303);
	}

	/** GET /arch/export/<pytorch|json>/<code> */
	public function action_export()
	{
		$f = 'Service_Nj660_Arch';
		$format = (string) $this->request->param('format', 'json');
		$design = $f::find((string) $this->request->param('code', ''));

		if ($design === NULL)
		{
			throw HTTP_Exception::factory(404, 'Проект не найден');
		}

		$dsl = json_decode((string) $design['dsl'], TRUE);

		if ($format === 'pytorch')
		{
			$eval = $f::evaluate(is_array($dsl) ? $dsl : []);
			$totals = (array) Arr::get($eval, 'totals', []);
			$body = $f::pytorch(is_array($dsl) ? $dsl : [], $totals ?: NULL, (string) $design['title']);

			$this->response->headers('Content-Type', 'text/x-python; charset=UTF-8');
			$this->response->headers('Content-Disposition', 'attachment; filename="nj660_'.preg_replace('/[^a-z0-9]+/', '', strtolower((string) $design['code'])).'.py"');
			$this->response->body($body);
			return;
		}

		$this->response->headers('Content-Type', 'application/json; charset=UTF-8');
		$this->response->headers('Content-Disposition', 'attachment; filename="nj660_'.$design['code'].'.json"');
		$this->response->body(json_encode([
			'title'  => (string) $design['title'],
			'family' => (string) $design['family'],
			'dsl'    => $dsl,
			'format' => 'n2j.nj660.arch.v1',
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	}

	// ------------------------------------------------------------------

	/** Общая сборка страницы конструктора. */
	protected function _editor(array $dsl, $title, $code)
	{
		$f = 'Service_Nj660_Arch';
		$this->template->title = 'Конструктор архитектуры — '.($title !== '' ? $title : 'новая модель');

		$this->template->content = View::factory('660/edit', [
			'dsl'     => $dsl,
			'title'   => $title,
			'code'    => $code,
			'palette' => $f::palette(),
			'families'=> $f::families(),
			'csrf'    => Security::token(),
			'error'   => (string) $this->request->query('error'),
		]);
	}

	protected function _owns(array $design, $user_id)
	{
		if ($user_id !== NULL AND (int) Arr::get($design, 'user_id', 0) === (int) $user_id)
		{
			return TRUE;
		}

		return Arr::get($design, 'visitor', '') === Visitor::uid() AND empty($design['user_id']);
	}
}
