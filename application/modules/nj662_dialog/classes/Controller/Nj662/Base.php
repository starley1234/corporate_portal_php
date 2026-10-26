<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-662 «Диалог двух ИИ»: базовый контроллер страниц.
 *
 * Доступ разложен по образцу erp222/nj654, но с двумя поправками (вторая —
 * решение владельца 09.10.2026):
 *
 *   · читать опубликованные разговоры может кто угодно — их страницы,
 *     читательский вид (/dialog/read/<id>) и каталог открыты и объявлены
 *     в карте сайта (nj602_content), как /forge/artifacts;
 *   · ЗАПУСК разговора (создание, «Продолжить», быстрый старт) — только по
 *     праву `nj662.start`: роль `vip` или админ. Обычный аккаунт видит вместо
 *     формы объяснение (662/gate) и ведёт диалоги, запущенные раньше: каждый
 *     обмен — это модель и очередь задач, тратить их без права нельзя;
 *   · чужие диалоги не видны никому, кроме админа (владение проверяет сервис);
 *     сводка всех диалогов сайта (/dialog/all) — по праву `nj662.manage`.
 *
 * Стиль — дизайн-эталон /pages/required_design_sample: карточки rounded-xl,
 * элементы управления rounded-lg с min-h-[44px], статус-ряд «спиннер + этап +
 * секундомер» одним неразрывным рядом, полос хода нет.
 */
class Controller_Nj662_Base extends Controller_Erp100_Base {

	/** id владельца текущей сессии (0 — гость). */
	protected $_user_id = 0;

	/** Админ: видит и чужие диалоги, и монитор очереди. */
	protected $_is_admin = FALSE;

	/**
	 * Право ЗАПУСКАТЬ диалог: роль vip или админ (решение владельца
	 * 09.10.2026). Обычный зарегистрированный аккаунт читает опубликованные
	 * разговоры и ведёт свои, если завёл их раньше, — но не тратит очередь.
	 */
	protected $_can_start = FALSE;

	/** Право видеть сводку ВСЕХ диалогов сайта (/dialog/all). */
	protected $_can_manage = FALSE;

	public function before()
	{
		parent::before();

		$this->_user_id   = $this->_current_user ? (int) $this->_current_user->id : 0;
		$this->_is_admin  = (bool) $this->can('nj662.dialog');
		$this->_can_start = (bool) $this->can('nj662.start');
		$this->_can_manage = (bool) $this->can('nj662.manage');

		$this->add_script('/assets/js/nj662-dialog.js', TRUE);

		$nav = [
			['title' => 'Мои диалоги',  'href' => '/dialog',      'icon' => 'fas fa-comments'],
			['title' => 'Каталог',      'href' => '/dialog/list', 'icon' => 'fas fa-book-open'],
		];

		// Сводка всех диалогов — только админу: ссылку не показываем тем,
		// кому страница всё равно ответит 403 (меню не должно обещать лишнего).
		if ($this->_can_manage)
		{
			$nav[] = ['title' => 'Все диалоги', 'href' => '/dialog/all', 'icon' => 'fas fa-table-list'];
		}

		$this->set_module_nav($nav);

		$this->set_title('Диалог двух ИИ — два агента обсуждают вашу тему');
		$this->set_description('Два ИИ-агента обсуждают тему по делу: сервер ведёт обмен репликами, агенты при необходимости ищут в интернете, а готовый разговор можно опубликовать.');
		$this->set_keywords('диалог двух ии агенты разговор двух моделей дебаты обсуждение llm очередь воркер поиск в интернете duckduckgo семантический индекс');

		// Страницы не кэшируем: живой ход обмена и поллинг ломаются кэшем.
		$this->response->headers('Cache-Control', 'private, no-cache, max-age=0');
		$this->response->headers('Pragma', 'no-cache');
	}

	/** Плашка «Сервис для зарегистрированных» вместо рабочей области. */
	protected function _wall($return_url = 'dialog')
	{
		return Gate::wall($return_url, [
			'icon'   => 'fas fa-comments',
			'title'  => 'Диалог двух ИИ — по аккаунту',
			'text'   => 'Разговор двух агентов идёт на сервере: каждый обмен — это работа моделей, '
				.'поиск в интернете и запись в базу. Поэтому создать диалог можно по аккаунту, а читать опубликованные разговоры — всем.',
			'points' => [
				'Задаёте тему — два агента обсуждают её по делу и не «пересказывают» друг друга',
				'Разрешаете поиск в интернете — агенты ищут факты и ссылаются на источники',
				'Положите материалы (текст или ссылку) — агенты опираются на них',
				'Готовый разговор публикуется одной кнопкой: страница для людей и поисковиков',
			],
		]);
	}

	/**
	 * Плашка «запуск — привилегированным» для вошедшего без права nj662.start.
	 *
	 * Почему не Gate::wall(): та плашка зовёт ВОЙТИ, а человек уже вошёл.
	 * Ему нужно другое — что доступ к запуску дают отдельно (роль vip или
	 * админ), и что читать опубликованные разговоры можно без него.
	 */
	protected function _gate_start()
	{
		return View::factory('662/gate', [
			'logged' => $this->_user_id > 0,
			'wall'   => $this->_user_id > 0 ? NULL : $this->_wall('dialog'),
		]);
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

	/** Плашка-сообщение в сессии (переживает redirect, как в nj593/nj661/erp222). */
	protected function _flash_set($tone, $message)
	{
		Session::instance()->set('nj662_flash', ['tone' => (string) $tone, 'text' => (string) $message]);
	}

	/** Забрать плашку из сессии: сообщения — только разовые. */
	protected function _flash()
	{
		$flash = Session::instance()->get('nj662_flash');
		Session::instance()->delete('nj662_flash');

		$flash = is_array($flash) ? $flash : [];

		return [
			'tone' => (string) Arr::get($flash, 'tone', ''),
			'text' => (string) Arr::get($flash, 'text', ''),
		];
	}

	/**
	 * Ответ на действие: JSON для fetch и 303 с плашкой для обычной отправки.
	 *
	 * Два формата — потому что обе отправки живые: страница диалога работает
	 * fetch-ом (статус-ряд оживает без перезагрузки), а форма без JS должна
	 * остаться рабочей. Ошибки уходят человеческим текстом, а не кодом.
	 */
	protected function _action_result(array $res, $redirect = '/dialog')
	{
		$error = (string) Arr::get($res, 'error', '');
		$to    = (string) Arr::get($res, 'redirect', $redirect);

		// Действия добавляют свою подпись успеха («Настройки сохранены»), не
		// зная, чем кончилось дело. С ошибкой эта подпись обязана молчать:
		// иначе человек читает «материал добавлен» рядом с «страница не
		// открылась» и не понимает, что ничего не добавилось.
		if ($error !== '')
		{
			unset($res['message']);
		}

		$message = (string) Arr::get($res, 'message', '');

		if ($this->request->is_ajax())
		{
			$this->auto_render = FALSE;
			$this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
			$this->response->body(json_encode([
				'ok'    => $error === '',
				'error' => $error,
				'text'  => $message !== '' ? $message : $error,
				'status' => Arr::get($res, 'status', NULL),
			] + $res, JSON_UNESCAPED_UNICODE));

			return $this->response;
		}

		$this->_flash_set($error !== '' ? 'error' : 'notice', $error !== '' ? $error
			: ($message !== '' ? $message : 'Готово'));

		HTTP::redirect($to, 303);

		return NULL;
	}

	/** Путь возврата внутри модуля: чужой адрес в редирект не пускаем. */
	protected function _back($default = '/dialog')
	{
		$back = (string) Arr::get($_POST, 'back', $default);

		return preg_match('~^/dialog(/[a-z0-9/_-]{0,120})?$~i', $back) ? $back : $default;
	}
}
