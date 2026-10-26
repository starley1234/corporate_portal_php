<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj661 «ИИ-радар»: админские страницы и действия владельца.
 *
 * Личный инструмент (audience admin): дашборд с последним отчётом и кнопками
 * «собрать сейчас»/«прислать письмо»/«проверить расписание», лента материалов,
 * база знаний (факты), борд идей «что реализовать на n2j» со статусами.
 * Всё действие — через Service_Nj661_Airadar; контроллер тонкий.
 */
class Controller_Nj661_Airadar extends Controller_Erp100_Base {

	/** Владелец/администратор: ему доступны прогоны, материалы и публикация. */
	protected $_is_owner = FALSE;

	/** Действия, открытые для людей и поисковиков (каталог и опубликованный отчёт). */
	protected $_public_actions = ['catalog', 'report'];

	public function before()
	{
		parent::before();

		$this->_is_owner = (bool) $this->can('nj661.airadar');

		// Каталог опубликованных отчётов и страница опубликованного отчёта —
		// ПУБЛИЧНЫЕ (владелец, 08.10.2026: «каталог опубликованных обзоров — для
		// людей и СЕО», как в трендах). Всё остальное в модуле — личный
		// инструмент: прогоны, материалы, база знаний, идеи и переключатель
		// публикации остаются под замком.
		if ( ! in_array($this->request->action(), $this->_public_actions, TRUE) AND ! $this->_is_owner)
		{
			throw HTTP_Exception::factory(403, 'ИИ-радар — личный инструмент владельца');
		}

		$this->set_title('ИИ-радар — прогресс и окна возможностей');
		$this->set_description('Конвейер наблюдения за прогрессом ИИ: открытые модели и фреймворки, окна возможностей, база знаний из фактов, отчёты и письма с идеями для N2J.');
		$this->set_keywords('ии радар прогресс открытые модели llm фреймворки бесплатные токены отчет база знаний');
		$this->add_script('/assets/js/nj661-airadar.js', TRUE);

		// Чипы модуля: гостю — только то, что ему открыто (каталог), иначе он
		// видел бы ссылки на страницы, которые отвечают 403.
		$nav = [
			['title' => 'Отчёты', 'href' => '/airadar/catalog', 'icon' => 'fas fa-book-open'],
		];

		if ($this->_is_owner)
		{
			$nav = [
				['title' => 'Дашборд',  'href' => '/airadar',        'icon' => 'fas fa-satellite-dish'],
				['title' => 'Отчёты',   'href' => '/airadar/catalog','icon' => 'fas fa-book-open'],
				['title' => 'Материалы', 'href' => '/airadar/items', 'icon' => 'fas fa-stream'],
				['title' => 'База знаний', 'href' => '/airadar/facts', 'icon' => 'fas fa-brain'],
				['title' => 'Идеи', 'href' => '/airadar/ideas',      'icon' => 'fas fa-lightbulb'],
			];
		}

		$this->set_module_nav($nav);
	}

	/** GET /airadar — дашборд. */
	public function action_index()
	{
		$f = 'Service_Nj661_Airadar';
		$ov = $f::overview();

		$this->template->content = View::factory('661/index', [
			'ov'         => $ov,
			'categories' => $f::$categories,
			'csrf'       => Security::token(),
			'flash'      => $this->_flash(),
			'mail_to'    => (string) Arr::get((array) $f::config('mail', []), 'to_email', ''),
			// Живой статус-ряд: этап, секундомер и лестница этапов считаются
			// сервисом (Service_Nj661_Airadar::run_progress) — на дашборде и в
			// /airadar/progress один и тот же расчёт.
			'progress'   => $f::run_progress($ov['run']),
			// Каталог отчётов: дайджест последней публикации + счётчик
			// (владелец, 08.10.2026 — «такую же страницу-каталог в /airadar»).
			'latest_published' => $f::latest_published(),
			'catalog_total'    => $f::count_published(),
		]);
	}

	/**
	 * GET /airadar/catalog — каталог опубликованных отчётов.
	 *
	 * «Как в трендах»: читаемый список, свежие сверху, пагинация общим партиалом
	 * и поиск по заголовку, сводке и разделам. Страницы модуля — личный
	 * инструмент владельца, поэтому замок тот же (before()), а вот отчёты можно
	 * снимать с публикации: прогон-черновик не должен мешать смотреть важное.
	 */
	public function action_catalog()
	{
		$f = 'Service_Nj661_Airadar';

		$q     = trim((string) Arr::get($_GET, 'q', ''));
		$per   = (int) Arr::get($_GET, 'per', 10);
		$per   = in_array($per, [10, 20, 50], TRUE) ? $per : 10;
		$total = $f::count_published($q);
		$pages = max(1, (int) ceil($total / $per));
		$page  = min($pages, max(1, (int) Arr::get($_GET, 'page', 1)));

		// SEO: у каталога свой заголовок под запрос, а не «ИИ-радар» целиком.
		$this->set_title($q === ''
			? 'Отчёты ИИ-радара — что нового в искусственном интеллекте'
			: 'Поиск по отчётам ИИ-радара: '.$q);
		$this->set_description('Опубликованные отчёты конвейера «ИИ-радар» одним списком: свежие сверху, с датой прогона, сводкой и числами — сколько источников, материалов, фактов и идей нашлось.');
		$this->set_keywords('ии радар, отчеты, каталог отчетов, новости искусственного интеллекта, открытые модели, llm, фреймворки, дайджест');

		$this->template->content = View::factory('661/catalog', [
			'rows'        => $f::search_published($q, $page, $per),
			'q'           => $q,
			'page'        => $page,
			'pages'       => $pages,
			'total'       => $total,
			'per'         => $per,
			'per_options' => [10, 20, 50],
			'csrf'        => Security::token(),
			'flash'       => $this->_flash(),
			'is_owner'    => $this->_is_owner,
		]);
	}

	/**
	 * POST /airadar/report/<id>/publish — снять отчёт с публикации или вернуть.
	 *
	 * Отчёт остаётся на месте (/airadar/report/<id> работает) — из каталога он
	 * только исчезает: так пробные прогоны не мешают содержательным.
	 */
	public function action_publish()
	{
		$this->_post_only();

		$f  = 'Service_Nj661_Airadar';
		$id = (int) $this->request->param('id', 0);

		if ($f::report($id) === NULL)
		{
			throw HTTP_Exception::factory(404, 'Отчёт не найден');
		}

		$want = (string) Arr::get($_POST, 'published', '') === '1';

		$this->_flash_set($f::set_published($id, $want)
			? ['notice', $want ? 'Отчёт снова в каталоге' : 'Отчёт снят с публикации — в каталоге его нет']
			: ['error', 'Не удалось изменить публикацию — попробуйте ещё раз']);

		HTTP::redirect('/airadar/catalog', 303);
	}

	/** GET /airadar/report/<id> — полный отчёт. */
	public function action_report()
	{
		$f = 'Service_Nj661_Airadar';
		$report = $f::report((int) $this->request->param('id', 0));

		if ($report === NULL)
		{
			throw HTTP_Exception::factory(404, 'Отчёт не найден');
		}

		// Снятый с публикации отчёт видят только владелец и администратор: для
		// остальных страницы нет (как у снятого обзора в трендах).
		if ( ! $this->_is_owner AND ! $f::is_published($report))
		{
			throw HTTP_Exception::factory(404, 'Отчёт не найден');
		}

		// SEO: страница отчёта — самостоятельная статья, заголовок и описание от неё.
		$title = trim((string) Arr::get($report, 'title', ''));
		$lead  = trim(preg_replace('/\s+/u', ' ', (string) Arr::get($report, 'summary', '')));

		$this->set_title(($title !== '' ? $title : 'Отчёт ИИ-радара #'.(int) $report['id']).' — ИИ-радар');
		$this->set_description($lead !== ''
			? mb_substr($lead, 0, 300)
			: 'Отчёт конвейера «ИИ-радар»: что нашлось в прогрессе ИИ и что из этого применить на n2j.');

		$this->template->content = View::factory('661/report', [
			'report'    => $report,
			'csrf'      => Security::token(),
			'flash'     => $this->_flash(),
			// Публикация: отчёт по умолчанию в каталоге; снять и вернуть можно
			// тут же, в два клика — но только владельцу.
			'published' => $f::is_published($report),
			'is_owner'  => $this->_is_owner,
		]);
	}

	/** GET /airadar/items — лента материалов с фильтрами. */
	public function action_items()
	{
		$f = 'Service_Nj661_Airadar';
		$this->template->content = View::factory('661/items', [
			'rows'       => $f::items_list([
				'q'        => (string) $this->request->query('q'),
				'source'   => (string) $this->request->query('source'),
				'category' => (string) $this->request->query('category'),
				'status'   => (string) $this->request->query('status'),
				'starred'  => (bool) $this->request->query('starred'),
			]),
			'categories' => $f::$categories,
			'csrf'       => Security::token(),
			'q'          => (string) $this->request->query('q'),
			'source'     => (string) $this->request->query('source'),
			'category'   => (string) $this->request->query('category'),
			'status'     => (string) $this->request->query('status'),
		]);
	}

	/** GET /airadar/facts — база знаний. */
	public function action_facts()
	{
		$f = 'Service_Nj661_Airadar';
		$this->template->content = View::factory('661/facts', [
			'rows' => $f::facts_list((string) $this->request->query('q')),
			'q'    => (string) $this->request->query('q'),
		]);
	}

	/** GET /airadar/ideas — борд идей. */
	public function action_ideas()
	{
		$f = 'Service_Nj661_Airadar';
		$status = (string) $this->request->query('status');
		$this->template->content = View::factory('661/ideas', [
			'rows'   => $f::ideas_list($status),
			'status' => $status,
			'csrf'   => Security::token(),
			'flash'  => $this->_flash(),
		]);
	}

	/**
	 * GET /airadar/progress — JSON хода прогона (поллинг дашборда).
	 *
	 * Форма ответа одна с серверной отрисовкой (Service_Nj661_Airadar::run_progress):
	 * этап, заметка этапа, индекс в лестнице этапов, секундомер и счётчики —
	 * поэтому статус-ряд не «прыгает» при первом же опросе.
	 */
	public function action_progress()
	{
		$this->auto_render = FALSE;
		$this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

		$f   = 'Service_Nj661_Airadar';
		$run = $f::active_run();

		$this->response->body(json_encode([
			'ok'        => TRUE,
			'run'       => $f::run_progress($run),
			'pulse'     => $run !== NULL,
			'published' => $f::count_published(),
		], JSON_UNESCAPED_UNICODE));

		return $this->response;
	}

	/**
	 * POST /airadar/run — собрать сейчас.
	 *
	 * Два ответа на одно действие (как в /similar/find):
	 *   · fetch дашборда (X-Requested-With) получает JSON — страница не
	 *     перезагружается, статус-ряд оживает сразу и показывает этап;
	 *   · обычная отправка формы без JS получает прежний 303 на дашборд.
	 */
	public function action_run()
	{
		$this->_post_only();

		$f     = 'Service_Nj661_Airadar';
		$res   = $f::start_run('manual', $this->_current_user ? (int) $this->_current_user->id : NULL);
		$error = (string) ($res['error'] ?? '');

		if ($this->request->is_ajax())
		{
			$this->auto_render = FALSE;
			$this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
			$this->response->body(json_encode([
				'ok'    => $error === '',
				'error' => $error,
				'run'   => $f::run_progress($f::active_run()),
			], JSON_UNESCAPED_UNICODE));

			return $this->response;
		}

		$this->_flash_set($error !== ''
			? ['error', $error]
			: ['notice', 'Прогон запущен — конвейер работает, ход виден на дашборде']);
		HTTP::redirect('/airadar', 303);
	}

	/** POST /airadar/mail — прислать письмо с последним отчётом сейчас. */
	public function action_mail()
	{
		$this->_post_only();

		$reports = Service_Nj661_Airadar::reports(1);

		if ( ! $reports)
		{
			$this->_flash_set(['error', 'Отчётов ещё нет — сначала запустите прогон']);
			HTTP::redirect('/airadar', 303);
		}

		$res = Service_Nj661_Airadar::send_report_mail((int) $reports[0]['id'], TRUE);

		$this->_flash_set(! empty($res['mailed'])
			? ['notice', 'Письмо: '.$res['reason']]
			: ['error', 'Письмо: '.$res['reason']]);
		HTTP::redirect('/airadar', 303);
	}

	/** POST /airadar/tick — проверить расписание вручную. */
	public function action_tick()
	{
		$this->_post_only();

		$res = Service_Nj661_Airadar::tick();
		$started = ! empty($res['started']);
		$msg = $started ? 'Расписание: прогон запущен' : 'Расписание: '.Arr::get($res, 'reason', 'не время');

		$this->_flash_set(['notice', $msg]);
		HTTP::redirect('/airadar', 303);
	}

	/** POST /airadar/idea — статус идеи (борд). */
	public function action_idea()
	{
		$this->_post_only();

		$id = (int) Arr::get($_POST, 'id', 0);
		$status = (string) Arr::get($_POST, 'status', '');

		if ($id > 0 AND Service_Nj661_Airadar::idea_set_status($id, $status))
		{
			$this->_flash_set(['notice', 'Статус идеи обновлён']);
			HTTP::redirect('/airadar/ideas', 303);
		}

		$this->_flash_set(['error', 'Не удалось обновить статус идеи']);
		HTTP::redirect('/airadar/ideas', 303);
	}

	/** POST /airadar/star — избранное материала. */
	public function action_star()
	{
		$this->_post_only();

		$id = (int) Arr::get($_POST, 'id', 0);
		$starred = (bool) Arr::get($_POST, 'starred', FALSE);

		if ($id > 0)
		{
			Service_Nj661_Airadar::item_star($id, $starred);
		}

		HTTP::redirect('/airadar/items'.($starred ? '?starred=1' : ''), 303);
	}

	// ------------------------------------------------------------------

	/** POST + CSRF, иначе 405/403. */
	protected function _post_only()
	{
		if ($this->request->method() !== Request::POST)
		{
			throw HTTP_Exception::factory(405, 'Только POST');
		}

		if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
		{
			throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
		}
	}

	/**
	 * Флеш-сообщение после POST-действий — в сессию, а не в адрес: адрес
	 * остаётся чистым (замечание владельца 06.10.2026 — «?notice= мусорит
	 * адресную строку»), ссылку можно переслать без чужих уведомлений.
	 */
	protected function _flash_set(array $msg)
	{
		// [тип, текст]: тип notice|error — те же ключи, что рисуют вьюхи
		Session::instance()->set('nj661_flash', [
			'type' => $msg[0] === 'error' ? 'error' : 'notice',
			'text' => (string) $msg[1],
		]);
	}

	/** Флеш из сессии (читается один раз, до вьюхи). */
	protected function _flash()
	{
		$flash = Session::instance()->get_once('nj661_flash');
		$type  = (is_array($flash) AND isset($flash['type'])) ? (string) $flash['type'] : '';

		return [
			'notice' => $type === 'notice' ? (string) ($flash['text'] ?? '') : '',
			'error'  => $type === 'error' ? (string) ($flash['text'] ?? '') : '',
		];
	}
}
