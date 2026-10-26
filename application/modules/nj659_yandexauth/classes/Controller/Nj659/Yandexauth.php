<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-659 «Вход через Яндекс»: контроллер OAuth-потока.
 *
 * Сценарии:
 *  - гость с /auth/login жмёт кнопку → /auth/yandex → Яндекс →
 *    /auth/yandex/callback → вход (или автосоздание аккаунта);
 *  - залогиненный открывает /auth/yandex → привязка Яндекса к аккаунту
 *    (результат — страница-сообщение 659/message);
 *  - владелец или админ отвязывает аккаунт: POST /auth/yandex/unlink;
 *  - /nj659/admin — диагностика связок (право nj659.yandexauth).
 */
class Controller_Nj659_Yandexauth extends Controller_Erp100_Base {

	public function before()
	{
		parent::before();

		if ($this->request->action() === 'admin' AND ! $this->can('nj659.yandexauth'))
		{
			throw HTTP_Exception::factory(403, 'Доступ только для администратора');
		}

		if ($this->request->action() === 'admin')
		{
			$this->set_title('Вход через Яндекс · связки');
			$this->set_module_nav([
				['title' => 'Связки', 'href' => '/nj659/admin', 'icon' => 'fab fa-yandex'],
			]);
		}
	}

	/**
	 * GET /auth/yandex — старт потока: анти-CSRF state и redirect на Яндекс.
	 * Работает и для гостя (вход), и для залогиненного (привязка аккаунта).
	 */
	public function action_start()
	{
		$service = new Service_Nj659_Yandexauth;

		if ( ! $service->is_configured())
		{
			$this->template->title = 'Вход через Яндекс';
			$this->template->content = View::factory('659/message', [
				'type'  => 'warning',
				'title' => 'Вход через Яндекс не настроен',
				'text'  => 'Администратору: задайте YANDEX_OAUTH_CLIENT_ID, YANDEX_OAUTH_CLIENT_SECRET и YANDEX_OAUTH_REDIRECT_URI в .env, затем откройте приложение на oauth.yandex.ru и укажите там redirect URI https://<домен>/auth/yandex/callback.',
				'href'  => '/auth/login',
				'link'  => 'К странице входа',
			]);
			return;
		}

		$state = Text::random('alnum', 32);
		Session::instance()->set('nj659_state', $state);

		HTTP::redirect($service->authorize_url($state), 302);
	}

	/**
	 * GET /auth/yandex/callback — возврат Яндекса: проверка state, обмен
	 * code на токен, вход гостя или привязка к текущему аккаунту.
	 */
	public function action_callback()
	{
		$service = new Service_Nj659_Yandexauth;

		// Пользователь передумал / ошибка на стороне Яндекса
		$error = (string) $this->request->query('error');

		if ($error !== '')
		{
			$description = (string) Arr::get($_GET, 'error_description', '');
			$this->_login_error('Яндекс: '.($description !== '' ? $description : 'доступ не подтверждён'));
		}

		// Защита от подмены: state должен совпасть с сессионным
		$state = (string) $this->request->query('state');
		$expected = (string) Session::instance()->get_once('nj659_state', '');

		if ($expected === '' OR $state === '' OR ! hash_equals($expected, $state))
		{
			$this->_login_error('Сессия устарела, попробуйте войти ещё раз');
		}

		$code = (string) $this->request->query('code');

		if ($code === '')
		{
			$this->_login_error('Яндекс не вернул код авторизации');
		}

		try
		{
			$access = $service->fetch_access($code);
		}
		catch (Exception $e)
		{
			Kohana::$log->add(Log::ERROR, 'nj659: token exchange: :m', [':m' => $e->getMessage()]);
			$this->_login_error('Не удалось получить доступ у Яндекса, попробуйте позже');
		}

		// Залогиненный пользователь привязывает Яндекс к своему аккаунту
		if (Auth::instance()->logged_in())
		{
			$user = Auth::instance()->get_user();

			try
			{
				$service->create_bind((int) $user->id, $access['access_token'], $access['profile']);
			}
			catch (Exception $e)
			{
				$this->_message('error', 'Привязка не удалась', $e->getMessage());
			}

			$this->_message('success', 'Яндекс привязан', 'Аккаунт Яндекса «'.Arr::get($access['profile'], 'login', '').'» связан с вашим профилем. Теперь можно входить по кнопке на странице входа.');
		}

		// Гость: вход по существующей связке / email или автосоздание
		try
		{
			$settings = ['autocreate' => (bool) Kohana::$config->load('nj659_yandexauth.autocreate')];
			$result = $service->login_with($access, $settings);
		}
		catch (Exception $e)
		{
			Kohana::$log->add(Log::ERROR, 'nj659: login: :m', [':m' => $e->getMessage()]);
			$this->_login_error($e->getMessage());
		}

		$target = (string) Session::instance()->get_once('login_redirect', '/');

		if ($target === '' OR strpos($target, '//') === 0 OR strpos($target, '://') !== FALSE)
		{
			$target = '/'; // только локальные пути
		}

		HTTP::redirect($target, 302);
	}

	/**
	 * POST /auth/yandex/unlink — отвязка. Владелец связки или админ
	 * (право nj659.yandexauth).
	 */
	public function action_unlink()
	{
		if ($this->request->method() !== Request::POST)
		{
			throw HTTP_Exception::factory(405, 'Только POST');
		}

		if ( ! Security::check((string) $this->request->post('csrf')))
		{
			throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
		}

		if ( ! Auth::instance()->logged_in())
		{
			throw HTTP_Exception::factory(403, 'Требуется вход');
		}

		$user = Auth::instance()->get_user();
		$id = (int) $this->request->post('id');

		$bind = ORM::factory('Erp100_UserOauth', $id);

		if ( ! $bind->loaded() OR $bind->provider !== Service_Nj659_Yandexauth::PROVIDER)
		{
			throw HTTP_Exception::factory(404, 'Связка не найдена');
		}

		if ((int) $bind->user_id !== (int) $user->id AND ! $this->can('nj659.yandexauth'))
		{
			throw HTTP_Exception::factory(403, 'Это не ваша связка');
		}

		$name = trim($bind->first_name.' '.$bind->last_name);
		$bind->delete();

		$back = (string) Arr::get($_POST, 'back', '/');

		if ($back === '' OR strpos($back, '/') !== 0 OR strpos($back, '//') === 0)
		{
			$back = '/';
		}

		HTTP::redirect($back.'?notice='.rawurlencode('Связка с Яндексом («'.($name !== '' ? $name : $bind->email).'») удалена'), 303);
	}

	/**
	 * GET /nj659/admin — диагностика связок: список, последний вход, отвязка.
	 */
	public function action_admin()
	{
		$service = new Service_Nj659_Yandexauth;

		$this->template->content = View::factory('659/admin', [
			'rows' => $service->binds_all(200),
			'csrf' => Security::token(),
			'notice' => (string) $this->request->query('notice'),
		]);
	}

	// ------------------------------------------------------------------

	/**
	 * Ошибка потока для гостя: flash в сессию и назад на /auth/login
	 * (Controller_Erp100_Auth::action_login покажет её в блоке ошибок).
	 */
	protected function _login_error($message)
	{
		Session::instance()->set('nj659_login_error', $message);
		HTTP::redirect('/auth/login', 302);
	}

	/**
	 * Страница-сообщение (для залогиненного потока привязки).
	 */
	protected function _message($type, $title, $text)
	{
		$this->template->title = 'Вход через Яндекс';
		$this->template->content = View::factory('659/message', [
			'type'  => $type,
			'title' => $title,
			'text'  => $text,
			'href'  => '/',
			'link'  => 'На главную',
		]);
	}

}
