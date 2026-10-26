<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-662: страница диалога (/dialog/<id>) и действия над ним.
 *
 * Страница отвечает на три вопроса: «что сейчас происходит» (статус-ряд:
 * спиннер/чек + этап + заметка + секундомер + счётчики — по эталону
 * /pages/required_design_sample), «что сказано» (лента реплик со ссылками на
 * найденные источники) и «что можно поправить» (настройки, материалы,
 * публикация). Ход ведёт сервер — страница только СМОТРИТ (поллинг
 * /dialog/status/<id>) и управляет.
 *
 * Чужой диалог не показывается даже по прямому адресу: гость получает 404,
 * чужой аккаунт — 404 тоже (не подтверждаем существование). Единственное
 * исключение — опубликованный диалог: его читают все, и это отдельная вьюха
 * без настроек и материалов.
 */
class Controller_Nj662_Dialog extends Controller_Nj662_Base {

	/** GET /dialog/<id> — страница диалога. */
	public function action_view()
	{
		$id     = (int) $this->request->param('id');
		$dialog = $id > 0 ? Service_Nj662_Dialog::dialog($id) : NULL;

		if ( ! $dialog)
		{
			throw HTTP_Exception::factory(404, 'Диалог не найден');
		}

		$own = Service_Nj662_Dialog::owns($dialog, $this->_user_id, $this->_is_admin);

		if ( ! $own)
		{
			// Гостю и чужому аккаунту показываем только ОПУБЛИКОВАННОЕ.
			if ( ! Service_Nj662_Dialog::as_bool($dialog['is_public']))
			{
				throw HTTP_Exception::factory(404, 'Диалог не найден');
			}

			$this->set_title(Service_Nj662_Dialog::excerpt($dialog['topic'], 120).' — диалог двух ИИ');
			$this->set_description(Service_Nj662_Dialog::excerpt((string) $dialog['brief'] !== ''
				? $dialog['brief']
				: 'Разговор двух ИИ-агентов о том, '.mb_strtolower($dialog['topic']), 300));

			$this->template->content = View::factory('662/public', [
				'dialog'   => $dialog,
				'progress' => Service_Nj662_Dialog::progress($dialog),
				'messages' => Service_Nj662_Dialog::messages($id),
				'searches' => Service_Nj662_Dialog::searches($id, 20),
				'own'      => FALSE,
				'csrf'     => Security::token(),
				'flash'    => $this->_flash(),
			]);

			return;
		}

		// Свой диалог: сначала проверяем, не «умер» ли он по вине воркера —
		// иначе страница показывала бы «идёт», когда очередь давно закрыта.
		Service_Nj662_Dialog::close_if_dead($dialog);
		$dialog = Service_Nj662_Dialog::dialog($id);

		if ((string) $dialog['status'] === 'running')
		{
			Service_Nj662_Dialog::knowledge_refresh($id);
		}

		$this->set_title(Service_Nj662_Dialog::excerpt($dialog['topic'], 100).' — диалог двух ИИ');

		$this->template->content = View::factory('662/dialog', [
			'dialog'    => $dialog,
			'progress'  => Service_Nj662_Dialog::progress($dialog),
			'messages'  => Service_Nj662_Dialog::messages($id),
			'searches'  => Service_Nj662_Dialog::searches($id, 20),
			'knowledge' => Service_Nj662_Dialog::knowledge($id),
			'events'    => Service_Nj662_Dialog::events($id, 12),
			'own'       => TRUE,
			'can_start' => $this->_can_start,
			'replies'   => (int) Service_Nj662_Dialog::db()->query(Database::SELECT,
				'SELECT count(*) AS n FROM nj_662_messages WHERE dialog_id = '.(int) $id, FALSE)->get('n'),
			'models'    => Service_Nj662_Models::offers(),
			'limits'    => Service_Nj662_Dialog::limits(),
			'samples'   => Service_Nj662_Dialog::samples(),
			'csrf'      => Security::token(),
			'flash'     => $this->_flash(),
		]);
	}

	/**
	 * GET /dialog/read/<id> — страница ЧТЕНИЯ диалога (решение владельца
	 * 09.10.2026): тот же разговор, но оформленный для чтения, а не для
	 * управления — шрифт крупнее, колонка уже, настроек и кнопок нет.
	 *
	 * Кого пускаем: опубликованный диалог читают все; неопубликованный —
	 * владелец и админ (это его рабочий черновик, но почитать его удобно
	 * там же, где опубликованный).
	 */
	public function action_read()
	{
		$id     = (int) $this->request->param('id');
		$dialog = $id > 0 ? Service_Nj662_Dialog::dialog($id) : NULL;

		if ( ! $dialog)
		{
			throw HTTP_Exception::factory(404, 'Диалог не найден');
		}

		$own = Service_Nj662_Dialog::owns($dialog, $this->_user_id, $this->_is_admin);

		if ( ! $own AND ! Service_Nj662_Dialog::as_bool($dialog['is_public']))
		{
			throw HTTP_Exception::factory(404, 'Диалог не найден');
		}

		$this->set_title(Service_Nj662_Dialog::excerpt($dialog['topic'], 120).' — диалог двух ИИ');
		$this->set_description(Service_Nj662_Dialog::excerpt((string) $dialog['brief'] !== ''
			? $dialog['brief']
			: 'Разговор двух ИИ-агентов о том, '.mb_strtolower($dialog['topic']), 300));

		$this->template->content = View::factory('662/read', [
			'dialog'   => $dialog,
			'progress' => Service_Nj662_Dialog::progress($dialog),
			'messages' => Service_Nj662_Dialog::messages($id),
			'searches' => Service_Nj662_Dialog::searches($id, 50),
			'events'   => $own ? Service_Nj662_Dialog::events($id, 8) : [],
			'own'      => $own,
			'can_start' => $this->_can_start,
			'csrf'     => Security::token(),
			'flash'    => $this->_flash(),
		]);
	}

	/**
	 * GET /dialog/status/<id> — JSON хода диалога (поллинг страницы).
	 *
	 * Ответ — тот же словарь, что рисует сервер (Service_Nj662_Dialog::progress):
	 * статус-ряд не «прыгает» при первом опросе. Плюс revision — отпечаток
	 * содержимого: вырос — движок перезагружает страницу и показывает новые
	 * реплики (собирать ленту в браузере по кусочкам не нужно).
	 */
	public function action_status()
	{
		$this->auto_render = FALSE;
		$this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

		$id = (int) $this->request->param('id');

		$data = Service_Nj662_Dialog::status_json($id, $this->_user_id, $this->_is_admin);

		$this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));

		return $this->response;
	}

	/** POST /dialog/<id>/settings — сохранить настройки (модели, обмены, поиск). */
	public function action_settings()
	{
		$this->_post_only();

		$id  = (int) $this->request->param('id');
		$res = Service_Nj662_Dialog::save_settings($id, $this->_user_id, $_POST, $this->_is_admin);

		return $this->_action_result($res + ['message' => 'Настройки сохранены'],
			'/dialog/'.$id);
	}

	/**
	 * POST /dialog/<id>/start — начать или продолжить разговор.
	 *
	 * Счётчик обменов считается ОТ НАЧАЛА диалога (решение владельца
	 * 09.10.2026): продолжение после паузы, стопа или своей реплики не
	 * обнуляет «обмен 3 из 5» — следующим будет «обмен 4 из 5». Нужно больше
	 * обменов — число правится в настройках (или добавляется вместе со своей
	 * репликой, /dialog/<id>/say).
	 */
	public function action_start()
	{
		$this->_post_only();

		// Запуск — привилегированным: аккаунт без роли vip не тратит очередь,
		// даже если страница диалога у него осталась открытой.
		if ( ! $this->_can_start)
		{
			return $this->_action_result([
				'error' => 'Запускать диалог могут привилегированные аккаунты (роль vip) и администратор',
			], '/dialog/'.$this->request->param('id'));
		}

		$id  = (int) $this->request->param('id');
		$res = Service_Nj662_Dialog::start($id, $this->_user_id, $this->_is_admin);

		// Подпись хода берём у сервиса: «обмен 2 из 5» — иначе страница обещает
		// один обмен и человек не понимает, сколько ещё ждать.
		return $this->_action_result($res + ['message' => 'Разговор идёт: обмен '
				.(int) Arr::get($res, 'turn', 1).' из '.(int) Arr::get($res, 'pairs', 1)],
			'/dialog/'.$id);
	}

	/** POST /dialog/<id>/stop — стоп: снять обмен с очереди сразу. */
	public function action_stop()
	{
		$this->_post_only();

		$id  = (int) $this->request->param('id');
		$res = Service_Nj662_Dialog::stop($id, $this->_user_id, $this->_is_admin);

		return $this->_action_result($res + ['message' => Arr::get($res, 'job_cancelled', FALSE)
					? 'Стоп: задание снято с очереди, разговор ждёт «Продолжить»'
					: 'Стоп: разговор остановлен, продолжение — кнопкой «Продолжить»'],
			'/dialog/'.$id);
	}

	/**
	 * POST /dialog/<id>/say — своя реплика в разговор (и, если просили,
	 * продолжение с того же места).
	 *
	 * Реплика — способ поправить направление: агент видит её в истории
	 * следующим обменом. План обменов при желании удлиняется той же формой:
	 * иначе после последнего обмена продолжать было бы нечего.
	 */
	public function action_say()
	{
		$this->_post_only();

		$id = (int) $this->request->param('id');

		// Реплику пишет владелец (или админ), но продолжение ставит обмен в
		// очередь — а это тот же запуск: право nj662.start спрашиваем заранее,
		// чтобы человек не писал текст в форму, которая не сработает.
		if (Arr::get($_POST, 'resume', '1') !== '0' AND ! $this->_can_start)
		{
			return $this->_action_result([
				'error' => 'Продолжать диалог могут привилегированные аккаунты (роль vip) и администратор',
			], '/dialog/'.$id);
		}

		$author = $this->_current_user ? trim((string) $this->_current_user->username) : '';

		$res = Service_Nj662_Dialog::say($id, $this->_user_id, (string) Arr::get($_POST, 'text', ''),
			$this->_is_admin, $author, (int) Arr::get($_POST, 'add', 0),
			(string) Arr::get($_POST, 'resume', '1') !== '0');

		if (isset($res['error']))
		{
			return $this->_action_result($res, '/dialog/'.$id);
		}

		// Подпись та же мысль, что в форме: счётчик идёт от начала, а план
		// разговора стал длиннее на столько-то обменов.
		$message = 'Реплика добавлена'.((int) Arr::get($res, 'add', 0) > 0
				? ', обменов в плане теперь '.(int) Arr::get($res, 'pairs_new', 0)
				: '');

		if ((string) Arr::get($res, 'note', '') !== '')
		{
			$message .= '. '.$res['note'];
		}
		elseif (Arr::get($res, 'started', FALSE))
		{
			$message .= ': разговор продолжен, обмен '.(int) Arr::get($res, 'next_turn', 0);
		}

		return $this->_action_result($res + ['message' => $message], '/dialog/'.$id);
	}

	/** POST /dialog/<id>/pause — пауза (ход не обрываем: воркер отпустит задачу). */
	public function action_pause()
	{
		$this->_post_only();

		$id  = (int) $this->request->param('id');
		$res = Service_Nj662_Dialog::pause($id, $this->_user_id, $this->_is_admin);

		return $this->_action_result($res + ['message' => 'Пауза: разговор остановлен, продолжение — той же кнопкой'],
			'/dialog/'.$id);
	}

	/**
	 * POST /dialog/<id>/quick — подобрать участников заново (быстрый старт).
	 *
	 * Это запуск (после подбора диалог стартует сам), поэтому право
	 * nj662.start проверяется здесь так же, как у «Продолжить».
	 */
	public function action_quick()
	{
		$this->_post_only();

		if ( ! $this->_can_start)
		{
			return $this->_action_result([
				'error' => 'Запускать диалог могут привилегированные аккаунты (роль vip) и администратор',
			], '/dialog/'.$this->request->param('id'));
		}

		$id     = (int) $this->request->param('id');
		$dialog = Service_Nj662_Dialog::dialog($id);

		if ( ! $dialog OR ! Service_Nj662_Dialog::owns($dialog, $this->_user_id, $this->_is_admin))
		{
			return $this->_action_result(['error' => 'Диалог не найден'], '/dialog');
		}

		$res = Service_Nj662_Quick::queue($id, Service_Nj662_Dialog::as_bool(Arr::get($_POST, 'autostart', '1')));

		return $this->_action_result($res + ['message' => 'Подбираю участников под тему'], '/dialog/'.$id);
	}

	/** POST /dialog/<id>/publish — опубликовать или снять с публикации. */
	public function action_publish()
	{
		$this->_post_only();

		$id     = (int) $this->request->param('id');
		$dialog = Service_Nj662_Dialog::dialog($id);

		if ( ! $dialog)
		{
			return $this->_action_result(['error' => 'Диалог не найден'], '/dialog');
		}

		$on  = ! Service_Nj662_Dialog::as_bool($dialog['is_public']);
		$res = Service_Nj662_Dialog::set_public($id, $this->_user_id, $on, $this->_is_admin);

		return $this->_action_result($res + ['message' => $on
			? 'Диалог опубликован: страница открыта для всех'
			: 'Диалог снят с публикации'], '/dialog/'.$id);
	}

	/** POST /dialog/<id>/delete — удалить диалог вместе с репликами и материалами. */
	public function action_delete()
	{
		$this->_post_only();

		$id  = (int) $this->request->param('id');
		$res = Service_Nj662_Dialog::delete($id, $this->_user_id, $this->_is_admin);

		return $this->_action_result($res + ['message' => 'Диалог удалён'], '/dialog');
	}

	/** POST /dialog/<id>/knowledge_add — добавить материал (текст или ссылку). */
	public function action_knowledge_add()
	{
		$this->_post_only();

		$id     = (int) $this->request->param('id');
		$dialog = Service_Nj662_Dialog::dialog($id);

		if ( ! $dialog OR ! Service_Nj662_Dialog::owns($dialog, $this->_user_id, $this->_is_admin))
		{
			return $this->_action_result(['error' => 'Диалог не найден'], '/dialog');
		}

		$res = Service_Nj662_Dialog::knowledge_add($id, $this->_user_id, [
			'title'  => Arr::get($_POST, 'title', ''),
			'source' => Arr::get($_POST, 'source', ''),
			'text'   => Arr::get($_POST, 'text', ''),
		]);

		return $this->_action_result($res + ['message' => 'Материал добавлен и отправлен в индекс'],
			'/dialog/'.$id);
	}

	/** POST /dialog/<id>/knowledge_delete — убрать материал и его вектор. */
	public function action_knowledge_delete()
	{
		$this->_post_only();

		$id     = (int) $this->request->param('id');
		$kid    = (int) Arr::get($_POST, 'knowledge_id', 0);
		$dialog = Service_Nj662_Dialog::dialog($id);

		if ( ! $dialog OR ! Service_Nj662_Dialog::owns($dialog, $this->_user_id, $this->_is_admin))
		{
			return $this->_action_result(['error' => 'Диалог не найден'], '/dialog');
		}

		$res = Service_Nj662_Dialog::knowledge_delete($kid, $id);

		return $this->_action_result($res + ['message' => 'Материал убран'], '/dialog/'.$id);
	}

	/** POST /dialog/<id>/knowledge_reindex — пересчитать векторы материалов. */
	public function action_knowledge_reindex()
	{
		$this->_post_only();

		$id     = (int) $this->request->param('id');
		$dialog = Service_Nj662_Dialog::dialog($id);

		if ( ! $dialog OR ! Service_Nj662_Dialog::owns($dialog, $this->_user_id, $this->_is_admin))
		{
			return $this->_action_result(['error' => 'Диалог не найден'], '/dialog');
		}

		$res = Service_Nj662_Dialog::knowledge_reindex($id);

		return $this->_action_result($res + ['message' => 'Материалы отправлены в индекс: '.(int) Arr::get($res, 'queued', 0)],
			'/dialog/'.$id);
	}
}
