<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-657 «Навыки Алисы»: вебхук Яндекс.Диалогов с ответами ИИ.
 *
 * Судьба запроса:
 *   1. extract_request() — разбор и нормализация протокола Алисы (чистая);
 *   2. find_skill() — какой навык отвечает: по токену из адреса, по
 *      session.skill_id из консоли Диалогов или навык по умолчанию;
 *   3. route_intent() — welcome / help / exit / dangerous / llm (чистая);
 *   4. llm_messages() + ask_llm() — вопрос с памятью диалога уходит в LLM
 *      через фасад Llm:: (модуль llm, одиночный быстрый вызов);
 *   5. build_response() — ответ в протоколе Алисы (чистая);
 *   6. журнал nj_657_dialogs (он же — память), опционально MQTT-публикация.
 *
 * Повторный вебхук с тем же (session_id, message_id) не дёргает ИИ заново —
 * отдаётся сохранённый ответ (Яндекс повторяет запрос при таймауте).
 *
 * Чистые методы (без БД) покрыты tests/Unit/AliceServiceTest.php и стендом
 * .tools/nj657_alice_stand.php; серверная часть — тестовая консоль
 * /alice/admin/test.
 */
class Service_Nj657_Alice
{
	const CODE_ALPHABET = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
	const CODE_LEN = 10;
	const TOKEN_ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	const TOKEN_LEN = 22;

	/** Интенты журнала (белые списки фильтров). */
	const INTENTS = ['welcome', 'help', 'exit', 'llm', 'fallback', 'dangerous'];

	// ------------------------------------------------------------------
	// Конфиг
	// ------------------------------------------------------------------

	/** Конфиг модуля как массив. */
	public static function config()
	{
		$config = Kohana::$config->load('nj657_alice');

		return $config instanceof Kohana_Config_Group ? $config->as_array() : (array) $config;
	}

	/** Пресеты ролей для формы навыка. */
	public static function presets()
	{
		return (array) Arr::get(self::config(), 'presets', []);
	}

	/** Модель ИИ платформы по умолчанию (подсказка в форме). */
	public static function platform_default_model()
	{
		try
		{
			$model = Kohana::$config->load('llm')->get('default_model');

			return (string) $model;
		}
		catch (Throwable $e)
		{
			return '';
		}
	}

	/**
	 * PostgreSQL BOOLEAN приходит из драйвера строкой 't'/'f', а пустой каст
	 * (bool) превращает 'f' в TRUE — из-за этого навык закрывал сессию после
	 * каждого ответа ИИ. Единая проверка правды для булевых полей навыка.
	 */
	public static function truthy($value)
	{
		return $value === TRUE OR $value === 't' OR $value === 'true' OR $value === 1 OR $value === '1';
	}

	/** Булевы поля строки навыка (pg отдаёт 't'/'f') → PHP-булиты. */
	protected static function _bools(array $row)
	{
		foreach (['is_active', 'is_default', 'end_session'] as $key)
		{
			if (array_key_exists($key, $row))
			{
				$row[$key] = self::truthy($row[$key]);
			}
		}

		return $row;
	}

	// ------------------------------------------------------------------
	// Чистые функции протокола (без БД)
	// ------------------------------------------------------------------

	/**
	 * Разбор запроса Алисы в нормализованный массив.
	 *
	 * @param   mixed $data json_decode тела вебхука
	 * @return  array ['ok', 'error', 'session' => [...], 'request' => [...]]
	 */
	public static function extract_request($data)
	{
		if ( ! is_array($data))
		{
			return ['ok' => FALSE, 'error' => 'body is not a json object'];
		}

		$session = Arr::get($data, 'session');
		$request = Arr::get($data, 'request');

		if ( ! is_array($session) OR ! is_array($request))
		{
			return ['ok' => FALSE, 'error' => 'session or request missing'];
		}

		$session_id = mb_substr((string) Arr::get($session, 'session_id', ''), 0, 80);
		$user_id    = mb_substr((string) Arr::get($session, 'user_id', ''), 0, 80);

		// user.user_id — постоянный идентификатор пользователя (живёт дольше
		// сессии); когда его нет, берём устаревший session.user_id.
		$deep_user = Arr::get($session, 'user');
		$deep_id   = is_array($deep_user) ? (string) Arr::get($deep_user, 'user_id', '') : '';

		$markup = Arr::get($request, 'markup');
		$payload = Arr::get($request, 'payload');

		$interfaces = (array) Arr::path($data, 'meta.interfaces', []);

		return [
			'ok'     => TRUE,
			'error'  => '',
			'version' => (string) Arr::get($data, 'version', '1.0'),
			'session' => [
				'session_id' => $session_id,
				'message_id' => (int) Arr::get($session, 'message_id', 0),
				'user_id'    => $user_id,
				'skill_id'   => mb_substr((string) Arr::get($session, 'skill_id', ''), 0, 64),
				'user_key'   => self::user_key($deep_id !== '' ? $deep_id : $user_id),
				'is_new'     => (bool) Arr::get($session, 'new', FALSE),
				'has_user'   => $deep_id !== '',
			],
			'request' => [
				'type'       => mb_substr((string) Arr::get($request, 'type', 'SimpleUtterance'), 0, 40),
				'command'    => mb_substr((string) Arr::get($request, 'command', ''), 0, 1024),
				'utterance'  => mb_substr((string) Arr::get($request, 'original_utterance', ''), 0, 2048),
				'dangerous'  => is_array($markup) AND (bool) Arr::get($markup, 'dangerous_context', FALSE),
				'payload'    => is_array($payload) ? $payload : [],
				'has_screen' => array_key_exists('screen', $interfaces),
			],
		];
	}

	/**
	 * Ключ пользователя для памяти и журнала: короткий хеш постоянного
	 * идентификатора Яндекса — сам идентификатор не копим.
	 */
	public static function user_key($user_id)
	{
		$user_id = trim((string) $user_id);

		return $user_id === '' ? '' : substr(sha1('nj657:'.$user_id), 0, 32);
	}

	/** Нормализация команды: нижний регистр, без краевых знаков. */
	public static function normalize_command($command)
	{
		$command = mb_strtolower(trim((string) $command));
		$command = trim($command, " \t\n\r.,!?…:;«»'\"-");

		return $command;
	}

	/**
	 * Текст вопроса для ИИ: команда (или реплика), для новой сессии — без
	 * имени активации («Алиса, запусти Мой диалог и скажи …»).
	 */
	public static function question_text(array $extracted, array $skill)
	{
		$request = $extracted['request'];
		$question = trim($request['command'] !== '' ? $request['command'] : $request['utterance']);

		// ButtonPressed с пустой командой: берём команду из payload кнопки.
		if ($question === '' AND $request['type'] === 'ButtonPressed')
		{
			$payload_command = Arr::get($request['payload'], 'command');
			$question = is_string($payload_command) ? trim($payload_command) : '';
		}

		if ($extracted['session']['is_new'])
		{
			foreach (self::parse_lines((string) Arr::get($skill, 'activation_names', '')) as $name)
			{
				$name = mb_strtolower(trim($name));

				if ($name === '')
				{
					continue;
				}

				if (mb_stripos($question, $name) === 0)
				{
					$question = trim(mb_substr($question, mb_strlen($name)));
					$question = ltrim($question, " \t.,!?…:;");
					break;
				}
			}
		}

		return mb_substr($question, 0, (int) Arr::get(self::config(), 'limits.question_max', 1024));
	}

	/**
	 * Какой сценарий отвечает. Чистая функция — зависит только от аргументов.
	 *
	 * @return string welcome|help|exit|llm
	 */
	public static function route_intent($command, $is_new, array $skill, array $config = NULL)
	{
		if ($config === NULL)
		{
			$config = self::config();
		}

		$commands = (array) Arr::get($config, 'commands', []);
		$norm = self::normalize_command($command);

		if ($norm === '')
		{
			return $is_new ? 'welcome' : 'help';
		}

		if (in_array($norm, (array) Arr::get($commands, 'exit', []), TRUE))
		{
			return 'exit';
		}

		if (in_array($norm, (array) Arr::get($commands, 'help', []), TRUE))
		{
			return 'help';
		}

		if ($is_new AND in_array($norm, (array) Arr::get($commands, 'welcome', []), TRUE))
		{
			return 'welcome';
		}

		return 'llm';
	}

	/**
	 * Ответ в протоколе Алисы 1.0: эхо сессии + text/tts/buttons/end_session.
	 */
	public static function build_response(array $session, $text, $tts, array $buttons, $end_session)
	{
		return [
			'version' => '1.0',
			'session' => [
				'session_id' => (string) Arr::get($session, 'session_id', ''),
				'message_id' => (int) Arr::get($session, 'message_id', 0),
				'user_id'    => (string) Arr::get($session, 'user_id', ''),
			],
			'response' => [
				'text'       => (string) $text,
				'tts'        => (string) $tts,
				'buttons'    => array_values($buttons),
				'end_session' => (bool) $end_session,
			],
		];
	}

	/** Кнопки ответа из поля навыка «кнопки» (одна строка = одна кнопка). */
	public static function parse_buttons($buttons_text)
	{
		$limits = (array) Arr::get(self::config(), 'limits', []);
		$max_count = (int) Arr::get($limits, 'buttons_max', 5);
		$max_len = (int) Arr::get($limits, 'button_title_max', 64);

		$out = [];

		foreach (self::parse_lines((string) $buttons_text) as $line)
		{
			$title = mb_substr(trim($line), 0, $max_len);

			if ($title === '')
			{
				continue;
			}

			$out[] = $title;

			if (count($out) >= $max_count)
			{
				break;
			}
		}

		return $out;
	}

	/** Кнопки в формате протокола Алисы (hide = прятать после нажатия). */
	public static function response_buttons(array $skill)
	{
		$out = [];

		foreach (self::parse_buttons((string) Arr::get($skill, 'buttons_text', '')) as $title)
		{
			$out[] = [
				'title'   => $title,
				'payload' => ['command' => $title],
				'hide'    => TRUE,
			];
		}

		return $out;
	}

	/** Кнопки показываем только экранным устройствам. */
	public static function buttons_for(array $skill, array $extracted)
	{
		return $extracted['request']['has_screen'] ? self::response_buttons($skill) : [];
	}

	/**
	 * TTS-версия ответа: Алиса читает текст вслух, markdown и ссылки в речи
	 * не нужны. Плюс — ограничение длины (TTS ≈ 3000 символов).
	 */
	public static function clean_for_tts($text)
	{
		$t = str_replace("\r", '', (string) $text);

		$t = preg_replace('/\[([^\]]*)\]\([^)]*\)/us', '$1', $t); // [текст](ссылка)
		$t = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $t);        // заголовки
		$t = preg_replace('/(\*\*|__|~~|`+)/u', '', $t);           // выделения, код
		$t = preg_replace('/^\s*[-*+•]\s+/mu', '', $t);            // маркеры списка
		$t = preg_replace('/^\s*\d+[.)]\s+/mu', '', $t);           // нумерация
		$t = preg_replace('/\|/u', ' ', $t);                       // таблицы
		$t = preg_replace('/https?:\/\/\S+/u', '', $t);            // ссылки вслух не читаем
		$t = preg_replace('/[ \t]+/u', ' ', $t);
		$t = preg_replace('/\n{2,}/u', "\n", $t);
		$t = trim($t);

		return mb_substr($t, 0, 2900);
	}

	/** Обрезка длинного ответа по границе слова. */
	public static function trim_answer($text, $max_chars)
	{
		$text = trim((string) $text);
		$max_chars = (int) $max_chars;

		if ($max_chars < 100 OR mb_strlen($text) <= $max_chars)
		{
			return $text;
		}

		$cut = mb_substr($text, 0, $max_chars);
		$pos = mb_strrpos($cut, ' ');

		if ($pos === FALSE OR $pos < (int) floor($max_chars * 0.6))
		{
			$pos = mb_strrpos($cut, "\n");

			if ($pos === FALSE OR $pos < (int) floor($max_chars * 0.6))
			{
				$pos = $max_chars;
			}
		}

		return trim(mb_substr($cut, 0, (int) $pos)).'…';
	}

	/** Многострочное поле в список непустых строк. */
	public static function parse_lines($text)
	{
		$out = [];

		foreach (explode("\n", str_replace("\r", '', (string) $text)) as $line)
		{
			$line = trim($line);

			if ($line !== '')
			{
				$out[] = $line;
			}
		}

		return $out;
	}

	/** Полный адрес вебхука навыка (с токеном, если задан). */
	public static function webhook_url(array $skill = NULL)
	{
		$config = self::config();
		$base = rtrim((string) Arr::get($config, 'webhook_base', ''), '/');
		$path = trim((string) Arr::get($config, 'webhook_path', ''), '/');
		$token = $skill === NULL ? '' : trim((string) Arr::get($skill, 'webhook_token', ''));

		return $token !== '' ? $base.'/'.$path.'/'.$token : $base.'/'.$path;
	}

	// ------------------------------------------------------------------
	// БД: навыки
	// ------------------------------------------------------------------

	protected static function _db()
	{
		static $db = NULL;

		return $db !== NULL ? $db : $db = Database::instance();
	}

	protected static function _q($value)
	{
		return self::_db()->quote($value);
	}

	protected static function _one($sql)
	{
		$row = self::_db()->query(Database::SELECT, $sql, FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	protected static function _all($sql)
	{
		return self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();
	}

	protected static function _exec($type, $sql)
	{
		return self::_db()->query($type, $sql, FALSE);
	}

	public static function skills()
	{
		$rows = [];

		foreach (self::_all('SELECT * FROM nj_657_skills ORDER BY id') as $row)
		{
			$rows[] = self::_bools($row);
		}

		return $rows;
	}

	public static function skill_by_code($code)
	{
		$code = trim((string) $code);

		if ($code === '' OR preg_match('/^[A-Za-z0-9]{6,12}$/', $code) !== 1)
		{
			return NULL;
		}

		$row = self::_one('SELECT * FROM nj_657_skills WHERE code = '.self::_q($code));

		return $row === NULL ? NULL : self::_bools($row);
	}

	public static function skill_by_id($id)
	{
		$id = (int) $id;

		if ($id <= 0)
		{
			return NULL;
		}

		$row = self::_one('SELECT * FROM nj_657_skills WHERE id = '.$id);

		return $row === NULL ? NULL : self::_bools($row);
	}

	/**
	 * Какой навык отвечает на вебхук.
	 *
	 * Приоритет: токен из адреса → session.skill_id → навык по умолчанию.
	 * Неверный токен не «проваливается» на навык по умолчанию — иначе токен
	 * не защищал бы ничего.
	 */
	public static function find_skill($token, $yandex_skill_id)
	{
		$token = trim((string) $token);

		if ($token !== '')
		{
			$row = self::_one('SELECT * FROM nj_657_skills WHERE webhook_token = '.self::_q($token));

			return $row === NULL ? NULL : self::_bools($row);
		}

		$yandex_skill_id = trim((string) $yandex_skill_id);

		if ($yandex_skill_id !== '')
		{
			$skill = self::_one('SELECT * FROM nj_657_skills WHERE yandex_skill_id = '.self::_q($yandex_skill_id));

			if ($skill !== NULL)
			{
				return self::_bools($skill);
			}
		}

		$row = self::_one('SELECT * FROM nj_657_skills WHERE is_default ORDER BY id LIMIT 1');

		return $row === NULL ? NULL : self::_bools($row);
	}

	/** Навык по умолчанию (для тестовой консоли, когда не выбран). */
	public static function default_skill()
	{
		$skill = self::_one('SELECT * FROM nj_657_skills WHERE is_default ORDER BY id LIMIT 1')
			?: self::_one('SELECT * FROM nj_657_skills WHERE is_active ORDER BY id LIMIT 1');

		return $skill === NULL ? NULL : self::_bools($skill);
	}

	/**
	 * Сохранение навыка из формы админки (создание и правка).
	 *
	 * @param   array $post $_POST
	 * @return  array ['ok', 'error', 'errors' => [], 'code']
	 */
	public static function save_skill(array $post)
	{
		$config = self::config();
		$limits = (array) Arr::get($config, 'limits', []);
		$errors = [];

		$code = trim((string) Arr::get($post, 'code', ''));
		$is_edit = $code !== '';
		$skill = $is_edit ? self::skill_by_code($code) : NULL;

		if ($is_edit AND $skill === NULL)
		{
			return ['ok' => FALSE, 'error' => 'Навык не найден', 'errors' => ['Навык не найден'], 'code' => ''];
		}

		$title = trim((string) Arr::get($post, 'title', ''));
		if ($title === '' OR mb_strlen($title) > (int) Arr::get($limits, 'title_max', 100))
		{
			$errors[] = 'Название: 1–'.Arr::get($limits, 'title_max', 100).' символов.';
		}

		$yandex_skill_id = trim((string) Arr::get($post, 'yandex_skill_id', ''));
		if (mb_strlen($yandex_skill_id) > (int) Arr::get($limits, 'yandex_skill_id_max', 64)
			OR ($yandex_skill_id !== '' AND preg_match('/^[0-9A-Za-z-]+$/', $yandex_skill_id) !== 1))
		{
			$errors[] = 'ID навыка Яндекса: до 64 символов, цифры, латиница и дефис (или пусто).';
		}

		$webhook_token = trim((string) Arr::get($post, 'webhook_token', ''));
		if ($webhook_token !== '' AND (strlen($webhook_token) > (int) Arr::get($limits, 'webhook_token_max', 64)
			OR preg_match('/^[A-Za-z0-9]+$/', $webhook_token) !== 1))
		{
			$errors[] = 'Токен вебхука: латиница и цифры, до 64 символов (или пусто).';
		}

		$model = trim((string) Arr::get($post, 'model', ''));
		if ($model !== '' AND (mb_strlen($model) > 120 OR preg_match('/^[A-Za-z0-9_:\/.\-]+$/', $model) !== 1))
		{
			$errors[] = 'Модель: строка вида провайдер::модель (до 120 символов) или пусто.';
		}

		$temperature = str_replace(',', '.', (string) Arr::get($post, 'temperature', '0.2'));
		$temperature = is_numeric($temperature) ? (float) $temperature : -1;
		if ($temperature < 0 OR $temperature > 2)
		{
			$errors[] = 'Температура: число от 0 до 2.';
		}

		$int_fields = [
			'max_tokens'         => ['max_tokens', 400, 'Лимит токенов ответа'],
			'answer_max_chars'   => ['answer_max_chars', 1024, 'Максимум символов ответа'],
			'history_depth'      => ['history_depth', 6, 'Глубина памяти (реплик)'],
			'history_window_min' => ['history_window_min', 120, 'Окно памяти (минут)'],
		];

		$int_values = [];

		foreach ($int_fields as $field => [$range_key, $default, $label])
		{
			$value = (int) Arr::get($post, $field, $default);
			[$min, $max] = (array) Arr::get($limits, $range_key, [1, 4000]);

			if ($value < $min OR $value > $max)
			{
				$errors[] = $label.': целое от '.$min.' до '.$max.'.';
			}

			$int_values[$field] = $value;
		}

		$text_fields = [
			'system_text'     => ['system_text_max', 'Системный промпт'],
			'activation_names' => ['text_max', 'Имена активации'],
			'welcome_text'    => ['text_max', 'Приветствие'],
			'welcome_tts'     => ['text_max', 'Приветствие (TTS)'],
			'help_text'       => ['text_max', 'Ответ на «помощь»'],
			'farewell_text'   => ['text_max', 'Прощание'],
			'fallback_text'   => ['text_max', 'Ответ при сбое ИИ'],
			'buttons_text'    => ['text_max', 'Кнопки'],
		];

		$text_values = [];

		foreach ($text_fields as $field => [$limit_key, $label])
		{
			$value = trim((string) Arr::get($post, $field, ''));
			$limit = (int) Arr::get($limits, $limit_key, 2000);

			if (mb_strlen($value) > $limit)
			{
				$errors[] = $label.': до '.$limit.' символов.';
			}

			$text_values[$field] = $value;
		}

		$mqtt_topic = trim((string) Arr::get($post, 'mqtt_topic', ''));
		if ($mqtt_topic !== '' AND ( ! Service_Nj657_Mqtt::topic_valid($mqtt_topic) OR strlen($mqtt_topic) > (int) Arr::get($limits, 'mqtt_topic_max', 200)))
		{
			$errors[] = 'MQTT-топик: до 200 символов, без пробелов и символов + # (или пусто).';
		}

		// Уникальность привязок (кроме себя).
		if ($yandex_skill_id !== '')
		{
			$exists = self::_one('SELECT id FROM nj_657_skills WHERE yandex_skill_id = '.self::_q($yandex_skill_id)
				.($skill !== NULL ? ' AND id <> '.(int) $skill['id'] : ''));

			if ($exists !== NULL)
			{
				$errors[] = 'Такой ID навыка Яндекса уже привязан к другому навыку.';
			}
		}

		if ($webhook_token !== '')
		{
			$exists = self::_one('SELECT id FROM nj_657_skills WHERE webhook_token = '.self::_q($webhook_token)
				.($skill !== NULL ? ' AND id <> '.(int) $skill['id'] : ''));

			if ($exists !== NULL)
			{
				$errors[] = 'Такой токен вебхука уже занят другим навыком.';
			}
		}

		if ($errors)
		{
			return ['ok' => FALSE, 'error' => $errors[0], 'errors' => $errors, 'code' => $code];
		}

		$values = [
			'title'           => $title,
			'yandex_skill_id' => $yandex_skill_id,
			'webhook_token'   => $webhook_token,
			'is_active'       => Arr::get($post, 'is_active') !== NULL,
			'is_default'      => Arr::get($post, 'is_default') !== NULL,
			'model'           => $model,
			'temperature'     => number_format($temperature, 3, '.', ''),
			'max_tokens'      => $int_values['max_tokens'],
			'answer_max_chars' => $int_values['answer_max_chars'],
			'history_depth'   => $int_values['history_depth'],
			'history_window_min' => $int_values['history_window_min'],
			'end_session'     => Arr::get($post, 'end_session') !== NULL,
			'mqtt_topic'      => $mqtt_topic,
		];

		foreach ($text_values as $field => $value)
		{
			$values[$field] = $value;
		}

		try
		{
			if ($is_edit)
			{
				$sets = ['updated_at = now()'];

				foreach ($values as $field => $value)
				{
					$sets[] = '"'.$field.'" = '.self::_q($value);
				}

				self::_exec(Database::UPDATE, 'UPDATE nj_657_skills SET '.implode(', ', $sets)
					.' WHERE id = '.(int) $skill['id']);
			}
			else
			{
				$code = self::generate_code();
				$values['code'] = $code;

				$columns = implode(', ', array_map(function ($f) { return '"'.$f.'"'; }, array_keys($values)));
				$placeholders = implode(', ', array_map(function ($v) { return self::_q($v); }, array_values($values)));

				self::_exec(Database::INSERT, 'INSERT INTO nj_657_skills ('.$columns.') VALUES ('.$placeholders.')');
			}

			// Навык по умолчанию — ровно один.
			if ($values['is_default'])
			{
				self::_exec(Database::UPDATE, 'UPDATE nj_657_skills SET is_default = FALSE, updated_at = now()'
					.' WHERE is_default AND code <> '.self::_q($code));
			}
		}
		catch (Throwable $e)
		{
			Log_Plain::event(Log::ERROR, 'alice: навык :code не сохранён: :e', [':code' => $code, ':e' => $e->getMessage()]);

			return ['ok' => FALSE, 'error' => 'Ошибка базы: '.$e->getMessage(), 'errors' => ['Ошибка базы: '.$e->getMessage()], 'code' => $code];
		}

		Log_Plain::event(Log::INFO, 'alice: навык :code сохранён', [':code' => $code]);

		return ['ok' => TRUE, 'error' => '', 'errors' => [], 'code' => $code];
	}

	public static function delete_skill($code)
	{
		$skill = self::skill_by_code($code);

		if ($skill === NULL)
		{
			return FALSE;
		}

		self::_exec(Database::DELETE, 'DELETE FROM nj_657_skills WHERE id = '.(int) $skill['id']);
		Log_Plain::event(Log::INFO, 'alice: навык :code удалён (вместе с журналом диалогов)', [':code' => $code]);

		return TRUE;
	}

	public static function toggle_skill($code, $active)
	{
		$skill = self::skill_by_code($code);

		if ($skill === NULL)
		{
			return FALSE;
		}

		self::_exec(Database::UPDATE, 'UPDATE nj_657_skills SET is_active = '.($active ? 'TRUE' : 'FALSE')
			.', updated_at = now() WHERE id = '.(int) $skill['id']);

		return TRUE;
	}

	// ------------------------------------------------------------------
	// БД: журнал и статистика
	// ------------------------------------------------------------------

	/**
	 * Журнал диалогов с фильтрами и постраничностью.
	 *
	 * @return array ['rows', 'total', 'page', 'pages']
	 */
	public static function history($skill_id, $intent, $source, $page)
	{
		$per_page = (int) Arr::get(self::config(), 'limits.history_page', 50);
		$where = ['1 = 1'];

		$skill_id = (int) $skill_id;

		if ($skill_id > 0)
		{
			$where[] = 'd.skill_id = '.$skill_id;
		}

		if (in_array($intent, self::INTENTS, TRUE))
		{
			$where[] = 'd.intent = '.self::_q($intent);
		}

		if ($source === 'yandex' OR $source === 'test')
		{
			$where[] = 'd.source = '.self::_q($source);
		}

		$where_sql = implode(' AND ', $where);

		$total_row = self::_one('SELECT count(*) AS c FROM nj_657_dialogs d WHERE '.$where_sql);
		$total = (int) ($total_row['c'] ?? 0);
		$pages = max(1, (int) ceil($total / $per_page));
		$page = min(max(1, (int) $page), $pages);
		$offset = ($page - 1) * $per_page;

		$rows = self::_all('SELECT d.*, s.title AS skill_title
			FROM nj_657_dialogs d
			JOIN nj_657_skills s ON s.id = d.skill_id
			WHERE '.$where_sql.'
			ORDER BY d.id DESC
			LIMIT '.$per_page.' OFFSET '.$offset);

		return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $per_page];
	}

	/** Статистика навыков для дашборда админки. */
	public static function stats()
	{
		$rows = self::_all('SELECT s.id, s.code, s.title, s.is_active, s.is_default, s.yandex_skill_id,
				s.webhook_token, s.mqtt_topic, s.updated_at,
				COALESCE(d.cnt, 0) AS dialogs,
				COALESCE(d.day_cnt, 0) AS day_dialogs,
				d.last_at
			FROM nj_657_skills s
			LEFT JOIN (
				SELECT skill_id,
					count(*) AS cnt,
					count(*) FILTER (WHERE created_at > now() - interval \'1 day\') AS day_cnt,
					max(created_at) AS last_at
				FROM nj_657_dialogs GROUP BY skill_id
			) d ON d.skill_id = s.id
			ORDER BY s.id');

		foreach ($rows as & $row)
		{
			$row = self::_bools($row);
		}
		unset($row);

		return $rows;
	}

	/** Сохранённый ответ на повторный вебхук (Яндекс ретраит при таймауте). */
	protected static function _find_duplicate($yandex_session_id, $message_id)
	{
		if ($yandex_session_id === '')
		{
			return NULL;
		}

		return self::_one('SELECT id, answer_text, intent, meta::text AS meta_json
			FROM nj_657_dialogs
			WHERE yandex_session_id = '.self::_q($yandex_session_id).'
				AND message_id = '.(int) $message_id.'
			ORDER BY id DESC LIMIT 1');
	}

	// ------------------------------------------------------------------
	// ИИ
	// ------------------------------------------------------------------

	/**
	 * Сообщения для LLM: система + память диалога + вопрос.
	 */
	public static function llm_messages(array $skill, $user_key, $question)
	{
		$config = self::config();
		$system = trim((string) Arr::get($skill, 'system_text', ''));

		if ($system !== '' AND trim((string) Arr::get($config, 'voice_suffix', '')) !== '')
		{
			$system .= "\n\n".trim((string) Arr::get($config, 'voice_suffix', ''));
		}

		$messages = [];

		if ($system !== '')
		{
			$messages[] = ['role' => 'system', 'content' => $system];
		}

		$depth = (int) Arr::get($skill, 'history_depth', 6);

		if ($depth > 0 AND (string) $user_key !== '')
		{
			$window = max(1, (int) Arr::get($skill, 'history_window_min', 120));
			$rows = self::_all('SELECT question_text, answer_text FROM nj_657_dialogs
				WHERE skill_id = '.(int) Arr::get($skill, 'id', 0).'
					AND user_key = '.self::_q($user_key)."
					AND intent = 'llm'
					AND created_at > now() - interval '".(int) $window." minutes'
				ORDER BY id DESC
				LIMIT ".(int) $depth);

			foreach (array_reverse($rows) as $row)
			{
				if ((string) $row['question_text'] !== '')
				{
					$messages[] = ['role' => 'user', 'content' => (string) $row['question_text']];
				}

				$messages[] = ['role' => 'assistant', 'content' => (string) $row['answer_text']];
			}
		}

		$messages[] = ['role' => 'user', 'content' => (string) $question];

		return $messages;
	}

	/**
	 * Одиночный вызов LLM через фасад модуля llm. Ошибки не бросаем наружу —
	 * вебхук обязан ответить Алисе в любом случае.
	 *
	 * @return array ['answer', 'error', 'ms', 'model']
	 */
	public static function ask_llm(array $skill, array $messages)
	{
		$out = ['answer' => NULL, 'error' => '', 'ms' => 0, 'model' => ''];

		if ( ! class_exists('Llm'))
		{
			$out['error'] = 'модуль llm не подключён';

			return $out;
		}

		$options = [];
		$model = trim((string) Arr::get($skill, 'model', ''));

		if ($model !== '')
		{
			$options['model'] = $model;
		}

		$temperature = Arr::get($skill, 'temperature');

		if ($temperature !== NULL AND $temperature !== '')
		{
			$options['temperature'] = (float) $temperature;
		}

		$max_tokens = (int) Arr::get($skill, 'max_tokens', 400);

		if ($max_tokens > 0)
		{
			$options['max_tokens'] = $max_tokens;
		}

		$out['model'] = $model !== '' ? $model : self::platform_default_model();

		$start = microtime(TRUE);

		try
		{
			$response = Llm::chat($messages, $options);
			$answer = $response['choices'][0]['message']['content'] ?? NULL;

			if ($answer === NULL OR trim((string) $answer) === '')
			{
				$out['error'] = 'пустой ответ модели';
			}
			else
			{
				$out['answer'] = (string) $answer;
			}
		}
		catch (Throwable $e)
		{
			$out['error'] = mb_substr(get_class($e).': '.$e->getMessage(), 0, 300);
		}

		$out['ms'] = (int) round((microtime(TRUE) - $start) * 1000);

		return $out;
	}

	// ------------------------------------------------------------------
	// Пайплайн вебхука
	// ------------------------------------------------------------------

	/**
	 * Обработка запроса Алисы от входа до готового ответа.
	 *
	 * @param array  $data         разобранный JSON запроса
	 * @param string $token        токен из адреса вебхука ('' — без токена)
	 * @param array  $forced_skill подменить выбор навыка (тестовая консоль)
	 * @param string $source       'yandex' | 'test' (метка в журнале)
	 * @return array ['response', 'http', 'skill', 'meta', 'duplicate']
	 */
	public static function handle(array $data, $token = '', array $forced_skill = NULL, $source = 'yandex')
	{
		$config = self::config();
		$texts = (array) Arr::get($config, 'texts', []);

		$extracted = self::extract_request($data);

		if ( ! $extracted['ok'])
		{
			return [
				'response' => ['error' => $extracted['error']],
				'http'     => 400,
				'skill'    => NULL,
				'meta'     => ['error' => $extracted['error']],
				'duplicate' => FALSE,
			];
		}

		$session = $extracted['session'];
		$request = $extracted['request'];
		$question_raw = ($request['command'] !== '' ? $request['command'] : $request['utterance']);

		// --- выбор навыка ------------------------------------------------
		if ($forced_skill !== NULL)
		{
			$skill = $forced_skill;
		}
		else
		{
			$skill = self::find_skill($token, $session['skill_id']);
		}

		if ($skill === NULL)
		{
			self::_warn('вебхук без навыка (skill_id=:skill_id, token=:token)', [
				':skill_id' => $session['skill_id'],
				':token'    => $token !== '' ? 'задан' : 'пуст',
			]);

			return [
				'response' => self::build_response($session, (string) Arr::get($texts, 'unmatched', ''), '', [], FALSE),
				'http'     => 200,
				'skill'    => NULL,
				'meta'     => ['intent' => 'unmatched'],
				'duplicate' => FALSE,
			];
		}

		if ( ! self::truthy($skill['is_active']))
		{
			self::_warn('навык :code выключен, но получает запросы', [':code' => $skill['code']]);

			return [
				'response' => self::build_response($session, (string) Arr::get($texts, 'inactive', ''), '', [], TRUE),
				'http'     => 200,
				'skill'    => $skill,
				'meta'     => ['intent' => 'inactive'],
				'duplicate' => FALSE,
			];
		}

		// --- повтор запроса: отдаём сохранённый ответ ---------------------
		if ($source === 'yandex')
		{
			$duplicate = self::_find_duplicate($session['session_id'], $session['message_id']);

			if ($duplicate !== NULL)
			{
				$meta_json = json_decode((string) $duplicate['meta_json'], TRUE);
				$stored = is_array($meta_json) ? Arr::get($meta_json, 'response') : NULL;

				$response = is_array($stored)
					? $stored
					: self::build_response($session, (string) $duplicate['answer_text'], '', [], FALSE);

				return [
					'response' => $response,
					'http'     => 200,
					'skill'    => $skill,
					'meta'     => ['intent' => 'duplicate'],
					'duplicate' => TRUE,
				];
			}
		}

		// --- опасный контент (маркировка Яндекса) --------------------------
		if ($request['dangerous'])
		{
			$intent = 'dangerous';
			$end_session = FALSE;
			$answer = (string) Arr::get($texts, 'dangerous', '');
			$question = $question_raw;
			$meta = ['intent' => $intent, 'type' => $request['type'], 'dangerous' => TRUE];
		}
		else
		{
			// --- маршрутизация команды ------------------------------------
			$intent = self::route_intent($question_raw, $session['is_new'], $skill, $config);
			$question = self::question_text($extracted, $skill);
			$meta = ['intent' => $intent, 'type' => $request['type'], 'new' => $session['is_new']];

			if ($intent === 'llm' AND $question === '')
			{
				$intent = 'help';
				$meta['intent'] = $intent;
			}

			if ($intent === 'welcome')
			{
				$answer = self::_skill_text($skill, 'welcome_text', 'welcome', $texts);
				$end_session = FALSE;
				$meta['question'] = $question;
			}
			elseif ($intent === 'help')
			{
				$answer = self::_skill_text($skill, 'help_text', 'help', $texts);
				$end_session = FALSE;
				$meta['question'] = $question;
			}
			elseif ($intent === 'exit')
			{
				$answer = self::_skill_text($skill, 'farewell_text', 'farewell', $texts);
				$end_session = TRUE;
				$meta['question'] = $question;
			}
			else
			{
				// --- вопрос ИИ ------------------------------------------------
				$messages = self::llm_messages($skill, $session['user_key'], $question);
				$llm = self::ask_llm($skill, $messages);

				$meta['model'] = $llm['model'];
				$meta['ms'] = $llm['ms'];
				$meta['context'] = count($messages) - 1;
				$meta['error'] = $llm['error'];

				if ($llm['answer'] === NULL)
				{
					if ($llm['error'] !== '')
					{
						Log_Plain::event(Log::ERROR, 'alice: навык :code не получил ответ ИИ: :e',
							[':code' => $skill['code'], ':e' => $llm['error']]);
					}

					$intent = 'fallback';
					$meta['intent'] = $intent;
					$answer = self::_skill_text($skill, 'fallback_text', 'fallback', $texts);
				}
				else
				{
					$answer = self::trim_answer($llm['answer'], (int) Arr::get($skill, 'answer_max_chars', 1024));
				}

				$end_session = self::truthy(Arr::get($skill, 'end_session'));
			}
		}

		// --- сборка ответа -------------------------------------------------
		$tts = '';

		if ($intent === 'welcome')
		{
			$welcome_tts = trim((string) Arr::get($skill, 'welcome_tts', ''));
			$tts = $welcome_tts !== '' ? $welcome_tts : self::clean_for_tts($answer);
		}
		else
		{
			$tts = self::clean_for_tts($answer);
		}

		$buttons = in_array($intent, ['welcome', 'llm', 'fallback'], TRUE)
			? self::buttons_for($skill, $extracted)
			: [];

		$response = self::build_response($session, $answer, $tts, $buttons, $end_session);
		$meta['response'] = $response;

		// --- журнал (он же память диалога) ---------------------------------
		self::_save_dialog($skill, $session, $question, $answer, $intent, $source, $meta);

		// --- MQTT-публикация для домашних дисплеев --------------------------
		if ($source === 'yandex' AND trim((string) Arr::get($skill, 'mqtt_topic', '')) !== '')
		{
			$mqtt = Service_Nj657_Mqtt::publish((string) $skill['mqtt_topic'], $answer);

			if ( ! $mqtt['ok'] AND class_exists('Log_Plain'))
			{
				Log_Plain::event(Log::WARNING, 'alice: ответ навыка :code не ушёл в MQTT (:topic): :e',
					[':code' => $skill['code'], ':topic' => $skill['mqtt_topic'], ':e' => $mqtt['error']]);
			}
		}

		return [
			'response' => $response,
			'http'     => 200,
			'skill'    => $skill,
			'meta'     => $meta,
			'duplicate' => FALSE,
		];
	}

	/**
	 * Синтетический запрос Алисы для тестовой консоли: тот же пайплайн,
	 * но без Яндекса — видно, что именно услышит пользователь.
	 */
	public static function build_test_request(array $skill, $text, $is_new)
	{
		return [
			'meta' => [
				'locale' => 'ru-RU',
				'timezone' => 'Europe/Moscow',
				'interfaces' => ['screen' => []],
			],
			'request' => [
				'command' => (string) $text,
				'original_utterance' => (string) $text,
				'type' => 'SimpleUtterance',
				'markup' => ['dangerous_context' => FALSE],
				'payload' => [],
			],
			'session' => [
				'message_id' => 0,
				'session_id' => 'test-'.bin2hex(random_bytes(6)),
				'skill_id' => (string) Arr::get($skill, 'yandex_skill_id', ''),
				'user_id' => 'test-console',
				'new' => (bool) $is_new,
			],
			'version' => '1.0',
		];
	}

	// ------------------------------------------------------------------
	// Внутреннее
	// ------------------------------------------------------------------

	/** Текст навыка с откатом к значению по умолчанию из конфига. */
	protected static function _skill_text(array $skill, $field, $default_key, array $texts)
	{
		$value = trim((string) Arr::get($skill, $field, ''));

		return $value !== '' ? $value : (string) Arr::get($texts, $default_key, '');
	}

	protected static function _save_dialog(array $skill, array $session, $question, $answer, $intent, $source, array $meta)
	{
		$payload = [
			'skill_id' => (int) Arr::get($skill, 'id', 0),
			'user_key' => (string) Arr::get($session, 'user_key', ''),
			'session' => (string) Arr::get($session, 'session_id', ''),
			'message' => (int) Arr::get($session, 'message_id', 0),
			'question' => (string) $question,
			'answer' => (string) $answer,
			'intent' => (string) $intent,
			'source' => (string) $source,
			'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
		];

		$sql = 'INSERT INTO nj_657_dialogs
				(skill_id, user_key, yandex_session_id, message_id, question_text, answer_text, intent, source, meta)
			VALUES ('
			.(int) $payload['skill_id'].', '
			.self::_q($payload['user_key']).', '
			.self::_q($payload['session']).', '
			.(int) $payload['message'].', '
			.self::_q($payload['question']).', '
			.self::_q($payload['answer']).', '
			.self::_q($payload['intent']).', '
			.self::_q($payload['source']).', '
			.self::_q($payload['meta']).'::jsonb)';

		try
		{
			self::_exec(Database::INSERT, $sql);
		}
		catch (Throwable $e)
		{
			// Дубликат (session_id, message_id) — Яндекс повторил запрос
			// быстрее нашей проверки; это не ошибка.
			Log_Plain::event(Log::WARNING, 'alice: реплика навыка :code не записана в журнал: :e',
				[':code' => Arr::get($skill, 'code', ''), ':e' => mb_substr($e->getMessage(), 0, 200)]);
		}
	}

	protected static function _warn($message, array $values)
	{
		if (class_exists('Log_Plain'))
		{
			Log_Plain::event(Log::WARNING, 'alice: '.$message, $values);
		}
	}

	/** Случайный код навыка для адресов админки. */
	public static function generate_code()
	{
		do
		{
			$code = '';

			for ($i = 0; $i < self::CODE_LEN; $i++)
			{
				$code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
			}
		}
		while (self::skill_by_code($code) !== NULL);

		return $code;
	}

	/** Случайный токен вебхука (кнопка «сгенерировать» в форме). */
	public static function generate_token()
	{
		$token = '';

		for ($i = 0; $i < self::TOKEN_LEN; $i++)
		{
			$token .= self::TOKEN_ALPHABET[random_int(0, strlen(self::TOKEN_ALPHABET) - 1)];
		}

		return $token;
	}
}
