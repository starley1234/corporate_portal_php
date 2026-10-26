<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Центр уведомлений пользователя (nj597): /nj597.
 *
 * Две вкладки:
 *   «Лента»     — уведомления: прочитать, в архив, очистить прочитанные;
 *   «Настройки» — каналы (почта, Telegram, MAX), матрица «событие × канал»,
 *                 тихие часы и режим сводки.
 *
 * Матрица строится только из тех событий, которые пользователю положены:
 * реестр сам отсеивает чужие по ролям и правам (Service_Nj597_Registry),
 * поэтому человек не видит административных событий и не может их включить.
 */
class Controller_Nj597_Notifications extends Controller_Erp100_Base
{
	protected $_user_id = NULL;

	public function before()
	{
		parent::before();

		if ( ! Auth::instance()->logged_in())
		{
			HTTP::redirect('/auth/login', 302);
		}

		$this->_user_id = (int) $this->_current_user->id;

		// чипы собирает nj110 из манифеста — module_nav не дублируем

		Service_Nj597_Notify::ensure_tables();

		// Реестр событий подтягиваем из манифестов не чаще раза в час.
		try
		{
			Service_Nj597_Registry::sync_manifests();
		}
		catch (Throwable $e)
		{
			Kohana::$log->add(Log::WARNING, 'nj597: реестр событий: :e', [':e' => $e->getMessage()]);
		}
	}

	/** GET /nj597 — лента и настройки. */
	public function action_index()
	{
		if ($this->request->method() === Request::POST)
		{
			$this->_action_post();

			return;
		}

		$this->set_title('Уведомления — N2J.ru');
		$this->set_description('Центр уведомлений N2J.ru: события ваших сервисов — колокольчиком на сайте, письмом, в Telegram и MAX. Настройте, о чём и куда сообщать.');

		$tab = (string) $this->request->query('tab');

		if ( ! in_array($tab, ['feed', 'settings'], TRUE))
		{
			$tab = 'feed';
		}

		$this->template->content = View::factory('597/index', [
			'tab'     => $tab,
			'channels' => Service_Nj597_Base::user_channels(),
			'items'   => Service_Nj597_Notify::latest($this->_user_id, 60),
			'unread'  => Service_Nj597_Notify::unread_count($this->_user_id),
			'prefs'   => Service_Nj597_Notify::prefs($this->_user_id),
			'matrix'  => $this->_matrix(),
			'binding' => $this->_binding(),
			'flash'   => isset($_GET['flash']) ? (string) $_GET['flash'] : '',
			'csrf'    => Security::token(),
			'email'   => (string) $this->_current_user->email,
		]);
	}

	/** POST /nj597 — действия без JS: prefs, sub, read, read_all, archive, clear_read. */
	protected function _action_post()
	{
		$act  = (string) $this->request->post('act');
		$back = '/nj597';

		if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
		{
			$this->redirect($back.'?flash='.urlencode('Сессия устарела — повторите'));

			return;
		}

		switch ($act)
		{
			case 'prefs':
				$msg = $this->_save_prefs();
				$back = '/nj597?tab=settings';
				break;

			case 'sub':
				$msg = $this->_save_sub();
				$back = '/nj597?tab=settings';
				break;

			case 'unbind':
				Service_Nj597_Bind::unbind($this->_user_id, (string) $this->request->post('channel'));
				$msg = 'Канал отвязан';
				$back = '/nj597?tab=settings';
				break;

			case 'read':
				Service_Nj597_Notify::mark_read($this->_user_id, [(int) $this->request->post('id')]);
				$msg = 'Уведомление отмечено прочитанным';
				break;

			case 'read_all':
				Service_Nj597_Notify::mark_read($this->_user_id);
				$msg = 'Все уведомления отмечены прочитанными';
				break;

			case 'archive':
				Service_Nj597_Notify::archive($this->_user_id);
				$msg = 'Прочитанные убраны в архив';
				break;

			case 'clear_read':
				$n   = Service_Nj597_Notify::clear_read($this->_user_id);
				$msg = $n ? 'Удалено прочитанных: '.$n : 'Прочитанных нет';
				break;

			default:
				$msg = 'Неизвестное действие';
		}

		$this->redirect($back.(strpos($back, '?') === FALSE ? '?' : '&').'flash='.urlencode($msg));
	}

	/** Сохранить настройки доставки (тихие часы, сводка, каналы). */
	protected function _save_prefs()
	{
		$p      = $this->request->post();
		$fields = Service_Nj597_Notify::user_pref_fields($p);

		try
		{
			Service_Nj597_Notify::save_prefs($this->_user_id, $fields);
		}
		catch (Throwable $e)
		{
			Kohana::$log->add(Log::ERROR, 'nj597: prefs save: :e', [':e' => $e->getMessage()]);

			return 'Настройки не сохранены — база данных не подтвердила запись';
		}

		return 'Настройки сохранены';
	}

	/** Клетка матрицы «событие × канал». */
	protected function _save_sub()
	{
		$alias   = (string) $this->request->post('event');
		$channel = (string) $this->request->post('channel');
		$enabled = (bool) $this->request->post('enabled');

		$event = Service_Nj597_Registry::event($alias);

		if ($event === NULL)
		{
			return 'Событие не найдено';
		}

		if ( ! empty($event['mandatory']))
		{
			return 'Это событие обязательное — его нельзя отключить';
		}

		if ( ! isset(Service_Nj597_Base::user_channels()[$channel])
			OR ! in_array($channel, $event['channels'], TRUE)
			OR ! Service_Nj597_Registry::user_allowed($event, Service_Nj597_Registry::roles_of($this->_user_id)))
		{
			return 'Событие или канал недоступны';
		}

		Service_Nj597_Registry::save_sub($this->_user_id, $event['alias'], $channel, $enabled);

		return ($enabled ? 'Включено' : 'Выключено').': '.$event['title'].' → '.Service_Nj597_Registry::channel_label($channel);
	}

	/**
	 * Матрица настроек: какие события этому пользователю положены и что из
	 * этого включено. Чужие события (не по роли/праву) в матрицу не попадают.
	 */
	protected function _matrix()
	{
		$roles = Service_Nj597_Registry::roles_of($this->_user_id);
		$subs  = Service_Nj597_Registry::subs($this->_user_id);
		$prefs = Service_Nj597_Notify::prefs($this->_user_id);
		$out   = [];
		$channels = Service_Nj597_Base::user_channels();

		foreach (Service_Nj597_Registry::events(TRUE) as $alias => $event)
		{
			if ( ! Service_Nj597_Registry::user_allowed($event, $roles))
			{
				continue;
			}

			$row = $event;
			$row['state'] = [];

			foreach ($event['channels'] as $channel)
			{
				if ( ! isset($channels[$channel]))
				{
					continue;
				}

				$row['state'][$channel] = Service_Nj597_Registry::sub_enabled($event, $channel, $prefs, $subs);
			}

			$out[$event['category']][] = $row;
		}

		return $out;
	}

	/** Привязки каналов пользователя: что подключено и что можно подключить. */
	protected function _binding()
	{
		$out = [];

		foreach (Service_Nj597_Registry::user_channels() as $key => $info)
		{
			$driver = Service_Nj597_Base::channel_driver($key);

			if ( ! class_exists($driver))
			{
				continue;
			}

			$row = [
				'key'         => (string) $key,
				'label'       => (string) Arr::get($info, 'label', $key),
				'icon'        => (string) Arr::get($info, 'icon', 'fas fa-bell'),
				'bind'        => (bool) Arr::get($info, 'bind', FALSE),
				'enabled'     => (bool) call_user_func([$driver, 'enabled']),
				'destination' => (string) call_user_func([$driver, 'destination'], $this->_user_id),
				'status'      => '',
			];

			$binding = NULL;

			try
			{
				$binding = call_user_func([$driver, 'binding'], $this->_user_id);
			}
			catch (Throwable $e)
			{
				$binding = NULL;
			}

			$row['status'] = $binding === NULL ? 'none' : (string) $binding['status'];
			$row['title']  = $binding === NULL ? '' : (string) $binding['title'];

			// Ссылку на бота показываем, только когда канал реально работает.
			// Скобки обязательны: AND слабее присваивания — без них в ссылку
			// уходил бы результат «$row['bind']», а не ветка тернарника.
			$row['bot_url'] = ($row['bind'] AND $row['enabled'])
				? Service_Nj597_Bind::bot_url($key, '__CODE__')
				: '';

			$out[(string) $key] = $row;
		}

		return $out;
	}
}
