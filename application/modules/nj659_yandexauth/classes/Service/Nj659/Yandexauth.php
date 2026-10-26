<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-659 «Вход через Яндекс» — сервисный слой.
 *
 * Server-side OAuth 2.0 (authorization code): без внешних скриптов на
 * фронтенде — только redirect'ы и POST/GET c бэкенда (правила проекта:
 * внешние хосты в браузерном коде запрещены).
 *
 * Внешние HTTP-запросы обёрнуты в защищённые методы _post()/_get() —
 * юнит-тесты мокают их и не ходят в сеть.
 *
 * Связки хранит erp100: таблица users_oauth (UNIQUE(provider, provider_id)),
 * ORM-модели Model_Erp100_UserOauth / Model_Erp100_User_Oauth.
 * Логин: Auth::instance()->force_login($user).
 */
class Service_Nj659_Yandexauth
{
	/** Короткое имя провайдера (колонка users_oauth.provider). */
	const PROVIDER = 'yandex';

	/** @var array конфиг nj659_yandexauth */
	protected $_config = [];

	public function __construct()
	{
		$this->_config = Kohana::$config->load('nj659_yandexauth');
	}

	/**
	 * Настроен ли провайдер (client_id и secret из .env)?
	 *
	 * @return bool
	 */
	public function is_configured()
	{
		return $this->_config['client_id'] !== '' AND $this->_config['client_secret'] !== '';
	}

	/**
	 * Ссылка «Войти через Яндекс» для страницы /auth/login.
	 * Модаль/виджеты не используются — обычный переход на /auth/yandex.
	 *
	 * @param  string $text подпись кнопки
	 * @param  string $class css-класс (кнопка платформенного стиля)
	 * @return string html
	 */
	public function login_button($text = 'Войти через Яндекс', $class = 'btn-yandex')
	{
		if ( ! $this->is_configured())
			return '';

		$class = HTML::chars($class);
		$text = HTML::chars($text);

		return '<a href="/auth/yandex" class="'.$class.'"><i class="fab fa-yandex" aria-hidden="true"></i> '.$text.'</a>';
	}

	/**
	 * URL авторизации Яндекса (шаг 1): пользователь подтверждает доступ.
	 *
	 * @param  string $state anti-csrf одноразовый ключ (кладётся в сессию)
	 * @return string абсолютный url
	 * @throws Exception если провайдер не настроен
	 */
	public function authorize_url($state)
	{
		if ( ! $this->is_configured())
			throw new Exception('NJ-659: Yandex OAuth не настроен (задайте YANDEX_OAUTH_* в .env)');

		$params = [
			'response_type' => 'code',
			'client_id'     => $this->_config['client_id'],
			'redirect_uri'  => $this->_redirect_uri(),
			'state'         => $state,
		];

		return $this->_config['authorize_url'].'?'.http_build_query($params);
	}

	/**
	 * Обмен code на access_token (шаг 2) и загрузка профиля (шаг 3).
	 *
	 * @param  string $code одноразовый код ответа Яндекса
	 * @return array  [access_token => string, profile => array]
	 * @throws Exception при ошибке обмена/профиля
	 */
	public function fetch_access($code)
	{
		if ( ! $this->is_configured())
			throw new Exception('NJ-659: Yandex OAuth не настроен');

		$response = $this->_post($this->_config['token_url'], [
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => $this->_config['client_id'],
			'client_secret' => $this->_config['client_secret'],
			'redirect_uri'  => $this->_redirect_uri(),
		]);

		$token = Arr::get($response, 'access_token');

		if ( ! $token)
			throw new Exception('NJ-659: Яндекс не вернул access_token ('.implode(';', array_keys($response)).')');

		return [
			'access_token' => $token,
			'profile'      => $this->fetch_profile($token),
		];
	}

	/**
	 * Профиль пользователя login.yandex.ru/info.
	 * Возвращает нормализованный массив с ключами provider_id, login,
	 * real_name, email, sex, birthday, avatar_id.
	 *
	 * @param  string $access_token
	 * @return array
	 * @throws Exception при ошибке запроса
	 */
	public function fetch_profile($access_token)
	{
		$data = $this->_get($this->_config['info_url'].'?format=json', [
			'Authorization: OAuth '.$access_token,
		]);

		$provider_id = Arr::get($data, 'id');

		if ( ! $provider_id)
			throw new Exception('NJ-659: Яндекс не вернул профиль (нет id)');

		return [
			'provider_id' => (string) $provider_id,
			'login'       => (string) Arr::get($data, 'login', ''),
			'real_name'   => (string) Arr::get($data, 'real_name', ''),
			'email'       => (string) Arr::get($data, 'default_email', Arr::get($data, 'email', '')),
			'sex'         => (string) Arr::get($data, 'sex', ''),
			'birthday'    => (string) Arr::get($data, 'birthday', ''),
			'avatar_id'   => (string) Arr::get($data, 'avatar_id', ''),
		];
	}

	/**
	 * Обработать вход: найти/создать пользователя, связать, залогинить.
	 *
	 * @param  array $access   результат fetch_access() (token + profile)
	 * @param  array $settings локальные настройки (autocreate и т.п.)
	 * @return array  [user => Model_User|NULL, created => bool, email => string]
	 * @throws Exception если вход невозможен
	 */
	public function login_with(array $access, array $settings = [])
	{
		$profile    = $access['profile'];
		$autocreate = Arr::get($settings, 'autocreate', TRUE);

		// 1. Уже есть связка?
		$bind = $this->bind_by_provider_id($profile['provider_id']);

		if ($bind)
		{
			$user = $bind->user_id
				? ORM::factory('User', (int) $bind->user_id)
				: NULL;

			if ($user AND $user->loaded())
			{
				$this->update_bind($bind, $access['access_token'], $profile);
				Auth::instance()->force_login($user);

				return ['user' => $user, 'created' => FALSE, 'email' => $user->email];
			}

			// Связка есть, пользователя нет (удалён) — сбрасываем её и ищем по email.
			$bind->delete();
		}

		// 2. Пользователь с таким email уже есть — привязываем к нему.
		//    Email подтверждён самим Яндексом, поэтому заодно закрываем
		//    висящее неподтверждение (иначе force_login упрётся в него).
		$email = $profile['email'];

		if ($email !== '')
		{
			$user = ORM::factory('User')->where('email', '=', $email)->find();

			if ($user->loaded())
			{
				if ((int) $user->email_confirmed !== 1)
				{
					$user->email_confirmed = 1;
					$user->confirmation_token = '';
					$user->update();
				}

				$this->create_bind($user->id, $access['access_token'], $profile);
				Auth::instance()->force_login($user);

				return ['user' => $user, 'created' => FALSE, 'email' => $email];
			}
		}

		// 3. Новичок: создаём аккаунт, если разрешено.
		if ( ! $autocreate)
			throw new Exception('NJ-659: аккаунт с таким email не найден — свяжите его обычным входом или обратитесь к администратору');

		if ($email === '')
			$email = $profile['provider_id'].'@yandex.ru'; // технический адрес без почты

		$user = $this->create_user($email, $profile);
		$this->create_bind($user->id, $access['access_token'], $profile);
		Auth::instance()->force_login($user);

		return ['user' => $user, 'created' => TRUE, 'email' => $email];
	}

	/**
	 * Создать локального пользователя (random-пароль, роль login).
	 * Колонки users: только email/username/password/email_confirmed —
	 * имя и прочие метаданные Яндекса живут в users_oauth.
	 *
	 * @param  string $email
	 * @param  array  $profile нормализованный профиль fetch_profile()
	 * @return Model_User
	 * @throws Exception при ошибке создания
	 */
	public function create_user($email, array $profile)
	{
		if (ORM::factory('User')->where('email', '=', $email)->find()->loaded())
			throw new Exception('NJ-659: пользователь с email '.$email.' уже существует');

		$user = ORM::factory('User');
		$user->email = $email;
		$user->username = $this->unique_username($this->_username_base($profile));
		$user->password = Text::random('alnum', 16);
		// email подтверждён Яндексом — вход сразу, без письма
		$user->email_confirmed = 1;

		try
		{
			$user->save();
		}
		catch (ORM_Validation_Exception $e)
		{
			throw new Exception('NJ-659: не удалось создать пользователя ('.implode('; ', array_map('strval', $e->errors(''))).')');
		}

		// роль login (см. Service_Erp100_Auth::register)
		$user->add('roles', ORM::factory('Role', ['name' => 'login']));

		return $user;
	}

	/**
	 * База для username: латинский логин Яндекса или yandex_<id>.
	 *
	 * @param  array $profile
	 * @return string
	 */
	protected function _username_base(array $profile)
	{
		$login = trim((string) Arr::get($profile, 'login', ''));

		if ($login !== '' AND preg_match('/^[a-zA-Z0-9_.-]+$/', $login))
			return $login;

		return 'yandex_'.Arr::get($profile, 'provider_id', 'user');
	}

	/**
	 * Уникальный username по базе (user, user2, user3…), латиница, ≤32
	 * (правило Model_Auth_User: not_empty, max_length 32, unique).
	 *
	 * @param  string $base
	 * @return string
	 */
	public function unique_username($base)
	{
		$base = substr(preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $base), 0, 24);

		if ($base === '')
			$base = 'yandex_user';

		$slug = $base;
		$i = 1;

		while (ORM::factory('User')->where('username', '=', $slug)->find()->loaded())
		{
			$i++;
			$slug = substr($base, 0, 32 - strlen($i)).$i;
		}

		return substr($slug, 0, 32);
	}

	/**
	 * Связка по provider_id Яндекса.
	 *
	 * @param  string $provider_id
	 * @return Model_Erp100_UserOauth|NULL
	 */
	public function bind_by_provider_id($provider_id)
	{
		$bind = ORM::factory('Erp100_UserOauth')
			->where('provider', '=', self::PROVIDER)
			->and_where('provider_id', '=', (string) $provider_id)
			->find();

		return $bind->loaded() ? $bind : NULL;
	}

	/**
	 * Связки пользователя (для страницы профиля/настроек).
	 *
	 * @param  int $user_id
	 * @return array
	 */
	public function binds_for_user($user_id)
	{
		return DB::select()->from('users_oauth')
			->where('provider', '=', self::PROVIDER)
			->and_where('user_id', '=', (int) $user_id)
			->order_by('id', 'ASC')
			->execute()
			->as_array();
	}

	/**
	 * Все связки Яндекса (админ-диагностика).
	 *
	 * @param  int $limit
	 * @return array
	 */
	public function binds_all($limit = 200)
	{
		return DB::select(
			['u.id','id'], ['u.user_id','user_id'], ['u.provider_id','provider_id'],
			['u.first_name','first_name'], ['u.last_name','last_name'],
			['u.email','email'], ['u.updated_at','updated_at'], ['u.created_at','created_at'],
			['uu.email','user_email'], ['uu.username','username']
		)
			->from(['users_oauth','u'])
			->join(['users','uu'])
			->on('uu.id', '=', 'u.user_id')
			->where('u.provider', '=', self::PROVIDER)
			->order_by('u.id', 'DESC')
			->limit((int) $limit)
			->execute()
			->as_array();
	}

	/**
	 * Создать связку (UNIQUE(provider, provider_id) страхует от гонок).
	 * Колонки users_oauth: first_name/last_name (не real_name), дата
	 * birthday — пустая строка недопустима, только NULL.
	 *
	 * @param  int    $user_id
	 * @param  string $access_token
	 * @param  array  $profile    результат fetch_profile()
	 * @return Model_Erp100_UserOauth
	 * @throws Exception если связка уже есть
	 */
	public function create_bind($user_id, $access_token, array $profile)
	{
		if ($this->bind_by_provider_id($profile['provider_id']))
			throw new Exception('NJ-659: аккаунт Яндекса уже связан');

		list($first_name, $last_name) = $this->_split_name(Arr::get($profile, 'real_name', ''));
		$birthday = trim((string) Arr::get($profile, 'birthday', ''));

		$bind = ORM::factory('Erp100_UserOauth');
		$bind->provider = self::PROVIDER;
		$bind->provider_id = (string) $profile['provider_id'];
		$bind->user_id = (int) $user_id;
		$bind->access_token = (string) $access_token;
		$bind->first_name = $first_name;
		$bind->last_name = $last_name;
		$bind->email = Arr::get($profile, 'email', '');
		$bind->sex = Arr::get($profile, 'sex', '');
		$bind->birthday = $birthday !== '' ? $birthday : NULL;
		$bind->avatar_id = Arr::get($profile, 'avatar_id', '');
		$bind->create();

		return $bind;
	}

	/**
	 * Обновить токен и профиль существующей связки (updated_at в PostgreSQL
	 * сам не обновляется — ставим руками).
	 *
	 * @param  Model_Erp100_UserOauth $bind
	 * @param  string $access_token
	 * @param  array  $profile
	 * @return void
	 */
	public function update_bind($bind, $access_token, array $profile)
	{
		list($first_name, $last_name) = $this->_split_name(Arr::get($profile, 'real_name', ''));
		$birthday = trim((string) Arr::get($profile, 'birthday', ''));

		$bind->access_token = (string) $access_token;
		$bind->first_name = $first_name;
		$bind->last_name = $last_name;
		$bind->email = Arr::get($profile, 'email', '');
		$bind->avatar_id = Arr::get($profile, 'avatar_id', '');
		$bind->birthday = $birthday !== '' ? $birthday : NULL;
		$bind->updated_at = date('Y-m-d H:i:s');
		$bind->update();
	}

	/**
	 * real_name Яндекса («Иван Петров») → [first_name, last_name].
	 *
	 * @param  string $real_name
	 * @return array [string, string]
	 */
	public function _split_name($real_name)
	{
		$parts = explode(' ', trim(preg_replace('/\s+/u', ' ', (string) $real_name)));

		return [
			(string) Arr::get($parts, 0, ''),
			(string) Arr::get($parts, 1, ''),
		];
	}

	/**
	 * Удалить связку пользователя (отвязка).
	 *
	 * @param  int $user_id владелец (защита от отвязки чужой связки)
	 * @param  int $bind_id
	 * @return bool удалена ли
	 */
	public function delete_bind($user_id, $bind_id)
	{
		$bind = ORM::factory('Erp100_UserOauth', (int) $bind_id);

		if ( ! $bind->loaded() OR $bind->provider !== self::PROVIDER OR (int) $bind->user_id !== (int) $user_id)
			return FALSE;

		$bind->delete();

		return TRUE;
	}

	/**
	 * Ссылка на аватар Яндекса (без внешних скриптов — прямой URL картинки).
	 *
	 * @param  string $avatar_id
	 * @param  string $size
	 * @return string пустая строка, если аватара нет
	 */
	public function avatar_url($avatar_id, $size = 'islands-200')
	{
		$avatar_id = trim((string) $avatar_id);

		if ($avatar_id === '')
			return '';

		return 'https://avatars.yandex.net/get-yapic/'.$avatar_id.'/'.$size;
	}

	/**
	 * redirect_uri с фолбэком на текущий хост.
	 *
	 * @return string
	 */
	protected function _redirect_uri()
	{
		if ($this->_config['redirect_uri'] !== '')
			return $this->_config['redirect_uri'];

		$host = Arr::get(parse_url(URL::base(TRUE, TRUE)), 'host', 'n2j.ru');

		return 'https://'.$host.'/auth/yandex/callback';
	}

	/**
	 * POST с form-encoded телом, ответ — JSON.
	 *
	 * @param  string $url
	 * @param  array  $params
	 * @return array
	 * @throws Exception при curl-ошибке или не-json ответе
	 */
	protected function _post($url, array $params)
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST           => TRUE,
			CURLOPT_POSTFIELDS     => http_build_query($params),
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT        => (int) $this->_config['timeout'],
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_SSL_VERIFYPEER => TRUE,
		]);
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err  = curl_error($ch);
		curl_close($ch);

		if ($body === FALSE)
			throw new Exception('NJ-659: ошибка запроса к Яндексу ('.$err.')');

		$data = json_decode($body, TRUE);

		if ( ! is_array($data))
			throw new Exception('NJ-659: Яндекс вернул не-JSON ответ (HTTP '.$code.')');

		return $data;
	}

	/**
	 * GET с заголовками, ответ — JSON.
	 *
	 * @param  string $url
	 * @param  array  $headers
	 * @return array
	 * @throws Exception при curl-ошибке или не-json ответе
	 */
	protected function _get($url, array $headers = [])
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT        => (int) $this->_config['timeout'],
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_SSL_VERIFYPEER => TRUE,
		]);
		$body = curl_exec($ch);
		$err  = curl_error($ch);
		curl_close($ch);

		if ($body === FALSE)
			throw new Exception('NJ-659: ошибка запроса к Яндексу ('.$err.')');

		$data = json_decode($body, TRUE);

		if ( ! is_array($data))
			throw new Exception('NJ-659: Яндекс вернул не-JSON ответ');

		return $data;
	}
}
