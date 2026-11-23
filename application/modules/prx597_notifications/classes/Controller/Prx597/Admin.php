<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Управление центром уведомлений (prx597): /prx597/admin. Только роль admin.
 *
 * Вкладки:
 *   summary   — сводка: KPI по каналам, очередь, привязки, боты;
 *   queue     — очередь доставки, отправленное по каналам и лента за сутки:
 *               видно, кто именно ждёт отправки, почему и что уже ушло;
 *   message   — адресное внутреннее сообщение от системы;
 *   events    — реестр событий: включить/выключить, обязательность, каналы,
 *               роли и право;
 *   templates — шаблоны «событие × канал» с предпросмотром;
 *   channels  — каналы: статус, токен из окружения, пробное сообщение,
 *               вебхук и режим работы бота;
 *   limits    — лимиты и антиспам, сроки хранения;
 *   journal   — журнал доставки и DLQ.
 *
 * Опасные действия (чистка, сброс счётчиков, повтор DLQ) — двухшаговые:
 * кнопка сначала «взводится» (data-prx-arm, app.js), повторный клик выполняет.
 */
class Controller_Prx597_Admin extends Controller_Prx102_Base
{
	/** Вкладки управления. */
	const TABS = ['summary', 'queue', 'message', 'events', 'templates', 'channels', 'limits', 'journal'];

	public function before()
	{
		parent::before();

		if ( ! Auth::instance()->logged_in() OR ! $this->can('prx597.admin'))
		{
			throw HTTP_Exception::factory(403, 'Доступ только для администратора');
		}

		// Владелец (16.10.2026): «„Уведомления · управление" переименуй в „Управление"».
		$this->set_title('Управление');

		Service_Prx597_Notify::ensure_tables();

		try
		{
			Service_Prx597_Registry::sync_manifests();
		}
		catch (Throwable $e)
		{
			Kohana::$log->add(Log::WARNING, 'prx597: реестр событий: :e', [':e' => $e->getMessage()]);
		}
	}

	public function action_index()
	{
		if ($this->request->method() === Request::POST)
		{
			$this->_action_post();

			return;
		}

		$tab = (string) $this->request->query('tab');

		if ( ! in_array($tab, self::TABS, TRUE))
		{
			$tab = 'summary';
		}

		$this->template->content = View::factory('prx597/admin', [
			'tab'      => $tab,
			'stats'    => Service_Prx597_Engine::stats(),
			'events'   => Service_Prx597_Registry::events(),
			'events_on'=> Service_Prx597_Registry::events(TRUE),
			'templates'=> Service_Prx597_Registry::templates(),
			'channels' => $this->_channels(),
			'limits'   => Service_Prx597_Limits::table(),
			'limits_extra' => $this->_limits_extra(),
			'journal'  => Service_Prx597_Engine::journal(60),
			'dead'     => Service_Prx597_Engine::dead(20),
			// Очередь и лента: «кто ждёт и почему» и «что уже отправлено».
			// Ссылки стоят на карточках Сводки, поэтому данные готовим всегда.
			'queue'    => Service_Prx597_Engine::queue(50),
			'sent'     => Service_Prx597_Engine::recent_sent(30),
			'feed'     => Service_Prx597_Engine::recent_inbox(30),
			// Чем наполнена лента: по этим кодам администратор узнаёт мусорное
			// событие и убирает его одним нажатием (вкладка «Очередь»).
			'feed_events' => Service_Prx597_Engine::feed_events(7, 12),
			'feed_cap'    => Service_Prx597_Limits::limit('source_hour', 'email', 120),
			'source_alert'=> Service_Prx597_Limits::source_alert(24),
			// ИИ-помощник по шаблонам: доступен ли, чем сочиняет и какие тона
			// предлагаем. Если ИИ не настроен — панель честно объясняет почему.
			'ai_ready' => Service_Prx597_Ai::ready($ai_why),
			'ai_why'   => $ai_why,
			'ai_model' => Service_Prx597_Ai::model_label(),
		'ai_tones' => Service_Prx597_Ai::tones(),
			'cleanup'  => $this->_cleanup_days(),
			// Шкала важности: одна на всю площадку (см. Registry::priority_map).
			// Пороги показываем рядом с ней, чтобы администратор видел, что
			// значит число: ниже quiet_skip не ждут тихих часов, от digest_from
			// и выше может уехать в суточную сводку.
			'priority'        => Service_Prx597_Registry::priority_rows(),
			'priority_skip'   => (int) Service_Prx597_Registry::cfg('priority.quiet_skip', 1),
			'priority_digest' => (int) Service_Prx597_Registry::cfg('priority.digest_from', 5),
			'flash'    => isset($_GET['flash']) ? (string) $_GET['flash'] : '',
			'csrf'     => Security::token(),
		]);
	}

	/** GET /prx597/admin/preview — как выглядит письмо-сводка (без отправки). */
	public function action_preview()
	{
		$rows = Service_Prx597_Notify::admin_recent(5);

		if ( ! $rows)
		{
			$rows = [
				['title' => 'Задача «Профиль компании» выполнена', 'body' => 'Профиль собран: 12 страниц, 5 активностей.', 'href' => '/tasks', 'created_at' => date('Y-m-d H:i:s')],
				['title' => 'Контент-конвейер: статья ждёт модерации', 'body' => '«Металлообработка в 2026» — собрана из 6 источников.', 'href' => '/content', 'created_at' => date('Y-m-d H:i:s')],
			];
		}

		$this->auto_render = FALSE;
		$this->response->headers('Content-Type', 'text/html; charset=UTF-8');
		$this->response->body(Service_Prx597_Notify::digest_html($rows, 2));
	}

	/** GET /prx597/admin/template — предпросмотр шаблона с демо-данными. */
	/**
	 * POST /prx597/admin/ai_template — ИИ-составление шаблона по имеющемуся
	 * сообщению (владелец, 15.10.2026: «добавь составление шаблона с помощью ИИ
	 * на основании имеющегося сообщения»).
	 *
	 * Ничего не сохраняем: отдаём JSON, а решение «подставить и сохранить» —
	 * за человеком в интерфейсе вкладки «Шаблоны».
	 */
	public function action_ai_template()
	{
		$this->auto_render = FALSE;
		$this->response->headers('Content-Type', 'application/json; charset=UTF-8');

		if ($this->request->method() !== Request::POST)
		{
			$this->response->body(json_encode(['ok' => FALSE, 'error' => 'Нужен POST'], JSON_UNESCAPED_UNICODE));

			return;
		}

		if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
		{
			$this->response->body(json_encode(['ok' => FALSE, 'error' => 'Сессия устарела — обновите страницу'], JSON_UNESCAPED_UNICODE));

			return;
		}

		$result = Service_Prx597_Ai::compose(
			(string) Arr::get($_POST, 'event', ''),
			(string) Arr::get($_POST, 'channel', 'in_app'),
			(string) Arr::get($_POST, 'source', ''),
			(string) Arr::get($_POST, 'tone', 'friendly'),
			(string) Arr::get($_POST, 'about', ''),
			(int) Auth::instance()->get_user()->id
		);

		$this->response->body(json_encode($result, JSON_UNESCAPED_UNICODE));
	}

	public function action_template()
	{
		$alias   = (string) $this->request->query('event');
		$channel = (string) $this->request->query('channel');

		$this->auto_render = FALSE;
		$this->response->headers('Content-Type', 'application/json; charset=UTF-8');

		$event = Service_Prx597_Registry::event($alias);

		if ($event === NULL)
		{
			$this->response->body(json_encode(['error' => 'Событие не найдено'], JSON_UNESCAPED_UNICODE));

			return;
		}

		$template = Service_Prx597_Registry::template($event['alias'], $channel);
		$data     = Service_Prx597_Render::sample_data($event);

		$this->response->body(json_encode([
			'ok'       => TRUE,
			'template' => $template,
			'sample'   => $data,
			'preview'  => $template === NULL ? NULL : [
				'subject' => Service_Prx597_Render::render($template['subject'], $data, $template['format'] === 'html' ? 'html' : 'text'),
				'body'    => Service_Prx597_Render::render($template['body'], $data, $template['format'] === 'html' ? 'html' : 'text'),
			],
		], JSON_UNESCAPED_UNICODE));
	}

	// ---------------------------------------------------------------- POST

	protected function _action_post()
	{
		$act  = (string) $this->request->post('act');
		$tab  = (string) $this->request->post('tab');
		$back = '/prx597/admin'.(in_array($tab, self::TABS, TRUE) ? '?tab='.$tab : '');

		if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
		{
			$this->redirect($back.(strpos($back, '?') === FALSE ? '?' : '&').'flash='.urlencode('Сессия устарела — повторите'));

			return;
		}

		switch ($act)
		{
			case 'run':
				$res = Service_Prx597_Engine::run(50);
				$msg = 'Проход доставки: взято '.(int) $res['taken']
					.', отправлено '.(int) $res['sent']
					.', отложено '.(int) $res['deferred']
					.', пропущено '.(int) $res['skipped']
					.', неудач '.(int) $res['failed']
					.', в DLQ '.(int) $res['dead'];
				break;

			case 'digest':
				$res = Service_Prx597_Engine::flush_digests(50);
				$msg = 'Сводки: групп '.(int) $res['groups'].', отправлено '.(int) $res['sent'].', отложено '.(int) $res['deferred'].', пропущено '.(int) $res['skipped'].', неудач '.(int) $res['failed'];
				break;

			case 'retry_dead':
				Service_Prx597_Engine::retry_dead(50);
				$msg = 'Сообщения из DLQ возвращены в очередь';
				break;

			case 'event_save':
				$msg = $this->_event_save();
				break;

			case 'event_toggle':
				$alias   = (string) $this->request->post('alias');
				$enabled = (bool) $this->request->post('enabled');
				Service_Prx597_Registry::toggle_event($alias, $enabled);
				$msg = 'Событие '.($enabled ? 'включено' : 'выключено').': '.$alias;
				break;

			case 'template_save':
				$msg = $this->_template_save();
				break;

			case 'channel_test':
				$msg = $this->_channel_test();
				break;

			case 'bot_mode':
				$channel = (string) $this->request->post('channel');
				$mode    = (string) $this->request->post('mode');
				Service_Prx597_Bind::set_mode($channel, $mode);
				$msg = 'Режим бота '.$channel.' — '.$mode;
				break;

			case 'webhook':
				$msg = $this->_set_webhook();
				break;

			case 'limits_save':
				$msg = $this->_limits_save();
				break;

			case 'settings_save':
				$msg = $this->_settings_save();
				break;

			case 'priority_move':
				$msg = $this->_priority_move();
				break;

			case 'reset_limits':
				Service_Prx597_Limits::reset();
				$msg = 'Счётчики лимитов сброшены';
				break;

			case 'cleanup':
				$res = Service_Prx597_Engine::cleanup();
				$msg = 'Очистка: лента '.(int) $res['inbox'].', очередь '.(int) $res['outbox']
					.', журнал '.(int) $res['log'].', счётчики '.(int) $res['rate'];
				break;

			// Владелец (16.10.2026): «дай возможность администратору очистить
			// неотправленные сообщения — там много мусорных». Убираем ровно
			// то, что ещё не ушло: отправленное остаётся историей.
			case 'purge_queue':
				$res = Service_Prx597_Engine::purge_unsent((string) $this->request->post('scope'));
				$msg = $res['total'] > 0
					? 'Убрано из очереди: '.$res['total'].' (ждало '.$res['waiting'].', не доставлено '.$res['failed'].')'
						.($res['unmarked'] > 0 ? ', меток «ждёт письма» снято: '.$res['unmarked'] : '')
					: 'Неотправленного в очереди не было';
				break;

			// И такая же уборка ленты: мусорные события видно по коду,
			// их и убираем — у всех получателей сразу.
			case 'purge_feed':
				$res = Service_Prx597_Engine::purge_feed_event((string) $this->request->post('alias'));
				$msg = ! empty($res['ok'])
					? 'Из ленты убрано записей: '.$res['deleted'].' (у '.$res['users'].' получателей)'
					: 'Не убрано: '.(string) $res['error'];
				break;

			case 'test_email':
				$to  = trim((string) $this->request->post('to'));
				$res = Service_Prx597_Notify::test_email($to !== '' ? $to : NULL);
				$msg = ! empty($res['ok'])
					? 'Тестовое письмо отправлено на '.$res['to'].' — проверьте ящик (и спам).'
					: 'Не удалось: '.(string) ($res['error'] ?? '?');
				break;

			case 'resend_failed':
				$res = Service_Prx597_Notify::resend_failed(20);
				$msg = 'Возвращено в очередь: '.(int) ($res['queued'] ?? 0);
				break;

			case 'notify_user':
				$user = Auth::instance()->get_user();
				$res = Service_Prx597_Notify::system_message(
					(string) $this->request->post('recipient'),
					(string) $this->request->post('title'),
					(string) $this->request->post('body'),
					$user ? (int) $user->id : 0
				);
				$msg = ! empty($res['ok'])
					? 'Сообщение от системы добавлено в ленту пользователя #'.(int) $res['user_id'].'.'
					: 'Не отправлено: '.(string) ($res['error'] ?? 'ошибка записи');
				$back = '/prx597/admin?tab=message';
				break;

			case 'notify_all':
				$msg = $this->_notify_all();
				break;

			default:
				$msg = 'Неизвестное действие';
		}

		$this->redirect($back.(strpos($back, '?') === FALSE ? '?' : '&').'flash='.urlencode($msg));
	}

	/** Сохранить тип события из формы. */
	protected function _event_save()
	{
		$alias = (string) $this->request->post('alias');

		if ($alias === '')
		{
			return 'Не указан код события';
		}

		$roles = (array) $this->request->post('roles');

		Service_Prx597_Registry::save_event($alias, [
			'title'        => (string) $this->request->post('title'),
			'description'  => (string) $this->request->post('description'),
			'category'     => (string) $this->request->post('category'),
			'icon'         => (string) $this->request->post('icon'),
			'channels'     => implode(',', (array) $this->request->post('channels')),
			'roles'        => $roles ? implode(',', $roles) : 'login',
			'ability'      => (string) $this->request->post('ability'),
			'mandatory'    => (bool) $this->request->post('mandatory'),
			'enabled'      => (bool) $this->request->post('enabled'),
			'digest'       => (bool) $this->request->post('digest'),
			'placeholders' => (string) $this->request->post('placeholders'),
		]);

		return 'Событие сохранено: '.$alias;
	}

	/** Сохранить шаблон. */
	protected function _template_save()
	{
		$alias   = (string) $this->request->post('event');
		$channel = (string) $this->request->post('channel');

		if ($alias === '' OR $channel === '')
		{
			return 'Не указано событие или канал';
		}

		$user = Auth::instance()->get_user();

		Service_Prx597_Registry::save_template($alias, $channel, [
			'subject'   => (string) $this->request->post('subject'),
			'body'      => (string) $this->request->post('body'),
			'format'    => (string) $this->request->post('format'),
			'is_active' => (bool) $this->request->post('is_active'),
		], $user ? (int) $user->id : NULL);

		return 'Шаблон сохранён: '.$alias.' → '.Service_Prx597_Registry::channel_label($channel);
	}

	/** Пробное сообщение по каналу. */
	protected function _channel_test()
	{
		$channel = (string) $this->request->post('channel');
		$to      = trim((string) $this->request->post('to'));
		$driver  = Service_Prx597_Base::channel_driver($channel);

		if ( ! class_exists($driver))
		{
			return 'Неизвестный канал';
		}

		if ($channel === 'in_app')
		{
			$user = Auth::instance()->get_user();
			$res  = call_user_func([$driver, 'test'], $user ? (string) $user->id : '', 'Проверка внутренних уведомлений из управления.');

			return ! empty($res['ok']) ? 'Строка добавлена в вашу ленту — откройте колокольчик.' : 'Не вышло: '.(string) ($res['error'] ?? '?');
		}

		if ($to === '')
		{
			return 'Укажите получателя (адрес, chat_id или id пользователя)';
		}

		$res = call_user_func([$driver, 'test'], $to);

		return ! empty($res['ok'])
			? 'Отправлено: '.Service_Prx597_Registry::channel_label($channel).' → '.$to
			: 'Не вышло: '.(string) ($res['error'] ?? '?');
	}

	/** Зарегистрировать вебхук в Telegram. */
	protected function _set_webhook()
	{
		$channel = (string) $this->request->post('channel');
		$url     = Service_Prx597_Bind::webhook_url($channel);

		if ($url === '')
		{
			return 'Не удалось построить адрес вебхука';
		}

		if ($channel !== 'telegram')
		{
			return 'Для MAX вебхук регистрируется в кабинете бота: '.$url;
		}

		$token = Service_Prx597_Bind::env('TELEGRAM_BOT_TOKEN');

		if ($token === '')
		{
			return 'PRX597_NOTIFICATIONS_TELEGRAM_BOT_TOKEN не задан — вебхук регистрировать нечем';
		}

		$api    = rtrim((string) Service_Prx597_Bind::cfg('telegram.api_url', 'https://api.telegram.org/bot'), '/');
		$params = [
			'url'          => $url,
			'secret_token' => Service_Prx597_Bind::secret($channel),
		];

		$ch = curl_init($api.$token.'/setWebhook?'.http_build_query($params));

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_SSL_VERIFYPEER => TRUE,
		]);

		$body = (string) curl_exec($ch);
		curl_close($ch);

		$data = json_decode($body, TRUE);

		if (is_array($data) AND ! empty($data['ok']))
		{
			Service_Prx597_Bind::set_mode($channel, 'webhook');

			return 'Вебхук зарегистрирован: '.$url;
		}

		return 'Telegram не принял вебхук: '.mb_substr($body, 0, 200);
	}

	/** Сохранить таблицу лимитов. */
	protected function _limits_save()
	{
		$table = (array) $this->request->post('limit');
		$n     = 0;

		foreach ($table as $scope => $channels)
		{
			foreach ((array) $channels as $channel => $value)
			{
				if ( ! in_array($scope, ['per_user_hour', 'per_user_day', 'per_hour', 'per_event_day'], TRUE))
				{
					continue;
				}

				if ( ! array_key_exists($channel, Service_Prx597_Registry::channels(FALSE)))
				{
					continue;
				}

				Service_Prx597_Registry::set_setting('limits.'.$scope.'.'.$channel, (string) max(0, (int) $value));
				$n++;
			}
		}

		return 'Лимиты сохранены: '.$n;
	}

	/**
	 * Поднять или опустить категорию на шкале важности.
	 *
	 * Кнопки, а не drag&drop: строк шесть, порядок меняется раз в год, а
	 * перетаскивание здесь дороже в поддержке (тач, клавиатура, «уронил не
	 * туда») и не даёт ничего сверх «выше/ниже». Каждая кнопка несёт пару
	 * «категория:направление» — так обходимся одной формой на всю шкалу.
	 */
	protected function _priority_move()
	{
		$move = (string) $this->request->post('move');

		if (strpos($move, ':') === FALSE)
		{
			return 'Не понял, что двигать';
		}

		list($category, $dir) = explode(':', $move, 2);
		$category = strtolower(trim($category));
		$new      = Service_Prx597_Registry::priority_move($category, (int) $dir);

		if ($new <= 0)
		{
			return 'Двигать некуда: категория уже на краю шкалы';
		}

		return 'Важность сохранена: '.Service_Prx597_Registry::category_label($category).' — '.$new;
	}

	/** Прочие настройки: окно дедупликации, попытки, пачка, сроки. */
	protected function _settings_save()
	{
		$map = [
			'limits.dedup_window' => (int) $this->request->post('dedup_window'),
			'limits.max_attempts' => (int) $this->request->post('max_attempts'),
			'limits.batch_limit'  => (int) $this->request->post('batch_limit'),
			'cleanup.inbox_days'  => (int) $this->request->post('inbox_days'),
			'cleanup.outbox_days' => (int) $this->request->post('outbox_days'),
			'cleanup.log_days'    => (int) $this->request->post('log_days'),
		];

		foreach ($map as $key => $value)
		{
			if ($value < 0)
			{
				continue;
			}

			Service_Prx597_Registry::set_setting($key, (string) $value);
		}

		$driver = (string) $this->request->post('max_driver');

		if ($driver === 'business' OR $driver === 'bot')
		{
			Service_Prx597_Registry::set_setting('max.driver', $driver);
		}

		Service_Prx597_Registry::forget_settings();

		return 'Настройки сохранены';
	}

	/** Системное сообщение всем, у кого включён колокольчик. */
	protected function _notify_all()
	{
		$title = trim((string) $this->request->post('title'));
		$body  = trim((string) $this->request->post('body'));
		$href  = trim((string) $this->request->post('href'));

		if ($title === '')
		{
			return 'Не указан заголовок';
		}

		$ids = Service_Prx597_Notify::recipient_ids((int) $this->request->post('limit'));

		$inbox = 0;

		foreach ($ids as $user_id)
		{
			$id = Service_Prx597_Notify::inbox_add((int) $user_id, [
				'event_alias' => 'system.message',
				'title'       => $title,
				'body'        => $body,
				'href'        => $href !== '' ? $href : '/prx597',
				'category'    => 'system',
				'icon'        => 'fas fa-bullhorn',
				'priority'    => 5,
			]);

			if ($id > 0)
			{
				$inbox++;
			}
		}

		return 'Отправлено в ленты: '.$inbox.' из '.count($ids);
	}

	// ---------------------------------------------------------------- даныне

	/** Каналы: статус, настройки, бот. */
	protected function _channels()
	{
		$out = [];

		foreach (Service_Prx597_Registry::channels(FALSE) as $key => $info)
		{
			$driver = Service_Prx597_Base::channel_driver($key);
			$row    = [
				'key'     => (string) $key,
				'label'   => (string) Arr::get($info, 'label', $key),
				'icon'    => (string) Arr::get($info, 'icon', 'fas fa-bell'),
				'quiet'   => (bool) Arr::get($info, 'quiet', FALSE),
				'bind'    => (bool) Arr::get($info, 'bind', FALSE),
				'exists'  => class_exists($driver),
				'enabled' => FALSE,
				'whoami'  => NULL,
				'bot'     => NULL,
			];

			if ($row['exists'])
			{
				$row['enabled'] = (bool) call_user_func([$driver, 'enabled']);

				if (method_exists($driver, 'whoami'))
				{
					try
					{
						$row['whoami'] = call_user_func([$driver, 'whoami']);
					}
					catch (Throwable $e)
					{
						$row['whoami'] = ['error' => $e->getMessage()];
					}
				}
			}

			if (in_array((string) $key, ['telegram', 'max'], TRUE))
			{
				$row['bot'] = [
					'mode'     => Service_Prx597_Bind::mode($key),
					'url'      => Service_Prx597_Bind::webhook_url($key),
					'bot_link' => Service_Prx597_Bind::bot_url($key, '<код>'),
				];
			}

			$out[(string) $key] = $row;
		}

		return $out;
	}

	/** Значения настроек антиспама для формы. */
	protected function _limits_extra()
	{
		return [
			'dedup_window' => (int) Service_Prx597_Registry::setting('limits.dedup_window', Service_Prx597_Registry::cfg('limits.dedup_window', 300)),
			'max_attempts' => (int) Service_Prx597_Registry::setting('limits.max_attempts', Service_Prx597_Registry::cfg('limits.max_attempts', 5)),
			'batch_limit'  => (int) Service_Prx597_Registry::setting('limits.batch_limit', Service_Prx597_Registry::cfg('limits.batch_limit', 50)),
			'max_driver'   => (string) Service_Prx597_Registry::setting('max.driver', Service_Prx597_Registry::cfg('max_driver', 'bot')),
		];
	}

	/** Сроки хранения для формы чистки. */
	protected function _cleanup_days()
	{
		return [
			'inbox'  => (int) Service_Prx597_Registry::setting('cleanup.inbox_days', Service_Prx597_Registry::cfg('cleanup.inbox_days', 180)),
			'outbox' => (int) Service_Prx597_Registry::setting('cleanup.outbox_days', Service_Prx597_Registry::cfg('cleanup.outbox_days', 60)),
			'log'    => (int) Service_Prx597_Registry::setting('cleanup.log_days', Service_Prx597_Registry::cfg('cleanup.log_days', 60)),
		];
	}
}
