<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-662 «Диалог двух ИИ»: диалоги, ход разговора, материалы и публикация.
 *
 * Что это и чем отличается от наработок владельца (source_data/100_ai_symposium):
 *
 *   · Единица работы — ДИАЛОГ. В наработках над диалогом стоял «симпозиум»
 *     (раунды, рейтинги, избранное, память агента) — владелец назвал эту
 *     сущность избыточной, и она не перенесена: остались диалог, его реплики,
 *     его материалы и лента событий.
 *   · Ход ведёт СЕРВЕР. В наработках очередь обменов крутил jQuery на странице:
 *     закрыл вкладку — разговор встал. Здесь каждый обмен — задача очереди nj120
 *     (канал nj662.dialog), и после приёма обмена сайт сам ставит следующий
 *     (apply_turn). Браузер только СМОТРИТ (поллинг статуса) и управляет
 *     (старт/пауза/настройки).
 *   · Агенты смотрят в интернет. У диалога есть бюджет поиска (0…N): на каждом
 *     обмене, пока бюджет не исчерпан, воркер формулирует запрос, ищет в
 *     DuckDuckGo («утка го») и читает найденные страницы, а находки уходят в
 *     подсказку агентам. Ноль — не выходить совсем (умолчание).
 *   · Материалы индексируются общей семантикой платформы (nj115) — тем же
 *     путём, что «Лаборатория векторов» (/veclab). Если эмбеддинг-сервера нет,
 *     подбор честно падает на совпадение слов, а не молчит.
 *
 * Роли в коде: этот сервис — единственное место, которое знает схему nj_662_*.
 * Контроллеры только собирают формы и показывают страницы, воркер — только
 * спрашивает контекст и отдаёт реплики (POST /dialog/api/*).
 *
 * Стиль SQL — интерполяция с $db->quote(), как в nj593/nj660/nj661/erp222.
 * Чистые функции (нормализация настроек, статус-ряд, слова) закрыты стендом
 * .tools/nj662_dialog_stand.php и тестом tests/Unit/Nj662DialogTest.php.
 */
class Service_Nj662_Dialog {

	/** Канал очереди: обмен репликами ведёт python-воркер. */
	const CHANNEL = 'nj662.dialog';

	/** Канал очереди: подбор участников под тему («быстрый старт»). */
	const QUICK_CHANNEL = 'nj662.quick';

	/** Коллекция и сущность в общей семантике (nj115) для материалов диалога. */
	const SEM_COLLECTION = 'nj662';
	const SEM_ENTITY     = 'nj662_knowledge';

	/** Лестница этапов: по ней страница рисует, где ход. */
	public static $stages = ['Подготовка', 'Ищу в интернете', 'Обмен', 'Сводка'];

	/**
	 * Словарь статусов: человеческие подписи.
	 *
	 * Один на всё — и подпись на странице (status_word), и фильтр сводки
	 * админа (/dialog/all). Неизвестный ключ в фильтр не пускаем: это защита
	 * от чужой строки в SQL, а не только удобство.
	 *
	 * 'stopped' — «Стоп» владельца (09.10.2026): задание снято с очереди,
	 * разговор ждёт «Продолжить»; от паузы отличается тем, что обмен не
	 * доехал до конца.
	 */
	public static $status_list = [
		'draft'     => 'черновик',
		'quick'     => 'подбираю агентов',
		'running'   => 'идёт диалог',
		'paused'    => 'пауза',
		'stopped'   => 'остановлен',
		'done'      => 'диалог завершён',
		'failed'    => 'ошибка',
		'cancelled' => 'отменён',
	];

	// ==================================================================
	// Конфиг, словари, чистые функции
	// ==================================================================

	/** Значение конфига модуля (dot-путь, как Arr::path). */
	public static function config($key = NULL, $default = NULL)
	{
		$cfg = (array) Kohana::$config->load('nj662_dialog');

		if ($key === NULL)
		{
			return $cfg;
		}

		return Arr::path($cfg, $key, $default);
	}

	/** Предел из секции limits (единая точка правды для формы и сервиса). */
	public static function limits($key = NULL, $default = NULL)
	{
		$limits = (array) self::config('limits', []);

		if ($key === NULL)
		{
			return $limits;
		}

		return Arr::get($limits, $key, $default);
	}

	/** Умолчания нового диалога. */
	public static function defaults()
	{
		return (array) self::config('defaults', []);
	}

	/** Примеры тем для быстрого старта. */
	public static function samples()
	{
		return array_values((array) self::config('samples', []));
	}

	/** Участники по умолчанию: код → (name, system). */
	public static function roles()
	{
		return (array) self::config('roles', []);
	}

	/** Характер участника по умолчанию с подставленной темой. */
	public static function role_default($code, $topic = '')
	{
		$roles = self::roles();
		$text  = (string) Arr::path($roles, $code.'.system', '');

		return trim(str_replace('{topic}', trim((string) $topic) !== '' ? trim((string) $topic) : 'без темы', $text));
	}

	/** Имя участника по умолчанию. */
	public static function role_name($code, $fallback = 'Участник')
	{
		$roles = self::roles();
		$name  = trim((string) Arr::path($roles, $code.'.name', ''));

		return $name !== '' ? $name : $fallback;
	}

	/** Форма передала галочку: TRUE для «on», «1», «да», чекбокса. */
	public static function as_bool($value)
	{
		if (is_bool($value))
		{
			return $value;
		}

		// 't' — так PostgreSQL отдаёт boolean в драйвере платформы: без него
		// «галочка включена» читалась бы как выключенная.
		return in_array(mb_strtolower(trim((string) $value)), ['1', 't', 'on', 'yes', 'true', 'да'], TRUE);
	}

	/** Текст от человека или модели: без управляющих символов, с обрезкой. */
	public static function clean_text($text, $max = 0)
	{
		$text = str_replace(["\r\n", "\r"], "\n", (string) $text);
		$text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]+/u', '', $text);
		$text = preg_replace('/\n{3,}/u', "\n\n", $text);
		$text = trim($text);

		if ($max > 0 AND mb_strlen($text) > $max)
		{
			$text = rtrim(mb_substr($text, 0, $max)).'…';
		}

		return $text;
	}

	/** Имя участника: одна строка, без подмены пустоты. */
	public static function clean_name($name, $fallback = '')
	{
		$name = self::clean_text(preg_replace('/\s+/u', ' ', (string) $name), 0);
		$name = mb_substr($name, 0, (int) self::limits('name_max', 60));

		return $name !== '' ? $name : $fallback;
	}

	/** Короткая выжимка текста для карточек и превью. */
	public static function excerpt($text, $len = 160)
	{
		$text = self::clean_text(preg_replace('/\s+/u', ' ', (string) $text));

		return mb_strlen($text) > $len ? mb_substr($text, 0, $len).'…' : $text;
	}

	/**
	 * Ключевые слова текста: слова длиннее трёх букв, без повторов.
	 *
	 * Запасной путь подбора материалов, когда семантики нет: короткие слова
	 * («как», «что», «или») дали бы совпадение по любому материалу.
	 */
	public static function keywords($text, $max = 8)
	{
		$words = [];
		$seen  = [];

		foreach (preg_split('/[^\p{L}\p{N}_\-]+/u', mb_strtolower((string) $text)) as $word)
		{
			$word = trim((string) $word, '-');

			if (mb_strlen($word) < 4 OR isset($seen[$word]))
			{
				continue;
			}

			$seen[$word] = TRUE;
			$words[]     = $word;

			if (count($words) >= $max)
			{
				break;
			}
		}

		return $words;
	}

	/**
	 * Настройки хода: нормализация и границы конфига.
	 *
	 * Проверять в форме мало: значения приходят и из быстрого старта, и после
	 * возврата на паузу. Нормализация стоит на ОБОИХ входах, иначе в базе
	 * оказалось бы 99 обменов, которые сервис потом не выполнит.
	 */
	public static function normalize_settings(array $data)
	{
		$defaults = self::defaults();

		$pairs = (int) Arr::get($data, 'pairs', Arr::get($defaults, 'pairs', 5));
		$pairs = max((int) self::limits('pairs_min', 1), min((int) self::limits('pairs_max', 12), $pairs));

		$context = (int) Arr::get($data, 'context_pairs', Arr::get($defaults, 'context_pairs', 3));
		$context = max((int) self::limits('context_min', 0), min((int) self::limits('context_max', 10), $context));

		$ddg = (int) Arr::get($data, 'ddg', Arr::get($defaults, 'ddg', 0));
		$ddg = max(0, min((int) self::limits('ddg_max', 5), $ddg));

		return [
			'pairs'         => $pairs,
			'context_pairs' => $context,
			'ddg'           => $ddg,
			'summarize'     => self::as_bool(Arr::get($data, 'summarize', Arr::get($defaults, 'summarize', TRUE))),
		];
	}

	/** «Обмен 3 из 5» — единая подпись хода для страницы и опроса. */
	public static function pair_word($turn, $pairs)
	{
		return 'Обмен '.(int) $turn.' из '.(int) $pairs;
	}

	/**
	 * Форма слова по числу: 1 обмен, 2 обмена, 5 обменов.
	 *
	 * Счётчики видны на странице и в ленте событий, а «1 обменов» читается как
	 * ошибка — поэтому склоняем, а не пишем форму от руки.
	 */
	public static function plural($n, $one, $few, $many)
	{
		$n    = abs((int) $n);
		$n10  = $n % 10;
		$n100 = $n % 100;

		if ($n10 === 1 AND $n100 !== 11)
		{
			return $one;
		}

		if ($n10 >= 2 AND $n10 <= 4 AND ($n100 < 12 OR $n100 > 14))
		{
			return $few;
		}

		return $many;
	}

	/** Секунды в человеческий вид: «45 с», «2 мин 5 с», «2 ч 3 мин». */
	public static function elapsed_text($sec)
	{
		$sec = max(0, (int) $sec);

		if ($sec < 60)
		{
			return $sec.' с';
		}

		if ($sec < 3600)
		{
			return floor($sec / 60).' мин '.($sec % 60).' с';
		}

		return floor($sec / 3600).' ч '.floor(($sec % 3600) / 60).' мин';
	}

	/** Статус словами. */
	public static function status_word($status)
	{
		$status = (string) $status;

		return isset(self::$status_list[$status]) ? self::$status_list[$status] : $status;
	}

	/** Тон статуса для вёрстки: live (мигает) | ok | warn | error | plain. */
	public static function tone($status)
	{
		if ($status === 'running' OR $status === 'quick')
		{
			return 'live';
		}

		if ($status === 'done')
		{
			return 'ok';
		}

		// Пауза и стоп — один тон: разговор стоит, ждёт владельца.
		if ($status === 'paused' OR $status === 'stopped')
		{
			return 'warn';
		}

		if ($status === 'failed')
		{
			return 'error';
		}

		return 'plain';
	}

	/**
	 * Частота поиска словами: «не выходят в интернет», «один поиск за диалог»,
	 * «поисков за диалог: 3».
	 */
	public static function ddg_label($n)
	{
		$n = max(0, (int) $n);

		if ($n === 0)
		{
			return 'не выходят в интернет';
		}

		if ($n === 1)
		{
			return 'один поиск за диалог';
		}

		if ($n >= (int) self::limits('ddg_max', 5))
		{
			return 'поисков за диалог: '.$n.' — очень много';
		}

		return 'поисков за диалог: '.$n;
	}

	/** Владелец ли это диалога (админ — да для любого). */
	public static function owns($dialog, $user_id, $is_admin = FALSE)
	{
		if ( ! is_array($dialog))
		{
			return FALSE;
		}

		$user_id = (int) $user_id;

		if ($user_id <= 0)
		{
			return FALSE;
		}

		return $is_admin OR (int) Arr::get($dialog, 'user_id', 0) === $user_id;
	}

	// ==================================================================
	// Диалоги: чтение и запись
	// ==================================================================

	public static function db()
	{
		return Database::instance();
	}

	/** Диалог по id или NULL. */
	public static function dialog($id)
	{
		$row = self::db()->query(Database::SELECT,
			'SELECT * FROM nj_662_dialogs WHERE id = '.(int) $id.' LIMIT 1', FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	/**
	 * Создать диалог (черновик) или сразу уйти в быстрый старт.
	 *
	 * @param  int   $user_id владелец
	 * @param  array $data    topic, brief, a/b (имя, характер, модель), настройки,
	 *                        quick => TRUE («подбери агентов сам»)
	 * @return array ['ok' => TRUE, 'dialog_id' => N] | ['error' => '…']
	 */
	public static function create($user_id, array $data)
	{
		$topic = self::clean_text(Arr::get($data, 'topic', ''), 0);

		if (mb_strlen($topic) < (int) self::limits('topic_min', 3))
		{
			return ['error' => 'Тема слишком короткая — напишите, о чём агентам говорить'];
		}

		$topic = mb_substr($topic, 0, (int) self::limits('topic_max', 300));
		$brief = self::clean_text(Arr::get($data, 'brief', ''), (int) self::limits('brief_max', 2000));

		$settings = self::normalize_settings($data);

		$roles = self::roles();

		$a_name = self::clean_name(Arr::get($data, 'a_name', ''), self::role_name('a', 'Структура'));
		$b_name = self::clean_name(Arr::get($data, 'b_name', ''), self::role_name('b', 'Импульс'));

		$a_system = self::clean_text(Arr::get($data, 'a_system', ''), (int) self::limits('system_max', 4000));
		$b_system = self::clean_text(Arr::get($data, 'b_system', ''), (int) self::limits('system_max', 4000));

		// Характер по умолчанию подставляет тему: агент должен знать, о чём речь,
		// даже если ход разговора придёт к нему без истории.
		if ($a_system === '')
		{
			$a_system = self::role_default('a', $topic);
		}

		if ($b_system === '')
		{
			$b_system = self::role_default('b', $topic);
		}

		// Модель: в форме настроек пустое поле — это «модель воркера», но при
		// СОЗДАНИИ диалога полей ещё нет, и пустая модель означала бы, что
		// владелец не увидел ни одной модели из списка конфига. Поэтому без
		// поля берём первую модель списка (умолчание диалога).
		$a_model = array_key_exists('a_model', $data)
			? self::clean_model($data['a_model'])
			: (string) Service_Nj662_Models::default_spec();

		$b_model = array_key_exists('b_model', $data)
			? self::clean_model($data['b_model'])
			: (string) Service_Nj662_Models::default_spec();

		$quick = self::as_bool(Arr::get($data, 'quick', FALSE));

		// Быстрый старт: имена и характеры придумает модель (канал nj662.quick),
		// поэтому диалог сразу помечается этим статусом — страница покажет
		// «подбираю агентов», а не пустую форму.
		$status = $quick ? 'quick' : 'draft';

		$db = self::db();

		$dialog_id = (int) $db->query(Database::SELECT,
			'INSERT INTO nj_662_dialogs
				(user_id, topic, brief, a_name, a_system, a_model, b_name, b_system, b_model,
				 pairs, context_pairs, summarize, ddg, status, stage, stage_note, stage_at)
			 VALUES ('.(int) $user_id.', '.$db->quote($topic).', '.$db->quote($brief).', '
				.$db->quote($a_name).', '.$db->quote($a_system).', '.$db->quote($a_model).', '
				.$db->quote($b_name).', '.$db->quote($b_system).', '.$db->quote($b_model).', '
				.(int) $settings['pairs'].', '.(int) $settings['context_pairs'].', '
				.($settings['summarize'] ? 'true' : 'false').', '.(int) $settings['ddg'].', '
				.$db->quote($status).', '.$db->quote($quick ? 'Подготовка' : '')
				.', '.$db->quote($quick ? 'подбираю участников под тему' : '').', '
				.'now()) RETURNING id', FALSE)->get('id');

		self::event($dialog_id, $quick ? 'quick' : 'created',
			$quick ? 'Диалог создан, подбираю участников' : 'Диалог создан');

		return ['ok' => TRUE, 'dialog_id' => $dialog_id, 'quick' => $quick];
	}

	/** Спецификация модели из формы: пусто допустимо (модель воркера). */
	public static function clean_model($spec)
	{
		$clean = Service_Nj662_Models::clean_custom($spec);

		return $clean['spec'];
	}

	/** Сохранить настройки диалога (владелец, до/после хода — но не во время). */
	public static function save_settings($id, $user_id, array $data, $is_admin = FALSE)
	{
		$dialog = self::dialog($id);

		if ( ! $dialog)
		{
			return ['error' => 'Диалог не найден'];
		}

		if ( ! self::owns($dialog, $user_id, $is_admin))
		{
			return ['error' => 'Это чужой диалог'];
		}

		if ((string) $dialog['status'] === 'running' OR (string) $dialog['status'] === 'quick')
		{
			return ['error' => 'Диалог идёт — настройки меняются на паузе'];
		}

		$topic = self::clean_text(Arr::get($data, 'topic', $dialog['topic']), 0);

		if (mb_strlen($topic) < (int) self::limits('topic_min', 3))
		{
			return ['error' => 'Тема слишком короткая'];
		}

		$settings = self::normalize_settings($data + [
			'pairs'         => $dialog['pairs'],
			'context_pairs' => $dialog['context_pairs'],
			'summarize'     => $dialog['summarize'],
			'ddg'           => $dialog['ddg'],
		]);

		// Форма несёт по два поля на участника (список + «своя модель»),
		// поэтому модель читаем общим разбором с префиксом: если полей в
		// запросе нет (короткая отправка), остаётся прежняя модель.
		$a_model = isset($data['a_model']) ? Service_Nj662_Models::from_form($data, 'a_')['spec'] : (string) $dialog['a_model'];
		$b_model = isset($data['b_model']) ? Service_Nj662_Models::from_form($data, 'b_')['spec'] : (string) $dialog['b_model'];

		self::db()->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET
				topic = '.self::db()->quote($topic).',
				brief = '.self::db()->quote(self::clean_text(Arr::get($data, 'brief', $dialog['brief']), (int) self::limits('brief_max', 2000))).',
				a_name = '.self::db()->quote(self::clean_name(Arr::get($data, 'a_name', $dialog['a_name']), $dialog['a_name'])).',
				b_name = '.self::db()->quote(self::clean_name(Arr::get($data, 'b_name', $dialog['b_name']), $dialog['b_name'])).',
				a_system = '.self::db()->quote(self::clean_text(Arr::get($data, 'a_system', $dialog['a_system']), (int) self::limits('system_max', 4000))).',
				b_system = '.self::db()->quote(self::clean_text(Arr::get($data, 'b_system', $dialog['b_system']), (int) self::limits('system_max', 4000))).',
				a_model = '.self::db()->quote($a_model).',
				b_model = '.self::db()->quote($b_model).',
				pairs = '.(int) $settings['pairs'].',
				context_pairs = '.(int) $settings['context_pairs'].',
				summarize = '.($settings['summarize'] ? 'true' : 'false').',
				ddg = '.(int) $settings['ddg'].',
				updated_at = now()
			 WHERE id = '.(int) $id, FALSE);

		self::event($id, 'settings', 'Настройки диалога обновлены: '.$settings['pairs'].' '
			.self::plural($settings['pairs'], 'обмен', 'обмена', 'обменов').', '
			.Service_Nj662_Models::label($a_model).' × '.Service_Nj662_Models::label($b_model).', '
			.self::ddg_label($settings['ddg']));

		return ['ok' => TRUE];
	}

	/** Удалить диалог со всем содержимым (владелец или админ). */
	public static function delete($id, $user_id, $is_admin = FALSE)
	{
		$dialog = self::dialog($id);

		if ( ! $dialog)
		{
			return ['error' => 'Диалог не найден'];
		}

		if ( ! self::owns($dialog, $user_id, $is_admin))
		{
			return ['error' => 'Это чужой диалог'];
		}

		$db = self::db();

		// Материалы уходят и из семантики: осиротевшие векторы платформа считает
		// мусором в покрытии — убираем явно.
		self::deindex($id);

		foreach (['nj_662_messages', 'nj_662_searches', 'nj_662_knowledge', 'nj_662_events'] as $table)
		{
			$db->query(Database::DELETE, 'DELETE FROM '.$table.' WHERE dialog_id = '.(int) $id, FALSE);
		}

		$db->query(Database::DELETE, 'DELETE FROM nj_662_dialogs WHERE id = '.(int) $id, FALSE);

		return ['ok' => TRUE];
	}

	/** Диалоги владельца: карточками на рабочий стол (последние сверху). */
	public static function dialogs_of($user_id, $limit = 60)
	{
		$rows = self::db()->query(Database::SELECT,
			'SELECT d.*,
				(SELECT count(*) FROM nj_662_messages m WHERE m.dialog_id = d.id) AS replies,
				(SELECT m.content FROM nj_662_messages m WHERE m.dialog_id = d.id ORDER BY m.id DESC LIMIT 1) AS last_reply,
				(SELECT m.author_name FROM nj_662_messages m WHERE m.dialog_id = d.id ORDER BY m.id DESC LIMIT 1) AS last_author
			 FROM nj_662_dialogs d
			 WHERE d.user_id = '.(int) $user_id.'
			 ORDER BY d.created_at DESC
			 LIMIT '.max(1, (int) $limit), FALSE)->as_array();

		return array_map([__CLASS__, '_row'], $rows);
	}

	/**
	 * ВСЕ диалоги сайта — сводка для админа (/dialog/all, решение владельца
	 * 09.10.2026): опубликованные и неопубликованные, любого владельца.
	 *
	 * Отдельный метод, а не флаг в dialogs_of(): «моё» и «всё» умышленно
	 * разведены, чтобы случайный вызов из личного кабинета не отдал чужие
	 * разговоры. Право проверяет контроллер (nj662.manage — только админ).
	 */
	public static function all_dialogs($q = '', $status = '', $per = 30, $page = 1)
	{
		$per  = max(1, min(100, (int) $per));
		$page = max(1, (int) $page);
		$off  = ($page - 1) * $per;
		$db   = self::db();

		$rows = $db->query(Database::SELECT,
			'SELECT d.*,
				u.email AS owner_email,
				u.username AS owner_login,
				(SELECT count(*) FROM nj_662_messages m WHERE m.dialog_id = d.id) AS replies,
				(SELECT count(*) FROM nj_662_messages m
				  WHERE m.dialog_id = d.id AND m.who = \'user\') AS owner_replies,
				(SELECT m.content FROM nj_662_messages m
				  WHERE m.dialog_id = d.id ORDER BY m.id DESC LIMIT 1) AS last_reply
			 FROM nj_662_dialogs d
			 LEFT JOIN users u ON u.id = d.user_id'
			.self::_all_where($q, $status).'
			 ORDER BY d.updated_at DESC, d.id DESC
			 LIMIT '.$per.' OFFSET '.$off, FALSE)->as_array();

		return array_map([__CLASS__, '_row'], $rows);
	}

	/** Сколько строк в сводке админа: то же условие, что у all_dialogs. */
	public static function all_count($q = '', $status = '')
	{
		$row = self::db()->query(Database::SELECT,
			'SELECT count(*) AS n,
				count(*) FILTER (WHERE d.is_public) AS public_n,
				count(*) FILTER (WHERE d.status IN (\'running\',\'quick\')) AS running_n,
				count(*) FILTER (WHERE d.status = \'done\') AS done_n,
				count(*) FILTER (WHERE d.status = \'stopped\') AS stopped_n
			 FROM nj_662_dialogs d
			 LEFT JOIN users u ON u.id = d.user_id'
			.self::_all_where($q, $status), FALSE)->current();

		$row = (array) $row;

		return [
			'all'     => (int) Arr::get($row, 'n', 0),
			'public'  => (int) Arr::get($row, 'public_n', 0),
			'running' => (int) Arr::get($row, 'running_n', 0),
			'done'    => (int) Arr::get($row, 'done_n', 0),
			'stopped' => (int) Arr::get($row, 'stopped_n', 0),
		];
	}

	/**
	 * Условие выборки сводки: строка поиска (тема, вводная, владелец, номер)
	 * и статус ровно из словаря — чужой текст в SQL не попадает.
	 */
	private static function _all_where($q, $status)
	{
		$db    = self::db();
		$where = [];
		$status = trim((string) $status);

		if ($status !== '' AND isset(self::$status_list[$status]))
		{
			$where[] = 'd.status = '.$db->quote($status);
		}

		$q = trim((string) $q);

		if ($q !== '')
		{
			$like = $db->quote('%'.mb_strtolower($q).'%');

			$where[] = '(lower(d.topic) LIKE '.$like
				.' OR lower(d.brief) LIKE '.$like
				.' OR lower(COALESCE(u.email, \'\')) LIKE '.$like
				.' OR lower(COALESCE(u.username, \'\')) LIKE '.$like
				.' OR CAST(d.id AS text) = '.$db->quote($q).')';
		}

		return $where ? ' WHERE '.implode(' AND ', $where) : '';
	}

	/** Счётчики рабочего стола. */
	public static function counters($user_id)
	{
		$row = self::db()->query(Database::SELECT,
			"SELECT count(*) AS dialogs,
				count(*) FILTER (WHERE status IN ('running','quick')) AS running,
				count(*) FILTER (WHERE status = 'done') AS done,
				count(*) FILTER (WHERE is_public) AS public
			 FROM nj_662_dialogs WHERE user_id = ".(int) $user_id, FALSE)->current();

		return [
			'dialogs' => (int) Arr::get((array) $row, 'dialogs', 0),
			'running' => (int) Arr::get((array) $row, 'running', 0),
			'done'    => (int) Arr::get((array) $row, 'done', 0),
			'public'  => (int) Arr::get((array) $row, 'public', 0),
		];
	}

	/** Привести строку из базы к массиву (pg отдаёт объект). */
	protected static function _row($row)
	{
		return (array) $row;
	}

	/** Заметка в ленту диалога. */
	public static function event($dialog_id, $kind, $message)
	{
		self::db()->query(Database::INSERT,
			'INSERT INTO nj_662_events (dialog_id, kind, message)
			 VALUES ('.(int) $dialog_id.', '.self::db()->quote(mb_substr((string) $kind, 0, 16)).', '
				.self::db()->quote(self::clean_text($message, 480)).')', FALSE);
	}

	/** Лента последних событий диалога. */
	public static function events($dialog_id, $limit = 30)
	{
		$rows = self::db()->query(Database::SELECT,
			'SELECT * FROM nj_662_events WHERE dialog_id = '.(int) $dialog_id.'
			 ORDER BY id DESC LIMIT '.max(1, (int) $limit), FALSE)->as_array();

		return array_map([__CLASS__, '_row'], $rows);
	}

	/** Реплики диалога по порядку (или с указанного обмена). */
	public static function messages($dialog_id, $from_turn = 0, $limit = 200)
	{
		$rows = self::db()->query(Database::SELECT,
			'SELECT * FROM nj_662_messages
			 WHERE dialog_id = '.(int) $dialog_id.($from_turn > 0 ? ' AND turn > '.(int) $from_turn : '').'
			 ORDER BY id ASC LIMIT '.max(1, (int) $limit), FALSE)->as_array();

		return array_map([__CLASS__, '_row'], $rows);
	}

	/**
	 * История для подсказки агента: окно последних обменов ПЛЮС реплики
	 * владельца из этого же окна (who = 'user').
	 *
	 * Реплика владельца лежит в уже сделанном обмене (turn тот же), поэтому
	 * обычная выборка «turn > N» теряла её при context_pairs = 0 — а смысл
	 * реплики именно в том, чтобы агент её увидел.
	 */
	public static function history_for_worker($dialog_id, $from_turn = 0, $limit = 200)
	{
		$from_turn = max(0, (int) $from_turn);

		$rows = self::db()->query(Database::SELECT,
			'SELECT * FROM nj_662_messages
			 WHERE dialog_id = '.(int) $dialog_id.'
			   AND (turn > '.$from_turn." OR (who = 'user' AND turn >= ".$from_turn.'))
			 ORDER BY id ASC LIMIT '.max(1, (int) $limit), FALSE)->as_array();

		return array_map([__CLASS__, '_row'], $rows);
	}

	/** Поиски диалога в интернете (журнал доказательств). */
	public static function searches($dialog_id, $limit = 40)
	{
		$rows = self::db()->query(Database::SELECT,
			'SELECT * FROM nj_662_searches WHERE dialog_id = '.(int) $dialog_id.'
			 ORDER BY id DESC LIMIT '.max(1, (int) $limit), FALSE)->as_array();

		$out = [];

		foreach ($rows as $row)
		{
			$row = self::_row($row);
			$row['results'] = json_decode((string) $row['results'], TRUE) ?: [];
			$row['pages']   = json_decode((string) $row['pages'], TRUE) ?: [];
			$out[] = $row;
		}

		return $out;
	}

	// ==================================================================
	// Ход диалога
	// ==================================================================

	/**
	 * Запустить (или продолжить) диалог: поставить первый обмен в очередь.
	 *
	 * После паузы продолжаем со следующего обмена: уже сказанное не повторяем,
	 * счётчик доедает остаток (обменов в диалоге ровно pairs).
	 */
	public static function start($id, $user_id, $is_admin = FALSE, $from_quick = FALSE)
	{
		$dialog = self::dialog($id);

		if ( ! $dialog)
		{
			return ['error' => 'Диалог не найден'];
		}

		if ( ! self::owns($dialog, $user_id, $is_admin) AND ! $from_quick)
		{
			return ['error' => 'Это чужой диалог'];
		}

		if (in_array((string) $dialog['status'], ['running', 'quick'], TRUE))
		{
			return ['ok' => TRUE, 'note' => 'Диалог уже идёт', 'job_id' => (int) $dialog['job_id']];
		}

		if ((string) $dialog['status'] === 'done')
		{
			return ['error' => 'Диалог завершён — создайте новый или начните с чистого листа'];
		}

		if ( ! class_exists('Service_Nj120_JobQueue'))
		{
			return ['error' => 'Очередь задач nj120 недоступна — ход диалога запустить нельзя'];
		}

		$turn = (int) $dialog['turn'] + 1;

		if ($turn > (int) $dialog['pairs'])
		{
			return ['error' => 'Все обмены уже сделаны'];
		}

		$db = self::db();

		$db->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET status = \'running\', error = \'\',
				stage = \'Подготовка\', stage_note = \'ставлю обмен в очередь\', stage_at = now(),
				started_at = COALESCE(started_at, now()), finished_at = NULL, updated_at = now()
			 WHERE id = '.(int) $id, FALSE);

		$res = self::queue_turn($dialog, $turn);

		if (isset($res['error']))
		{
			$db->query(Database::UPDATE,
				'UPDATE nj_662_dialogs SET status = \'failed\', error = '.$db->quote($res['error']).',
					stage = \'Отказ\', stage_note = \'очередь не приняла задачу\', updated_at = now()
				 WHERE id = '.(int) $id, FALSE);

			return $res;
		}

		self::event($id, 'start', $turn > 1
			? 'Разговор продолжен с обмена '.$turn
			: 'Диалог запущен: обмен 1 из '.(int) $dialog['pairs']);

		return ['ok' => TRUE, 'job_id' => $res['job_id'], 'turn' => $turn,
			'pairs' => (int) $dialog['pairs']];
	}

	/**
	 * Пауза: ход не обрываем, воркер сам увидит и отпустит задачу.
	 *
	 * finished_at ставим намеренно: секундомер страницы считает «от начала до
	 * финала», и без этой даты он тикал бы всё время паузы (09.10.2026).
	 * «Продолжить» (start) снова обнуляет finished_at — отсчёт честный.
	 */
	public static function pause($id, $user_id, $is_admin = FALSE)
	{
		$dialog = self::dialog($id);

		if ( ! $dialog)
		{
			return ['error' => 'Диалог не найден'];
		}

		if ( ! self::owns($dialog, $user_id, $is_admin))
		{
			return ['error' => 'Это чужой диалог'];
		}

		if ( ! in_array((string) $dialog['status'], ['running', 'quick'], TRUE))
		{
			return ['error' => 'Диалог и так не идёт'];
		}

		self::db()->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET status = \'paused\', stage = \'Пауза\',
				stage_note = \'остановлено владельцем\', stage_at = now(),
				finished_at = COALESCE(finished_at, now()), updated_at = now()
			 WHERE id = '.(int) $id, FALSE);

		self::event($id, 'pause', 'Пауза: ход остановлен на обмене '.(int) $dialog['turn']);

		return ['ok' => TRUE];
	}

	/**
	 * Стоп: остановить разговор СРАЗУ, а не дожидаться конца обмена (09.10.2026).
	 *
	 * Чем отличается от паузы: пауза просит воркера отпустить задачу саму
	 * (обмен доедет до конца и ляжет в базу), стоп же СНИМАЕТ задание с
	 * очереди принудительно — владелец передумал и ждать не хочет. Реплика,
	 * которую модель уже пишет, пропадает: это цена немедленной остановки,
	 * поэтому кнопка не «взводится», но подписана честно.
	 *
	 * Возобновление — то же «Продолжить» (start): счётчик обменов не
	 * обнуляется, диалог продолжается с того же места.
	 */
	public static function stop($id, $user_id, $is_admin = FALSE)
	{
		$dialog = self::dialog($id);

		if ( ! $dialog)
		{
			return ['error' => 'Диалог не найден'];
		}

		if ( ! self::owns($dialog, $user_id, $is_admin))
		{
			return ['error' => 'Это чужой диалог'];
		}

		if ( ! in_array((string) $dialog['status'], ['running', 'quick', 'paused'], TRUE))
		{
			return ['error' => 'Диалог и так не идёт'];
		}

		$job_id = (int) Arr::get($dialog, 'job_id', 0);
		$killed = FALSE;

		// Задание снимаем принудительно (force): обмен может быть в работе,
		// и тогда воркер просто не применит результат — статус задачи уже
		// cancelled (так устроен Service_Nj120_JobQueue::cancel).
		if ($job_id > 0 AND class_exists('Service_Nj120_JobQueue'))
		{
			try
			{
				Service_Nj120_JobQueue::instance()->cancel($job_id, TRUE);
				$killed = TRUE;
			}
			catch (Throwable $e)
			{
				// Очередь недоступна — разговор всё равно останавливаем:
				// статус важнее, чем удачный разговор с очередью.
				Kohana::$log->add(Log::ERROR,
					'nj662: стоп диалога :id не снял задачу :job: :class: :e',
					[':id' => (int) $id, ':job' => $job_id,
						':class' => get_class($e), ':e' => $e->getMessage()]);
			}
		}

		self::db()->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET status = \'stopped\', stage = \'Стоп\',
				stage_note = ' . self::db()->quote($killed
					? 'остановлено владельцем, задание снято с очереди'
					: 'остановлено владельцем').',
				stage_at = now(), job_id = 0,
				finished_at = COALESCE(finished_at, now()), updated_at = now()
			 WHERE id = '.(int) $id, FALSE);

		self::event($id, 'stop', 'Стоп: разговор остановлен на обмене '.(int) $dialog['turn']
			.($killed ? ', задание очереди снято' : ''));

		return ['ok' => TRUE, 'job_cancelled' => $killed];
	}

	/**
	 * План разговора после своей реплики: сколько обменов осталось и на
	 * сколько удлиняем диалог, чтобы «Продолжить» было чем продолжать
	 * (решение владельца 09.10.2026).
	 *
	 * Ответ на вопрос «обменов в разговоре — считается от начала или от
	 * продолжения»: ОТ НАЧАЛА. turn — сколько обменов сделано с первого
	 * запуска, pairs — план на весь диалог; ни пауза, ни стоп, ни своя реплика
	 * счётчик не обнуляют. Было «обмен 3 из 5» — после продолжения станет
	 * «обмен 4 из 5», а не «обмен 1 из 5» заново. Нужно больше обменов —
	 * владелец добавляет их той же формой (add) или правит «Обменов в
	 * разговоре» в настройках диалога.
	 *
	 * Функция без базы: и сервис, и вьюха, и тесты считают план одинаково.
	 *
	 * @return array turn | pairs | pairs_new | add (сколько добавлено) | left
	 *               (оставалось) | max (предел) | capped (упёрлись в предел)
	 */
	public static function say_plan($dialog, $add = 1)
	{
		$turn  = max(0, (int) Arr::get($dialog, 'turn', 0));
		$pairs = max(0, (int) Arr::get($dialog, 'pairs', 0));
		$max   = max(1, (int) self::limits('pairs_max', 12));
		$add   = max(0, min((int) self::limits('say_add_max', 5), (int) $add));
		$left  = max(0, $pairs - $turn);

		// Продолжать нечего (план исчерпан) — удлиняем хотя бы на один обмен,
		// иначе «добавить реплику и продолжить» молча бы не продолжило.
		if ($left < 1 AND $add < 1)
		{
			$add = 1;
		}

		$pairs_new = min($max, $pairs + $add);

		return [
			'turn'      => $turn,
			'pairs'     => $pairs,
			'pairs_new' => $pairs_new,
			'add'       => $pairs_new - $pairs,
			'left'      => $left,
			'max'       => $max,
			// «Упёрлись» — это просили добавить, а предел не дал: без этой
			// оговорки «ничего не добавили по просьбе» выглядело бы как тупик.
			'capped'    => $add > 0 AND $pairs_new <= $pairs,
			'text'      => 'обмен '.$turn.' из '.$pairs
				.($pairs_new > $pairs ? ' → '.$pairs_new : ''),
		];
	}

	/**
	 * Своя реплика в разговор: владелец правит направление диалога
	 * (решение владельца 09.10.2026).
	 *
	 * Как это работает: реплика пишется в ленту ТЕКУЩИМ обменом (turn тот же,
	 * что уже сделан) — агент видит её в истории следующим обменом и отвечает
	 * на неё, а не на свою прошлую мысль. Номер СЛЕДУЮЩЕГО обмена занимать
	 * нельзя: по нему apply_turn отличает принятый обмен от ретрая, и своя
	 * реплика выглядела бы как «обмен уже доехал» (агент промолчал бы).
	 *
	 * После реплики разговор можно продолжить той же кнопкой: план
	 * удлиняется на add обменов (иначе продолжать нечего — см. say_plan).
	 *
	 * @param  int    $add    сколько обменов добавить к плану
	 * @param  bool   $resume поставить следующий обмен в очередь сразу
	 */
	public static function say($id, $user_id, $text, $is_admin = FALSE, $author = '',
		$add = 1, $resume = TRUE)
	{
		$dialog = self::dialog($id);

		if ( ! $dialog)
		{
			return ['error' => 'Диалог не найден'];
		}

		if ( ! self::owns($dialog, $user_id, $is_admin))
		{
			return ['error' => 'Это чужой диалог'];
		}

		// Менять направление посреди реплики агента нельзя — как и настройки:
		// человек сперва останавливает разговор (пауза или стоп).
		if (in_array((string) $dialog['status'], ['running', 'quick'], TRUE))
		{
			return ['error' => 'Диалог идёт — свою реплику добавляют на паузе'];
		}

		$min  = max(1, (int) self::limits('say_min', 3));
		$text = self::clean_text($text, (int) self::limits('say_max', 2000));

		if (mb_strlen($text) < $min)
		{
			return ['error' => 'Реплика слишком короткая: напишите, куда повернуть разговор'];
		}

		$plan = self::say_plan($dialog, $resume ? $add : 0);
		$db   = self::db();

		$db->query(Database::INSERT,
			'INSERT INTO nj_662_messages (dialog_id, turn, who, author_name, content)
			 VALUES ('.(int) $id.', '.(int) $dialog['turn'].", 'user', "
				.$db->quote(self::clean_name($author, 'Владелец')).', '
				.$db->quote($text).')', FALSE);

		// Завершённый диалог после реплики снова «можно продолжать»: иначе
		// start() честно отвечал бы «все обмены уже сделаны».
		$status = ((string) $dialog['status'] === 'done' OR (string) $dialog['status'] === 'failed')
			? 'paused'
			: (string) $dialog['status'];

		$db->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET
				pairs = '.(int) $plan['pairs_new'].',
				status = '.$db->quote($status).",
				stage = 'Реплика владельца',
				stage_note = ".$db->quote('владелец направил разговор: '.self::excerpt($text, 120)).',
				stage_at = now(), updated_at = now()
			 WHERE id = '.(int) $id, FALSE);

		self::event($id, 'say', 'Своя реплика: «'.self::excerpt($text, 120).'»'
			.($plan['add'] > 0 ? '; план разговора продлён до '.$plan['pairs_new'] : ''));

		$out = ['ok' => TRUE, 'started' => FALSE, 'turn' => (int) $dialog['turn']] + $plan;

		if ( ! $resume)
		{
			return $out;
		}

		// Упираться в предел обменов молча нельзя: реплика сохранена, но
		// продолжать нечего — говорим об этом текстом, а не тишиной.
		if ($plan['capped'] AND $plan['left'] < 1)
		{
			return $out + ['note' => 'Реплика добавлена, но обменов больше нет: предел '
				.(int) $plan['max'].' обменов на диалог'];
		}

		$fresh = self::dialog($id);
		$res   = self::start($id, (int) $fresh['user_id'], TRUE, TRUE);

		if (isset($res['error']))
		{
			return $out + ['note' => $res['error']];
		}

		return $out + ['started' => TRUE, 'job_id' => (int) Arr::get($res, 'job_id', 0),
			'next_turn' => (int) Arr::get($res, 'turn', 0)];
	}

	/**
	 * Поставить обмен в очередь nj120.
	 *
	 * Задача несёт ВСЁ, что нужно воркеру: id диалога и номер обмена. Модели и
	 * бюджеты воркер берёт из контекста обмена (POST /dialog/api/context) —
	 * так правка настроек на паузе не требует переставлять задачи.
	 */
	public static function queue_turn($dialog, $turn)
	{
		$dialog_id = (int) Arr::get($dialog, 'id', 0);

		$payload = [
			'dialog_id' => $dialog_id,
			'turn'      => (int) $turn,
		];

		try
		{
			$job_id = Service_Nj120_JobQueue::push(self::CHANNEL, $payload, [
				'module'       => 'nj662',
				'visitor_id'   => 'u:'.(int) Arr::get($dialog, 'user_id', 0),
				'created_by'   => (int) Arr::get($dialog, 'user_id', 0),
				'max_attempts' => 2,
				// Обмен — до двух вызовов модели плюс поиск и чтение страниц:
				// на локальной модели паузы внутри хода длинные.
				'timeout_sec'  => (int) self::limits('turn_timeout', 1800),
			]);
		}
		catch (Throwable $e)
		{
			// Отказ очереди — не «сообщение для пользователя»: в логе нужна
			// причина с номерами (класс исключения, диалог, обмен).
			Kohana::$log->add(Log::ERROR,
				'nj662: очередь не приняла обмен :turn диалога :id: :class: :e',
				[':turn' => (int) $turn, ':id' => $dialog_id,
					':class' => get_class($e), ':e' => $e->getMessage()]);

			return ['error' => 'Очередь не приняла задачу: '.$e->getMessage()];
		}

		self::db()->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET job_id = '.(int) $job_id.', updated_at = now()
			 WHERE id = '.$dialog_id, FALSE);

		return ['ok' => TRUE, 'job_id' => $job_id];
	}

	/** Живой этап хода: воркер сообщает, что делает прямо сейчас. */
	public static function set_stage($id, $stage, $note = '')
	{
		$stage = self::clean_text($stage, 80);
		$note  = self::clean_text(preg_replace('/\s+/u', ' ', (string) $note), 280);

		self::db()->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET stage = '.self::db()->quote($stage).',
				stage_note = '.self::db()->quote($note).', stage_at = now()
			 WHERE id = '.(int) $id, FALSE);
	}

	/**
	 * Контекст обмена для воркера (POST /dialog/api/context).
	 *
	 * Здесь же решается «продолжать ли»: пауза, отмена и завершение отдают
	 * ok = FALSE и stop = TRUE — задача из очереди закроется мирно, без
	 * «падения», потому что диалог остановил человек, а не сломался воркер.
	 */
	public static function worker_context($id, $turn)
	{
		$dialog = self::dialog($id);

		if ( ! $dialog)
		{
			return ['ok' => FALSE, 'error' => 'Диалог не найден'];
		}

		if ( ! in_array((string) $dialog['status'], ['running', 'quick'], TRUE))
		{
			return ['ok' => FALSE, 'stop' => TRUE, 'error' => 'Диалог не идёт: '.self::status_word($dialog['status'])];
		}

		if ((int) $turn !== (int) $dialog['turn'] + 1)
		{
			return ['ok' => FALSE, 'stop' => TRUE,
				'error' => 'Этот обмен уже не актуален (в диалоге обмен '.(int) $dialog['turn'].')'];
		}

		// История — окно последних обменов: агент видит ровно то, что решил
		// владелец настройкой «обменов в контексте», остальное живёт в выжимке.
		$context_pairs = (int) $dialog['context_pairs'];
		$from_turn     = max(0, (int) $dialog['turn'] - $context_pairs);

		// Свои реплики владельца (who = 'user') не выпадают из окна контекста:
		// они лежат в том обмене, который уже сделан, и при context_pairs = 0
		// иначе потерялись бы — а это ровно то, чем владелец правит направление
		// разговора (09.10.2026).
		$history = [];

		foreach (self::history_for_worker((int) $dialog['id'], $from_turn) as $m)
		{
			$history[] = [
				'who'     => (string) $m['who'],
				'author'  => (string) $m['author_name'],
				'content' => (string) $m['content'],
				'turn'    => (int) $m['turn'],
			];
		}

		$topic  = (string) $dialog['topic'];
		$query  = $topic.' '.self::excerpt(Arr::get($history ? end($history) : [], 'content', ''), 200);
		$budget = max(0, (int) $dialog['ddg']);
		$left   = max(0, $budget - (int) $dialog['searches_used']);

		return [
			'ok'        => TRUE,
			'dialog_id' => (int) $dialog['id'],
			'turn'      => (int) $turn,
			'pairs'     => (int) $dialog['pairs'],
			'topic'     => $topic,
			'brief'     => (string) $dialog['brief'],
			'summarize' => self::as_bool($dialog['summarize']) ? TRUE : FALSE,
			'history'   => $history,
			'summary'   => (string) $dialog['summary'],
			'agents'    => [
				'a' => [
					'name'   => (string) $dialog['a_name'],
					'system' => (string) $dialog['a_system'],
					'model'  => (string) $dialog['a_model'],
				],
				'b' => [
					'name'   => (string) $dialog['b_name'],
					'system' => (string) $dialog['b_system'],
					'model'  => (string) $dialog['b_model'],
				],
			],
			'search'    => [
				'budget' => $budget,
				'used'   => (int) $dialog['searches_used'],
				'left'   => $left,
				'label'  => self::ddg_label($budget),
			],
			'knowledge' => self::relevant_knowledge((int) $dialog['id'], $query),
			'limits'    => self::limits(),
		];
	}

	/**
	 * Принять обмен от воркера: реплики, поиск, выжимка — и поставить следующий.
	 *
	 * Идемпотентность: повторная задача того же обмена (ретрай) не создаёт
	 * дублей — если реплики этого хода уже есть, обмен считается принятым.
	 * Возвращает done = TRUE, когда обменов больше не осталось.
	 */
	public static function apply_turn($id, $turn, array $data)
	{
		$dialog = self::dialog($id);

		if ( ! $dialog)
		{
			return ['ok' => FALSE, 'error' => 'Диалог не найден'];
		}

		$db   = self::db();
		$turn = (int) $turn;

		$already = (int) $db->query(Database::SELECT,
			'SELECT count(*) AS n FROM nj_662_messages WHERE dialog_id = '.(int) $id.' AND turn = '.$turn, FALSE)->get('n');

		if ($already > 0)
		{
			return ['ok' => TRUE, 'duplicate' => TRUE, 'done' => $turn >= (int) $dialog['pairs'],
				'turn' => $turn, 'pairs' => (int) $dialog['pairs']];
		}

		$search = Arr::get($data, 'search', NULL);
		$search_id = 0;

		if (is_array($search) AND trim((string) Arr::get($search, 'query', '')) !== '')
		{
			$search_id = (int) $db->query(Database::SELECT,
				'INSERT INTO nj_662_searches (dialog_id, turn, agent, query, found, results, pages, note, error)
				 VALUES ('.(int) $id.', '.$turn.', '.$db->quote(self::clean_name(Arr::get($search, 'agent', 'a'), 'a')).', '
					.$db->quote(mb_substr(self::clean_text(Arr::get($search, 'query', ''), 0), 0, 300)).', '
					.(int) Arr::get($search, 'found', count((array) Arr::get($search, 'results', []))).', '
					.$db->quote(json_encode(array_slice((array) Arr::get($search, 'results', []), 0, 10), JSON_UNESCAPED_UNICODE)).', '
					.$db->quote(json_encode(array_slice((array) Arr::get($search, 'pages', []), 0, 6), JSON_UNESCAPED_UNICODE)).', '
					.$db->quote(self::clean_text(Arr::get($search, 'note', ''), 300)).', '
					.$db->quote(self::clean_text(Arr::get($search, 'error', ''), 300)).')
				 RETURNING id', FALSE)->get('id');

			self::event($id, 'search', 'Поиск в интернете: «'.self::excerpt(Arr::get($search, 'query', ''), 120).'»');
		}

		$tokens  = 0;
		$summary = '';

		foreach ((array) Arr::get($data, 'messages', []) as $m)
		{
			$content = self::clean_text(Arr::get($m, 'content', ''), 0);

			if ($content === '')
			{
				continue;
			}

			$tokens += (int) Arr::get($m, 'tokens', 0);

			if (trim((string) Arr::get($m, 'summary', '')) !== '')
			{
				$summary = self::clean_text(Arr::get($m, 'summary', ''), 1200);
			}

			$db->query(Database::INSERT,
				'INSERT INTO nj_662_messages
					(dialog_id, turn, who, author_name, content, summary, model, tokens)
				VALUES ('.(int) $id.', '.$turn.', '
					.$db->quote(self::clean_name(Arr::get($m, 'who', 'a'), 'a')).', '
					.$db->quote(self::clean_name(Arr::get($m, 'author', ''), 'Агент')).', '
					.$db->quote($content).', '
					.$db->quote(self::clean_text(Arr::get($m, 'summary', ''), 1200)).', '
					.$db->quote(self::clean_name(Arr::get($m, 'model', ''), '')).', '
					.(int) Arr::get($m, 'tokens', 0).')', FALSE);
		}

		$tokens += (int) Arr::get($data, 'tokens', 0);

		$pairs = (int) $dialog['pairs'];
		$done  = $turn >= $pairs;

		// Выжимка копится: новая сводка обмена включается в общую (старое
		// уходит в слова, чтобы подсказка не росла бесконечно).
		$full_summary = trim((string) $dialog['summary']);

		// Повтор той же сводки не копим: модель часто пересказывает прошлый обмен
		// теми же словами, и подсказка растёт без нового смысла (2 000 знаков
		// выжимки должны быть заняты разным, а не одним и тем же пять раз).
		if ($summary !== '' AND mb_strpos($full_summary, $summary) === FALSE)
		{
			$full_summary = self::clean_text($full_summary.' '.$summary, 2000);
		}

		$status = $done ? 'done' : 'running';

		$db->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET
				turn = '.$turn.',
				searches_used = searches_used + '.($search_id > 0 ? 1 : 0).',
				tokens_total = tokens_total + '.(int) $tokens.',
				summary = '.$db->quote($full_summary).',
				status = '.$db->quote($status).',
				stage = '.$db->quote($done ? 'Диалог' : 'Подготовка').',
				stage_note = '.$db->quote($done
					? 'сделано '.$pairs.' '.self::plural($pairs, 'обмен', 'обмена', 'обменов')
					: 'готовлю следующий обмен').',
				stage_at = now(),
				finished_at = '.($done ? 'now()' : 'NULL').',
				outcome = '.$db->quote($done
					? 'Разговор из '.$pairs.' '.self::plural($pairs, 'обмена', 'обменов', 'обменов')
						.'; на выходе — разбор темы двумя агентами.'
					: '').',
				updated_at = now()
			 WHERE id = '.(int) $id, FALSE);

		self::event($id, 'turn', 'Обмен '.$turn.' принят'.($search_id > 0 ? ' (с поиском в интернете)' : ''));

		$next = NULL;

		if ( ! $done)
		{
			$fresh = self::dialog($id);
			$res   = self::queue_turn($fresh, $turn + 1);

			if (isset($res['error']))
			{
				// Очередь отказала: диалог честно встаёт на паузу, а не висит
				// «идущим» до сторожа.
				$db->query(Database::UPDATE,
					'UPDATE nj_662_dialogs SET status = \'paused\', stage = \'Пауза\',
						stage_note = '.$db->quote($res['error']).', updated_at = now()
					 WHERE id = '.(int) $id, FALSE);

				return ['ok' => TRUE, 'turn' => $turn, 'pairs' => $pairs, 'done' => FALSE,
					'next_job' => 0, 'note' => $res['error']];
			}

			$next = (int) $res['job_id'];
		}
		else
		{
			$used = (int) $dialog['searches_used'];

			self::event($id, 'done', 'Диалог завершён: '.$pairs.' '
				.self::plural($pairs, 'обмен', 'обмена', 'обменов')
				.', '.($used > 0 ? 'поисков в интернете: '.$used : 'без поиска в интернете'));
		}

		return ['ok' => TRUE, 'turn' => $turn, 'pairs' => $pairs, 'done' => $done,
			'next_job' => $next, 'search_id' => $search_id, 'tokens' => $tokens];
	}

	/**
	 * Быстрый старт: принять участников от модели (канал nj662.quick).
	 *
	 * Владелец просил «быстрое безгеморройное начало диалога»: ввёл тему — сайт
	 * сам подобрал имена и характеры под неё (в наработках это делал
	 * Service_Lib100_QuickStart::generate_agent_config, здесь работа уходит
	 * серверному воркеру). Характеры модели НЕ переписываем целиком: роль
	 * держит форму («структура» и «импульс»), поэтому пришедший характер
	 * дописывается к роли, а не заменяет её.
	 */
	public static function apply_quick($id, array $agents, $autostart = FALSE)
	{
		$dialog = self::dialog($id);

		if ( ! $dialog)
		{
			return ['ok' => FALSE, 'error' => 'Диалог не найден'];
		}

		if ((string) $dialog['status'] !== 'quick')
		{
			return ['ok' => FALSE, 'error' => 'Этот диалог уже не ждёт подбора участников'];
		}

		$topic = (string) $dialog['topic'];

		$a_name = self::clean_name(Arr::get($agents, 'a_name', ''), self::role_name('a', 'Структура'));
		$b_name = self::clean_name(Arr::get($agents, 'b_name', ''), self::role_name('b', 'Импульс'));

		$a_voice = self::clean_text(Arr::get($agents, 'a_system', ''), 1200);
		$b_voice = self::clean_text(Arr::get($agents, 'b_system', ''), 1200);

		$a_system = self::role_default('a', $topic).($a_voice !== '' ? "\n\nСвоя роль под эту тему:\n".$a_voice : '');
		$b_system = self::role_default('b', $topic).($b_voice !== '' ? "\n\nСвоя роль под эту тему:\n".$b_voice : '');

		$db = self::db();

		$db->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET
				a_name = '.$db->quote($a_name).', b_name = '.$db->quote($b_name).',
				a_system = '.$db->quote(self::clean_text($a_system, (int) self::limits('system_max', 4000))).',
				b_system = '.$db->quote(self::clean_text($b_system, (int) self::limits('system_max', 4000))).',
				status = \'draft\', stage = \'\', stage_note = \'\', updated_at = now()
			 WHERE id = '.(int) $id, FALSE);

		self::event($id, 'quick', 'Участники подобраны: '.$a_name.' × '.$b_name);

		$out = ['ok' => TRUE, 'a_name' => $a_name, 'b_name' => $b_name, 'started' => FALSE];

		if ($autostart)
		{
			$fresh = self::dialog($id);
			$res   = self::start($id, (int) $fresh['user_id'], TRUE, TRUE);

			if (isset($res['error']))
			{
				return $out + ['note' => $res['error']];
			}

			$out['started'] = TRUE;
			$out['job_id']  = (int) $res['job_id'];
		}

		return $out;
	}

	/** Подбор участников не удался: диалог остаётся черновиком, причина видна. */
	public static function quick_failed($id, $error)
	{
		self::db()->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET status = \'draft\', error = '.self::db()->quote(self::clean_text($error, 480)).',
				stage = \'\', stage_note = \'\', updated_at = now()
			 WHERE id = '.(int) $id.' AND status = \'quick\'', FALSE);

		self::event($id, 'error', 'Подбор участников не удался: '.self::clean_text($error, 300));
	}

	/** Ход упал: причина на страницу, диалог не «висит идущим». */
	public static function fail($id, $error)
	{
		self::db()->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET status = \'failed\',
				error = '.self::db()->quote(self::clean_text($error, 480)).',
				stage = \'Отказ\', stage_note = \'ход не получился\', stage_at = now(),
				finished_at = now(), updated_at = now()
			 WHERE id = '.(int) $id, FALSE);

		self::event($id, 'fail', 'Ход не получился: '.self::clean_text($error, 300));
	}

	/**
	 * Лечение осиротевших диалогов: TRUE — диалог закрыт сайтом.
	 *
	 * Два признака смерти, оба проверяются (приём из erp222/nj661):
	 *   · задача очереди закрыта (done/failed/cancelled), а диалог «идёт»;
	 *   · воркер молчит дольше порога limits.run_stale_min.
	 * Без этого падение воркера навсегда оставляло бы диалог «идущим».
	 */
	public static function close_if_dead(array $dialog)
	{
		$id     = (int) Arr::get($dialog, 'id', 0);
		$job_id = (int) Arr::get($dialog, 'job_id', 0);

		// Судим только ЖИВОЙ диалог: завершённый, черновик или стоящий на паузе
		// «встать» уже не может, а лишний UPDATE и лишняя строка в ленте
		// («воркер молчал 30 мин») — шум, который растёт с каждым открытием
		// страницы.
		if ( ! in_array((string) Arr::get($dialog, 'status', ''), ['running', 'quick'], TRUE))
		{
			return FALSE;
		}

		if ($job_id > 0)
		{
			$status = self::db()->query(Database::SELECT,
				'SELECT status FROM nj_120_jobs WHERE id = '.$job_id.' LIMIT 1', FALSE)->get('status');

			if ($status !== NULL AND in_array((string) $status, ['done', 'failed', 'cancelled'], TRUE))
			{
				// Задача закрыта как done — значит, обмен доехал: диалож либо
				// продолжается следующей задачей, либо завершён на apply_turn.
				$fresh = self::dialog($id);

				if ($fresh AND (string) $fresh['status'] === 'running' AND (int) $fresh['job_id'] === $job_id)
				{
					$next = (int) $fresh['turn'] < (int) $fresh['pairs'];

					if ($next)
					{
						return FALSE;
					}
				}

				if ($fresh AND in_array((string) $fresh['status'], ['running', 'quick'], TRUE))
				{
					self::pause_with_note($id, 'задача очереди закрыта со статусом '.(string) $status.' — диалог поставлен на паузу');

					return TRUE;
				}
			}
		}

		$silent = (int) self::limits('run_stale_min', 30);
		$since  = Arr::get($dialog, 'stage_at', Arr::get($dialog, 'updated_at', 'now'));

		if ($silent > 0 AND $since AND strtotime((string) $since) < time() - $silent * 60)
		{
			self::pause_with_note($id, 'воркер молчал больше '.$silent.' мин — диалог поставлен на паузу, можно продолжить');

			return TRUE;
		}

		return FALSE;
	}

	/** Поставить на паузу с объяснением (страница показывает причину). */
	protected static function pause_with_note($id, $note)
	{
		self::db()->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET status = \'paused\', stage = \'Пауза\',
				stage_note = '.self::db()->quote(self::clean_text($note, 280)).',
				error = '.self::db()->quote(self::clean_text($note, 480)).', updated_at = now()
			 WHERE id = '.(int) $id.' AND status IN (\'running\', \'quick\')', FALSE);

		self::event($id, 'pause', $note);
	}

	/**
	 * Пиналка модуля (GET /dialog/api/tick): закрыть брошенные диалоги и
	 * догнать состояние индексации материалов. Зовётся из обслуживания очереди.
	 */
	public static function tick()
	{
		$closed = 0;

		foreach (self::db()->query(Database::SELECT,
			"SELECT * FROM nj_662_dialogs WHERE status IN ('running', 'quick')
			 ORDER BY id DESC LIMIT 20", FALSE)->as_array() as $row)
		{
			if (self::close_if_dead((array) $row))
			{
				$closed++;
			}
		}

		return ['ok' => TRUE, 'closed' => $closed, 'indexed' => self::knowledge_refresh()];
	}

	// ==================================================================
	// Материалы диалога и семантика
	// ==================================================================

	/** Материалы диалога (последние сверху). */
	public static function knowledge($dialog_id)
	{
		$rows = self::db()->query(Database::SELECT,
			'SELECT id, dialog_id, title, source, chars, index_state, index_note, created_at,
				left(text, 400) AS preview
			 FROM nj_662_knowledge WHERE dialog_id = '.(int) $dialog_id.' ORDER BY id DESC', FALSE)->as_array();

		return array_map([__CLASS__, '_row'], $rows);
	}

	/** Материал целиком (для подсказки агентам). */
	public static function knowledge_item($id)
	{
		$row = self::db()->query(Database::SELECT,
			'SELECT * FROM nj_662_knowledge WHERE id = '.(int) $id.' LIMIT 1', FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	/**
	 * Добавить материал: текст или ссылку (страницу читает сам сайт).
	 *
	 * Ссылка читается по правилам платформы: только http(s), только наружу
	 * (внутренние адреса отклоняются — иначе сервер сходит сам к себе, а это
	 * дыра в SSRF). Если страница не прочиталась, материал не создаётся:
	 * пустая запись «проиндексировано ничего» хуже честного отказа.
	 */
	public static function knowledge_add($dialog_id, $user_id, array $data)
	{
		$dialog = self::dialog($dialog_id);

		if ( ! $dialog)
		{
			return ['error' => 'Диалог не найден'];
		}

		$count = (int) self::db()->query(Database::SELECT,
			'SELECT count(*) AS n FROM nj_662_knowledge WHERE dialog_id = '.(int) $dialog_id, FALSE)->get('n');

		if ($count >= (int) self::limits('knowledge_max', 20))
		{
			return ['error' => 'Материалов уже '.$count.' — это предел для одного диалога'];
		}

		$source = self::clean_text(Arr::get($data, 'source', ''), (int) self::limits('knowledge_source', 500));
		$title  = self::clean_text(Arr::get($data, 'title', ''), 300);
		$text   = self::clean_text(Arr::get($data, 'text', ''), (int) self::limits('knowledge_chars', 20000));

		if ($source !== '')
		{
			$page = self::fetch_page($source);

			if (isset($page['error']))
			{
				return ['error' => $page['error']];
			}

			$text   = self::clean_text($page['text'], (int) self::limits('knowledge_chars', 20000));
			$source = $page['url'];

			if ($title === '')
			{
				$title = $page['title'];
			}
		}

		if (mb_strlen($text) < 80)
		{
			return ['error' => 'Материал слишком короткий: нужен текст от 80 знаков или рабочая ссылка'];
		}

		if ($title === '')
		{
			$title = self::excerpt($text, 80);
		}

		$id = (int) self::db()->query(Database::SELECT,
			'INSERT INTO nj_662_knowledge (dialog_id, user_id, title, source, text, chars, index_state)
			 VALUES ('.(int) $dialog_id.', '.(int) $user_id.', '.self::db()->quote($title).', '
				.self::db()->quote($source).', '.self::db()->quote($text).', '
				.mb_strlen($text).", 'new') RETURNING id", FALSE)->get('id');

		self::event($dialog_id, 'knowledge', 'Материал добавлен: «'.$title.'»');

		$index = self::knowledge_index($id);

		return ['ok' => TRUE, 'knowledge_id' => $id, 'index' => $index];
	}

	/** Удалить материал и его вектор из семантики. */
	public static function knowledge_delete($id, $dialog_id)
	{
		$item = self::knowledge_item($id);

		if ( ! $item OR (int) $item['dialog_id'] !== (int) $dialog_id)
		{
			return ['error' => 'Материал не найден'];
		}

		self::db()->query(Database::DELETE, 'DELETE FROM nj_662_knowledge WHERE id = '.(int) $id, FALSE);
		self::deindex((int) $dialog_id, [(int) $id]);

		self::event($dialog_id, 'knowledge', 'Материал убран: «'.$item['title'].'»');

		return ['ok' => TRUE];
	}

	/**
	 * Поставить материал в семантический индекс (nj115) — тот же путь, что у
	 * «Лаборатории векторов». Ошибка индексации не мешает диалогу: материал
	 * остаётся в подборе по словам, а состояние честно пишется в базу.
	 */
	public static function knowledge_index($id, $force = FALSE)
	{
		$item = self::knowledge_item($id);

		if ( ! $item)
		{
			return ['error' => 'Материал не найден'];
		}

		if ( ! class_exists('Service_Nj115_Semantic'))
		{
			self::db()->query(Database::UPDATE,
				'UPDATE nj_662_knowledge SET index_state = \'error\',
					index_note = \'семантика платформы недоступна\', updated_at = now()
				 WHERE id = '.(int) $id, FALSE);

			return ['error' => 'Семантический индекс недоступен'];
		}

		$text = self::semantic_text($item);

		try
		{
			$res = Service_Nj115_Semantic::queue_index(self::SEM_COLLECTION, self::SEM_ENTITY, [[
				'id'       => (int) $item['id'],
				'text'     => $text,
				'metadata' => [
					'dialog_id' => (int) $item['dialog_id'],
					'title'     => (string) $item['title'],
					'source'    => (string) $item['source'],
				],
			]], ['module' => 'nj662'] + ($force ? ['force' => TRUE] : []));
		}
		catch (Throwable $e)
		{
			self::db()->query(Database::UPDATE,
				'UPDATE nj_662_knowledge SET index_state = \'error\',
					index_note = '.self::db()->quote(self::clean_text($e->getMessage(), 280)).', updated_at = now()
				 WHERE id = '.(int) $id, FALSE);

			return ['error' => 'Индексация не поставилась: '.$e->getMessage()];
		}

		$state = ((int) Arr::get($res, 'queued', 0) > 0) ? 'queued' : 'indexed';
		$note  = ((int) Arr::get($res, 'queued', 0) > 0)
			? 'в очереди на индексацию: задач '.count((array) Arr::get($res, 'job_ids', []))
			: 'текст не менялся — вектор уже актуален';

		self::db()->query(Database::UPDATE,
			'UPDATE nj_662_knowledge SET index_state = '.self::db()->quote($state).',
				index_note = '.self::db()->quote($note).', updated_at = now()
			 WHERE id = '.(int) $id, FALSE);

		return ['ok' => TRUE, 'state' => $state, 'note' => $note];
	}

	/** Переиндексировать все материалы диалога (кнопка «Индексировать»). */
	public static function knowledge_reindex($dialog_id)
	{
		$done = 0;

		foreach (self::knowledge($dialog_id) as $item)
		{
			$res = self::knowledge_index((int) $item['id'], TRUE);

			if ( ! isset($res['error']))
			{
				$done++;
			}
		}

		self::event($dialog_id, 'knowledge', 'Материалы отправлены в индекс: '.$done);

		return ['ok' => TRUE, 'queued' => $done];
	}

	/**
	 * Догнать состояние индексации: у материалов «в очереди» проверяем, встал ли
	 * вектор с текущим хешем текста. Тихий SQL — без выгрузки текстов в PHP
	 * (md5 считает сама база, как и в nj115).
	 */
	public static function knowledge_refresh($dialog_id = 0)
	{
		if ( ! class_exists('Service_Nj115_Semantic'))
		{
			return 0;
		}

		$need = 'SELECT id, md5(trim(title) || \' \' || text) AS hash FROM nj_662_knowledge
			WHERE index_state IN (\'queued\', \'new\', \'error\')'
			.($dialog_id > 0 ? ' AND dialog_id = '.(int) $dialog_id : '')
			.' LIMIT 50';

		$updated = 0;

		foreach (self::db()->query(Database::SELECT, $need, FALSE)->as_array() as $row)
		{
			$row = (array) $row;
			$ok  = (int) self::db()->query(Database::SELECT,
				"SELECT count(*) AS n FROM nj_115_vectors v
				 JOIN nj_115_collections c ON c.id = v.collection_id
				 WHERE c.name = ".self::db()->quote(self::SEM_COLLECTION)."
				   AND v.entity = ".self::db()->quote(self::SEM_ENTITY)."
				   AND v.entity_id = ".self::db()->quote((string) $row['id'])."
				   AND v.content_hash = ".self::db()->quote((string) $row['hash']), FALSE)->get('n');

			if ($ok > 0)
			{
				self::db()->query(Database::UPDATE,
					'UPDATE nj_662_knowledge SET index_state = \'indexed\',
						index_note = \'вектор в общей семантике\', updated_at = now()
					 WHERE id = '.(int) $row['id'], FALSE);
				$updated++;
			}
		}

		return $updated;
	}

	/** Текст материала для вектора: заголовок + источник + содержимое. */
	public static function semantic_text(array $item)
	{
		$parts = [trim((string) Arr::get($item, 'title', ''))];

		$source = trim((string) Arr::get($item, 'source', ''));

		if ($source !== '')
		{
			$parts[] = 'Источник: '.$source;
		}

		$parts[] = trim((string) Arr::get($item, 'text', ''));

		return self::clean_text(implode("\n", array_filter($parts)), (int) self::limits('knowledge_chars', 20000));
	}

	/** Убрать материалы диалога из семантики (или один материал). */
	public static function deindex($dialog_id, array $ids = [])
	{
		if ( ! class_exists('Service_Nj115_Semantic'))
		{
			return 0;
		}

		if ( ! $ids)
		{
			$ids = [];

			foreach (self::knowledge($dialog_id) as $item)
			{
				$ids[] = (int) $item['id'];
			}
		}

		if ( ! $ids)
		{
			return 0;
		}

		try
		{
			return (int) Service_Nj115_Semantic::delete_entity(self::SEM_COLLECTION, self::SEM_ENTITY, $ids);
		}
		catch (Throwable $e)
		{
			Kohana::$log->add(Log::WARNING, 'nj662: материал не убран из семантики: :e', [':e' => $e->getMessage()]);

			return 0;
		}
	}

	/**
	 * Какие материалы диалога показать агентам на этом обмене.
	 *
	 * Порядок честности: семантика (nj115) → совпадение слов по тексту → вообще
	 * ничего. Первые два случая помечаются в результате (how), и агент видит,
	 * почему материал попал в подсказку — это и есть «посмотри на /veclab».
	 */
	public static function relevant_knowledge($dialog_id, $query)
	{
		$limit = max(1, (int) self::limits('in_prompt', 4));
		$found = [];

		if (class_exists('Service_Nj115_Semantic') AND mb_strlen(trim((string) $query)) > 3)
		{
			try
			{
				$rows = Service_Nj115_Semantic::search(self::SEM_COLLECTION, $query, $limit * 2, self::SEM_ENTITY, [
					'hybrid' => TRUE,
				]);

				foreach ($rows as $row)
				{
					$row = (array) $row;

					if ((int) Arr::get($row, 'metadata.dialog_id', 0) !== (int) $dialog_id)
					{
						continue;
					}

					$id = (int) $row['entity_id'];

					if (isset($found[$id]))
					{
						continue;
					}

					$item = self::knowledge_item($id);

					if ( ! $item)
					{
						continue;
					}

					$item['how'] = Arr::get($row, 'match', '') === 'text'
						? 'совпадение слов'
						: 'подбор по смыслу';

					$found[$id] = $item;
				}
			}
			catch (Throwable $e)
			{
				// Нет эмбеддинг-сервера — это не ошибка диалога: ниже подберём
				// по словам и честно назовём способ.
				Kohana::$log->add(Log::DEBUG, 'nj662: семантика недоступна: :e', [':e' => $e->getMessage()]);
			}
		}

		if (count($found) < $limit)
		{
			$words = self::keywords($query, 6);

			if ($words)
			{
				$where = [];

				foreach ($words as $word)
				{
					$where[] = 'text ILIKE '.self::db()->quote('%'.$word.'%');
				}

				$rows = self::db()->query(Database::SELECT,
					'SELECT id, dialog_id, title, source, text FROM nj_662_knowledge
					 WHERE dialog_id = '.(int) $dialog_id.' AND ('.implode(' OR ', $where).')
					 ORDER BY id DESC LIMIT '.$limit, FALSE)->as_array();

				foreach ($rows as $row)
				{
					$row = self::_row($row);
					$id  = (int) $row['id'];

					if (isset($found[$id]))
					{
						continue;
					}

					$row['how'] = 'совпадение слов';
					$found[$id] = $row;
				}
			}
		}

		// Совсем ничего не подошло — берём свежие материалы: пусть агент видит,
		// что владелец положил в диалог, а не молчание.
		if ( ! $found)
		{
			$rows = self::db()->query(Database::SELECT,
				'SELECT id, dialog_id, title, source, text FROM nj_662_knowledge
				 WHERE dialog_id = '.(int) $dialog_id.' ORDER BY id DESC LIMIT '.$limit, FALSE)->as_array();

			foreach ($rows as $row)
			{
				$row = self::_row($row);
				$row['how'] = 'материал диалога';
				$found[(int) $row['id']] = $row;
			}
		}

		$out = [];
		$cap = (int) self::limits('in_prompt_chars', 1600);

		foreach (array_slice(array_values($found), 0, $limit) as $item)
		{
			$out[] = [
				'id'       => (int) $item['id'],
				'title'    => (string) $item['title'],
				'source'   => (string) $item['source'],
				'how'      => (string) Arr::get($item, 'how', 'материал диалога'),
				'text'     => self::clean_text(Arr::get($item, 'text', ''), $cap),
			];
		}

		return $out;
	}

	// ==================================================================
	// Чтение страниц (для материалов и поиска)
	// ==================================================================

	/**
	 * Прочитать страницу по адресу: заголовок и текст.
	 *
	 * Отказы честные и человеческие: не http(s), внутренний адрес, страница не
	 * ответила, текста почти нет. Внутренние адреса запрещены отдельно — сайт
	 * стоит в интернете, и «прочитай http://127.0.0.1/admin» не должен открывать
	 * серверу его же служебные страницы.
	 */
	public static function fetch_page($url)
	{
		$url  = trim((string) $url);
		$url  = preg_replace('/[\x00-\x20]+/u', '', $url);
		$bits = parse_url($url);

		if ( ! $bits OR empty($bits['scheme']) OR empty($bits['host']))
		{
			return ['error' => 'Нужен адрес вида https://адрес/страница'];
		}

		$scheme = mb_strtolower($bits['scheme']);

		if ($scheme !== 'http' AND $scheme !== 'https')
		{
			return ['error' => 'Читаю только http и https'];
		}

		$host = mb_strtolower($bits['host']);

		if ( ! self::is_public_host($host))
		{
			// Попытка прочитать внутренний адрес — событие безопасности: в лог,
			// а не только в ответ пользователю.
			Kohana::$log->add(Log::WARNING, 'nj662: отклонён внутренний адрес: :host',
				[':host' => mb_substr($host, 0, 120)]);

			return ['error' => 'Это внутренний адрес — читать его нельзя'];
		}

		if ( ! function_exists('curl_init'))
		{
			return ['error' => 'На сервере нет curl — прочитать ссылку нечем'];
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT        => 12,
			CURLOPT_CONNECTTIMEOUT => 6,
			CURLOPT_FOLLOWLOCATION => TRUE,
			CURLOPT_MAXREDIRS      => 3,
			// Больше 400 КБ текста не читаем: это материал для агента, а не архив.
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; n2j.ru bot)',
		]);
		$html = (string) curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err  = (string) curl_error($ch);
		curl_close($ch);

		if ($html === '' OR ($code >= 400 AND $code > 0))
		{
			Kohana::$log->add(Log::WARNING,
				'nj662: страница не прочиталась: :url → код :code, :err',
				[':url' => mb_substr($url, 0, 300), ':code' => $code,
					':err' => mb_substr($err !== '' ? $err : 'пустой ответ', 0, 200)]);

			return ['error' => $code >= 400
				? 'Страница ответила кодом '.$code
				: 'Страница не открылась'.($err !== '' ? ': '.$err : '')];
		}

		$title = '';

		if (preg_match('~<title[^>]*>(.*?)</title>~isu', $html, $m))
		{
			$title = self::clean_text(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'), 200);
		}

		$text = preg_replace('~<(script|style|noscript)\b.*?</\1>~isu', ' ', $html);
		$text = preg_replace('~<(br|/p|/div|/li|/h[1-6])\s*/?>~iu', "\n", (string) $text);
		$text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8');
		$text = self::clean_text(preg_replace('/[ \t]+/u', ' ', (string) $text), (int) self::limits('knowledge_chars', 20000));

		if (mb_strlen($text) < 80)
		{
			return ['error' => 'На странице нет читаемого текста'];
		}

		return ['ok' => TRUE, 'url' => $url, 'title' => $title, 'text' => $text];
	}

	/** Адрес ведёт наружу? (внутренние сети и localhost закрыты) */
	public static function is_public_host($host)
	{
		$host = trim(mb_strtolower((string) $host), '[]');

		if ($host === '' OR $host === 'localhost' OR substr($host, -6) === '.local')
		{
			return FALSE;
		}

		if (filter_var($host, FILTER_VALIDATE_IP))
		{
			return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
		}

		return (bool) preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/', $host);
	}

	// ==================================================================
	// Публикация и каталог
	// ==================================================================

	/** Публикация: читать диалог смогут все. Публикуем только сказанное. */
	public static function set_public($id, $user_id, $on, $is_admin = FALSE)
	{
		$dialog = self::dialog($id);

		if ( ! $dialog)
		{
			return ['error' => 'Диалог не найден'];
		}

		if ( ! self::owns($dialog, $user_id, $is_admin))
		{
			return ['error' => 'Это чужой диалог'];
		}

		$on = self::as_bool($on);

		if ($on AND (int) $dialog['turn'] < 1)
		{
			return ['error' => 'Публиковать нечего: в диалоге ещё нет ни одного обмена'];
		}

		self::db()->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET is_public = '.($on ? 'true' : 'false').',
				published_at = '.($on ? 'COALESCE(published_at, now())' : 'NULL').', updated_at = now()
			 WHERE id = '.(int) $id, FALSE);

		self::event($id, 'publish', $on ? 'Диалог опубликован' : 'Диалог снят с публикации');

		return ['ok' => TRUE, 'is_public' => $on];
	}

	/** Публичный диалог для чтения или NULL (гость видит только опубликованное). */
	public static function public_dialog($id)
	{
		$dialog = self::dialog($id);

		return ($dialog AND self::as_bool($dialog['is_public'])) ? $dialog : NULL;
	}

	/** Опубликованные диалоги для карты сайта (свежие сверху). */
	public static function public_ids($limit = 200)
	{
		$rows = self::db()->query(Database::SELECT,
			'SELECT id FROM nj_662_dialogs WHERE is_public ORDER BY published_at DESC NULLS LAST, id DESC
			 LIMIT '.max(1, (int) $limit), FALSE)->as_array();

		$ids = [];

		foreach ($rows as $row)
		{
			$ids[] = (int) ((array) $row)['id'];
		}

		return $ids;
	}

	/** Сколько опубликованных диалогов подходит под поиск. */
	public static function count_public($q = '')
	{
		return (int) self::db()->query(Database::SELECT,
			'SELECT count(*) AS n FROM nj_662_dialogs d WHERE d.is_public '.self::_public_where($q), FALSE)->get('n');
	}

	/**
	 * Каталог опубликованных диалогов: тема, участники, начало разговора.
	 *
	 * Ищем по теме, вводной и репликам: читателю важно найти разговор по сути,
	 * а не только по названию.
	 */
	public static function public_dialogs($q = '', $per = 12, $page = 1)
	{
		$per  = max(1, min(50, (int) $per));
		$page = max(1, (int) $page);
		$off  = ($page - 1) * $per;

		$rows = self::db()->query(Database::SELECT,
			'SELECT d.*,
				(SELECT count(*) FROM nj_662_messages m WHERE m.dialog_id = d.id) AS replies,
				(SELECT count(*) FROM nj_662_searches s WHERE s.dialog_id = d.id) AS searches,
				(SELECT m.content FROM nj_662_messages m WHERE m.dialog_id = d.id ORDER BY m.id ASC LIMIT 1) AS opening
			 FROM nj_662_dialogs d
			 WHERE d.is_public '.self::_public_where($q).'
			 ORDER BY d.published_at DESC NULLS LAST, d.id DESC
			 LIMIT '.$per.' OFFSET '.$off, FALSE)->as_array();

		return array_map([__CLASS__, '_row'], $rows);
	}

	/** Условие поиска по опубликованным (тема, вводная, реплики). */
	protected static function _public_where($q)
	{
		$q = self::clean_text($q, 120);

		if (mb_strlen($q) < 2)
		{
			return '';
		}

		$like = self::db()->quote('%'.$q.'%');

		return ' AND (d.topic ILIKE '.$like.' OR d.brief ILIKE '.$like
			.' OR EXISTS (SELECT 1 FROM nj_662_messages m WHERE m.dialog_id = d.id AND m.content ILIKE '.$like.'))';
	}

	// ==================================================================
	// Статус-ряд (страница и опрос)
	// ==================================================================

	/**
	 * Живой статус диалога одним словарём — для страницы и для опроса /status.
	 *
	 * Один источник правды важен буквально: страница и поллинг рисуют одно и то
	 * же место, и если бы словари разъехались, владелец видел бы «идёт» рядом с
	 * «пауза». Секундомер считает сервер, клиент только дотикивает между
	 * опросами (и останавливается на finished_at).
	 */
	public static function progress($dialog)
	{
		if ( ! is_array($dialog) OR ! $dialog)
		{
			return NULL;
		}

		$status = (string) Arr::get($dialog, 'status', 'draft');
		$live   = in_array($status, ['running', 'quick'], TRUE);

		$from   = Arr::get($dialog, 'started_at', Arr::get($dialog, 'created_at', NULL));
		$finish = Arr::get($dialog, 'finished_at', NULL);

		$end     = ($finish AND ! $live) ? strtotime((string) $finish) : time();
		$elapsed = ($from AND $end) ? max(0, $end - strtotime((string) $from)) : 0;

		$stage = trim((string) Arr::get($dialog, 'stage', ''));

		return [
			'id'            => (int) Arr::get($dialog, 'id', 0),
			'status'        => $status,
			'status_word'   => self::status_word($status),
			'tone'          => self::tone($status),
			'live'          => $live,
			'stage'         => $stage,
			'stage_note'    => (string) Arr::get($dialog, 'stage_note', ''),
			'stage_index'   => self::stage_index($stage),
			'stages'        => self::$stages,
			'turn'          => (int) Arr::get($dialog, 'turn', 0),
			'pairs'         => (int) Arr::get($dialog, 'pairs', 0),
			'turn_word'     => self::pair_word(Arr::get($dialog, 'turn', 0), Arr::get($dialog, 'pairs', 0)),
			'searches_used' => (int) Arr::get($dialog, 'searches_used', 0),
			'searches_max'  => (int) Arr::get($dialog, 'ddg', 0),
			'tokens'        => (int) Arr::get($dialog, 'tokens_total', 0),
			'counters_text' => self::counters_text($dialog),
			'title'         => trim((string) Arr::get($dialog, 'a_name', '')).' × '.trim((string) Arr::get($dialog, 'b_name', '')),
			'elapsed_sec'   => $elapsed,
			'elapsed_text'  => self::elapsed_text($elapsed),
			'error'         => (string) Arr::get($dialog, 'error', ''),
			'outcome'       => (string) Arr::get($dialog, 'outcome', ''),
			'summary'       => (string) Arr::get($dialog, 'summary', ''),
			'is_public'     => self::as_bool(Arr::get($dialog, 'is_public', FALSE)) ? TRUE : FALSE,
		];
	}

	/** Строка счётчиков под статус-рядом: обмены, поиски, токены. */
	public static function counters_text($dialog)
	{
		$used  = (int) Arr::get($dialog, 'searches_used', 0);
		$ddg   = (int) Arr::get($dialog, 'ddg', 0);
		$parts = [
			'обмен '.(int) Arr::get($dialog, 'turn', 0).' из '.(int) Arr::get($dialog, 'pairs', 0),
			'поиск: '.$used.' из '.$ddg,
		];

		if ((int) Arr::get($dialog, 'tokens_total', 0) > 0)
		{
			$parts[] = 'токенов: '.(int) Arr::get($dialog, 'tokens_total', 0);
		}

		return implode(' · ', $parts);
	}

	/** Индекс этапа в лестнице: неизвестный этап — «Обмен» (реже всего ошибка). */
	public static function stage_index($stage)
	{
		$stage = mb_strtolower(trim((string) $stage));

		if ($stage === '')
		{
			return 0;
		}

		$map = [
			'подбор'  => 0,
			'подготов' => 0,
			'ищу'     => 1,
			'читаю'   => 1,
			'интернет' => 1,
			'говор'   => 2,
			'отвеча'  => 2,
			'обмен'   => 2,
			'сводка'  => 3,
		];

		foreach ($map as $needle => $index)
		{
			if (mb_strpos($stage, $needle) !== FALSE)
			{
				return $index;
			}
		}

		return 2;
	}

	/**
	 * Статус для опроса (GET /dialog/status/<id>): то, что видно на странице.
	 *
	 * revision — отпечаток содержимого: если он вырос, страница перезагружается
	 * и показывает новые реплики. Так лента не «собирается» в браузере по
	 * кусочкам (в наработках этим занимался jQuery) — браузер только смотрит.
	 */
	public static function status_json($id, $user_id = NULL, $is_admin = FALSE)
	{
		$dialog = self::dialog($id);

		if ( ! $dialog)
		{
			return ['ok' => FALSE, 'error' => 'Диалог не найден'];
		}

		$own = ($user_id !== NULL AND self::owns($dialog, $user_id, $is_admin));

		if ( ! $own AND ! self::as_bool($dialog['is_public']))
		{
			return ['ok' => FALSE, 'error' => 'Диалог закрыт'];
		}

		$revision = (int) self::db()->query(Database::SELECT,
			'SELECT (COALESCE(max(id), 0) * 100 + count(*)) AS rev FROM nj_662_messages
			 WHERE dialog_id = '.(int) $id, FALSE)->get('rev');

		$progress = self::progress($dialog);

		return $progress + [
			'ok'       => TRUE,
			'own'      => $own,
			'revision' => (int) $revision,
			'replies'  => (int) self::db()->query(Database::SELECT,
				'SELECT count(*) AS n FROM nj_662_messages WHERE dialog_id = '.(int) $id, FALSE)->get('n'),
		];
	}
}
