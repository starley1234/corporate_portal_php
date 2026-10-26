<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ERP-222 «Кузница систем»: каталог артефактов и страницы артефактов.
 *
 * Почему каталог публичный: артефакты Кузницы — это готовые документы
 * (спецификация, схема данных, контракт API, алгоритм, промпт, план проверки,
 * риски, метрики, оценка, оффер). Их ценность растёт, когда их читают: по
 * спецификации можно начать работу, схему — применить, промпт — скопировать.
 * Поэтому у каталога человекочитаемые строки, поиск, фильтр по типу, пагинация
 * общим партиалом `partials/pagination` — ровно как у /trends/catalog и
 * /airadar/catalog (владелец, 08.10.2026: «каталог — для людей и СЕО»).
 *
 * Снятие с публикации — у владельца: черновик с ценностью 20 не должен попадать
 * в индекс, но страница остаётся ему доступной (404 остальным — как у обзоров и
 * отчётов).
 */
class Controller_Erp222_Artifacts extends Controller_Erp222_Main {

	/** GET /forge/artifacts — каталог опубликованных артефактов. */
	public function action_index()
	{
		$q    = trim((string) Arr::get($_GET, 'q', ''));
		$type = trim((string) Arr::get($_GET, 'type', ''));
		$per  = (int) Arr::get($_GET, 'per', 10);
		$per  = in_array($per, [10, 20, 50], TRUE) ? $per : 10;

		$total = Service_Erp222_Forge::count_artifacts($q, $type);
		$pages = max(1, (int) ceil($total / $per));
		$page  = min($pages, max(1, (int) Arr::get($_GET, 'page', 1)));

		$this->set_title($q === ''
			? 'Каталог артефактов Кузницы систем — спецификации, схемы, API, промпты'
			: 'Поиск по артефактам Кузницы: '.$q);
		$this->set_description('Готовые артефакты синтеза информационных систем: спецификации, схемы данных, контракты API, алгоритмы, промпты, планы проверки, риски, метрики, оценки и офферы — с оценкой ценности и разбором Критика.');
		$this->set_keywords('каталог артефактов спецификация схема данных api алгоритм промпт тестплан риски метрики оценка оффер синтез систем');

		$this->template->content = View::factory('erp222/artifacts', [
			'rows'        => Service_Erp222_Forge::search_artifacts($q, $type, $page, $per, ! $this->_is_owner),
			'q'           => $q,
			'type'        => $type,
			'type_counts' => Service_Erp222_Artifact::type_counts(! $this->_is_owner),
			'page'        => $page,
			'pages'       => $pages,
			'total'       => $total,
			'per'         => $per,
			'per_options' => [10, 20, 50],
			'types'       => Service_Erp222_Forge::types(),
			'csrf'        => Security::token(),
			'is_owner'    => $this->_is_owner,
			'flash'       => $this->_flash(),
		]);
	}

	/** GET /forge/artifact/<id> — страница артефакта. */
	public function action_view()
	{
		$id = (int) $this->request->param('id', 0);
		$artifact = Service_Erp222_Forge::artifact($id, $this->_is_owner);

		if ($artifact === NULL)
		{
			throw HTTP_Exception::factory(404, 'Артефакт не найден');
		}

		$title = trim((string) $artifact['title']);
		$lead  = trim((string) $artifact['summary']);

		$this->set_title(($title !== '' ? $title : 'Артефакт #'.$id).' — Кузница систем');
		$this->set_description($lead !== '' ? mb_substr($lead, 0, 300) : 'Артефакт синтеза системы: '.$artifact['type_title'].'.');

		$this->template->content = View::factory('erp222/artifact', [
			'artifact' => $artifact,
			'pages'    => Service_Erp222_Artifact::document_pages((string) $artifact['body']),
			'links'    => Service_Erp222_Artifact::links($id),
			'world'    => Service_Erp222_Forge::world((int) $artifact['world_id']),
			'events'   => Service_Erp222_Forge::events(0, 10, (int) $artifact['world_id']),
			'csrf'     => Security::token(),
			'is_owner' => $this->_is_owner,
			'flash'    => $this->_flash(),
		]);
	}

	/**
	 * GET /forge/artifact/<id>/md — скачать документ в markdown.
	 *
	 * Страница показывает документ HTML-ом (с разбивкой на
	 * страницы), но в работу его забирают исходником:
	 * спецификацию правят, промпт вставляют в код.
	 * Поэтому рядом с «Скопировать» стоит честное «Скачать .md».
	 *
	 * Доступ — тот же, что у страницы артефакта: неопубликованный
	 * видит только владелец (Service_Erp222_Forge::artifact() проверяет это сам).
	 */
	public function action_md()
	{
		$id       = (int) $this->request->param('id', 0);
		$artifact = Service_Erp222_Forge::artifact($id, $this->_is_owner);

		if ($artifact === NULL)
		{
			throw HTTP_Exception::factory(404, 'Артефакт не найден');
		}

		$name = 'forge-artifact-'.$id.'.md';

		$this->auto_render = FALSE;
		$this->response
			->headers('Content-Type', 'text/markdown; charset=UTF-8')
			->headers('Content-Disposition', 'attachment; filename="'.$name.'"')
			->headers('Cache-Control', 'private, max-age=0, must-revalidate')
			->headers('X-Content-Type-Options', 'nosniff')
			->body((string) $artifact['body']);
	}

	/**
	 * POST /forge/artifact/<id>/publish — снять артефакт с публикации или вернуть.
	 *
	 * Артефакт остаётся на месте (страница работает у владельца) — из каталога и
	 * из карты сайта он исчезает. Кнопка «взведённая»: первый клик показывает
	 * «Точно снять?», второй отправляет (window.confirm в проекте не используем).
	 */
	public function action_publish()
	{
		$this->_post_only();

		$id = (int) $this->request->param('id', 0);
		$artifact = Service_Erp222_Forge::artifact($id, TRUE);

		if ($artifact === NULL)
		{
			throw HTTP_Exception::factory(404, 'Артефакт не найден');
		}

		$want = (string) Arr::get($_POST, 'published', '') === '1';

		Service_Erp222_Forge::set_published($id, $want);

		$this->_flash_set('notice', $want
			? 'Артефакт снова в каталоге'
			: 'Артефакт снят с публикации — в каталоге его нет, страница работает только у вас');

		HTTP::redirect('/forge/artifact/'.$id, 303);
	}
}
