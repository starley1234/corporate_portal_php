<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-662: рабочий стол диалогов (/dialog) и создание разговора (/dialog/new).
 *
 * Владелец просил «быстрое безгеморройное начало диалога»: человек пишет тему,
 * нажимает одну кнопку — и разговор уже идёт. Поэтому на странице две кнопки
 * одной формы:
 *
 *   · «Подобрать агентов и начать» (quick=go) — имена и характеры под тему
 *     придумает модель (канал nj662.quick), а сайт сразу поставит первый обмен:
 *     человеку не нужно заполнять ни одного поля;
 *   · «Создать и настроить» (quick=edit) — диалог открывается с формой
 *     настроек: модели, число обменов, контекст, суммаризация, частота поиска
 *     в интернете, материалы.
 *
 * Второе, что видно на странице, — «мои диалоги» карточками: статус, участники,
 * сколько реплик и чем закончилось. Чужие диалоги здесь не показываются.
 *
 * С 09.10.2026 обе кнопки создания доступны только по праву nj662.start (роль
 * vip или админ): запуск разговора тратит модель и очередь. Вошедший без права
 * видит плашку 662/gate (что даёт роль и что доступно без неё), гость — плашку
 * входа Gate::wall.
 */
class Controller_Nj662_Index extends Controller_Nj662_Base {

	/** GET /dialog — рабочий стол. */
	public function action_index()
	{
		$logged = Gate::logged_in();

		// Запуск диалога — привилегированным (роль vip или админ): форму
		// создания показываем только им, остальным — объяснение. Читать
		// опубликованные разговоры и вести свои (заведённые раньше) можно всем
		// зарегистрированным (решение владельца 09.10.2026).
		$can_start = $logged && $this->_can_start;

		$this->template->content = View::factory('662/index', [
			'logged'    => $logged,
			'can_start' => $can_start,
			'gate'      => ($logged AND ! $can_start) ? $this->_gate_start() : NULL,
			'wall'      => $logged ? NULL : $this->_wall('dialog'),
			'dialogs'   => $logged ? Service_Nj662_Dialog::dialogs_of($this->_user_id, 60) : [],
			'counters'  => $logged ? Service_Nj662_Dialog::counters($this->_user_id) : [],
			'samples'  => Service_Nj662_Dialog::samples(),
			'defaults' => Service_Nj662_Dialog::defaults(),
			'limits'   => Service_Nj662_Dialog::limits(),
			'roles'    => Service_Nj662_Dialog::roles(),
			'models'   => Service_Nj662_Models::offers(),
			'models_default' => Service_Nj662_Models::default_spec(),
			'csrf'     => Security::token(),
			'flash'    => $this->_flash(),
		]);
	}

	/**
	 * POST /dialog/new — создать диалог.
	 *
	 * Гость сюда не попадает ссылкой, но форма могла «провиснуть» в открытой
	 * вкладке: аккаунта нет — отправляем на страницу сервиса, там плашка входа.
	 */
	public function action_create()
	{
		$this->_post_only();

		if ( ! Gate::logged_in())
		{
			if ($this->request->is_ajax())
			{
				return $this->_action_result(['error' => 'Нужен аккаунт: войдите и повторите'], '/dialog');
			}

			HTTP::redirect('/dialog', 303);

			return NULL;
		}

		// Второй замок: форму могли открыть до того, как право сняли, —
		// сервер не ставит обмен в очередь без права nj662.start.
		if ( ! $this->_can_start)
		{
			$error = 'Запускать диалог могут привилегированные аккаунты (роль vip) и администратор';

			if ($this->request->is_ajax())
			{
				return $this->_action_result(['error' => $error], '/dialog');
			}

			$this->_flash_set('error', $error);

			HTTP::redirect('/dialog', 303);

			return NULL;
		}

		$quick = (string) Arr::get($_POST, 'quick', '') === 'go';

		$res = Service_Nj662_Dialog::create($this->_user_id, [
			'topic' => Arr::get($_POST, 'topic', ''),
			'brief' => Arr::get($_POST, 'brief', ''),
			'quick' => $quick,
		]);

		if (isset($res['error']))
		{
			return $this->_action_result(['error' => (string) $res['error']], '/dialog');
		}

		$dialog_id = (int) $res['dialog_id'];

		if ($quick)
		{
			$queued = Service_Nj662_Quick::queue($dialog_id, TRUE);

			if (isset($queued['error']))
			{
				return $this->_action_result([
					'error'    => (string) $queued['error'],
					'redirect' => '/dialog/'.$dialog_id,
				], '/dialog/'.$dialog_id);
			}

			return $this->_action_result([
				'message'  => 'Диалог создан: подбираю участников под тему',
				'redirect' => '/dialog/'.$dialog_id,
			], '/dialog/'.$dialog_id);
		}

		return $this->_action_result([
			'message'  => 'Диалог создан — проверьте участников и настройки',
			'redirect' => '/dialog/'.$dialog_id,
		], '/dialog/'.$dialog_id);
	}
}
