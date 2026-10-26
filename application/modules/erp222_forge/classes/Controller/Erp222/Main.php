<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ERP-222 «Кузница систем»: базовый контроллер страниц.
 *
 * Владелец (08.10.2026): «интегрируй наработки source_data/erp222_aeterna_forge…».
 * Наработки были открыты всем, кто пройдёт по ACL-правам saas_forge.*, которых
 * в платформе нет (страницы отвечали 403 всем, кроме админа, либо падали).
 * Здесь доступ разложен так же, как в ИИ-радаре и трендах:
 *
 *   · КАТАЛОГ АРТЕФАКТОВ и страницы опубликованных артефактов — публичные:
 *     готовые спецификации, схемы и API-контракты читают люди и индексируют
 *     поисковики (их и просил владелец: «чтобы модуль формировал ценные и
 *     полезные артефакты и был интересен»);
 *   · всё остальное (дашборд, миры, протоколы, циклы, публикация) — владельцу.
 *
 * Стиль — дизайн-эталон /pages/required_design_sample: карточки rounded-xl,
 * элементы управления rounded-lg, min-h-[44px], статус-ряд «спиннер + этап +
 * секундомер» одним неразрывным рядом, без полос хода.
 */
class Controller_Erp222_Main extends Controller_Erp100_Base {

	/** Владелец/администратор: ему доступны циклы, миры и публикация. */
	protected $_is_owner = FALSE;

	/** Действия, открытые людям и поисковикам. */
	protected $_public_actions = ['index', 'view'];

	public function before()
	{
		parent::before();

		$this->_is_owner = (bool) $this->can('erp222.forge');

		$action = (string) $this->request->action();

		// Публичность — по контроллеру: каталог и карточки артефактов открыты,
		// остальные разделы Кузницы закрыты.
		$controller = strtolower((string) $this->request->controller());
		$is_artifacts = (strpos($controller, 'artifacts') !== FALSE);

		if ( ! $is_artifacts AND ! $this->_is_owner)
		{
			throw HTTP_Exception::factory(403, 'Кузница систем — рабочий инструмент владельца; открыт только каталог артефактов');
		}

		$this->set_title('Кузница систем — синтез информационных систем');
		$this->set_description('Группа LLM-ролей проектирует информационную систему: спецификация, схема данных, контракт API, алгоритм, промпт, план проверки, риски, метрики, оценка и оффер — с ценностью каждого артефакта.');
		$this->set_keywords('синтез систем кузница артефакты спецификация схема данных api алгоритм промпт риски метрики оценка оффер llm агенты');

		$this->add_script('/assets/js/erp222-forge.js', TRUE);

		// Чипы модуля: гостю — только каталог, иначе он видел бы ссылки на 403.
		$nav = [
			['title' => 'Артефакты', 'href' => '/forge/artifacts', 'icon' => 'fas fa-cubes'],
		];

		if ($this->_is_owner)
		{
			$nav = [
				['title' => 'Кузница',   'href' => '/forge',           'icon' => 'fas fa-hammer'],
				['title' => 'Миры',      'href' => '/forge/worlds',    'icon' => 'fas fa-globe'],
				['title' => 'Артефакты', 'href' => '/forge/artifacts', 'icon' => 'fas fa-cubes'],
			];
		}

		$this->set_module_nav($nav);

		// Страницы не кэшируем: живой ход цикла и поллинг ломаются кэшем.
		$this->response->headers('Cache-Control', 'private, no-cache, max-age=0');
		$this->response->headers('Pragma', 'no-cache');
	}

	/** Только POST (страницы действий): 405 + проверка CSRF, иначе 403. */
	protected function _post_only()
	{
		if ($this->request->method() !== Request::POST)
		{
			throw HTTP_Exception::factory(405, 'Действие доступно только POST-запросом');
		}

		if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
		{
			throw HTTP_Exception::factory(403, 'Сессия устарела — обновите страницу и повторите');
		}
	}

	/** Плашка-сообщение в сессии (переживает redirect, как в nj593/nj661). */
	protected function _flash_set($tone, $message)
	{
		Session::instance()->set('erp222_flash', ['tone' => (string) $tone, 'text' => (string) $message]);
	}

	/**
	 * Забрать плашку из сессии: сообщения — только разовые.
	 *
	 * Раньше в наработках уведомления передавались параметрами URL и жили в
	 * ссылке; плашку в сессии нельзя случайно «показать дважды», переслав ссылку.
	 */
	protected function _flash()
	{
		$flash = Session::instance()->get('erp222_flash');
		Session::instance()->delete('erp222_flash');

		$flash = is_array($flash) ? $flash : [];

		return [
			'tone' => (string) Arr::get($flash, 'tone', ''),
			'text' => (string) Arr::get($flash, 'text', ''),
		];
	}
}
