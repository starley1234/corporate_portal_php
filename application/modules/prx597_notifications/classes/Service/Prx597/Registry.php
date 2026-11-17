<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Реестр типов событий (prx597).
 *
 * Отвечает на три вопроса центра уведомлений:
 *   1. Какие события вообще бывают? (модули объявляют их в своём манифесте)
 *   2. Кому это событие положено? (роли и права — «план» пользователя)
 *   3. Каким каналом и по какому шаблону его доставлять?
 *
 * Откуда берётся реестр:
 *   * модули объявляют события в config/prx_module.php, секция notify.events
 *     (пятый контракт: модуль описывает себя сам, ядро ничего о нём не знает);
 *   * администратор дополняет и правит записи через /prx597/admin/events —
 *     его правки (включено, обязательно, роли, каналы) важнее манифеста;
 *   * событие, о котором никто не объявил, но которое кто-то кинул, попадает
 *     в реестр автоматически (source = 'runtime'): уведомление не потеряется,
 *     а администратор увидит новинку и настроит её как положено.
 *
 * Планирование каналов (plan_for) — это и есть «матрица настроек»:
 *   каналы события ∩ разрешённые каналы ∩ привязки пользователя ∩ его выключатели.
 */
class Service_Prx597_Registry extends Service_Prx597_Base
{
	/** Категории событий: порядок = порядок в интерфейсе. */
	const CATEGORIES = [
		'system'    => 'Системные',
		'security'  => 'Безопасность',
		'jobs'      => 'Задачи и отчёты',
		'content'   => 'Контент',
		'billing'   => 'Деньги и счета',
		'social'    => 'Общение',
		'marketing' => 'Новости платформы',
	];

	/** Кеш реестра на запрос. */
	protected static $_events = NULL;

	/** Кеш ролей пользователей в рамках запроса. */
	protected static $_roles = [];

	// ------------------------------------------------------------------ реестр

	/**
	 * Все типы событий: alias => запись. Запись всегда содержит массивы
	 * 'channels' и 'roles' (кроме строк БД), поэтому интерфейсу не нужно помнить,
	 * что каналы в базе лежат строкой через запятую.
	 */
	public static function events($only_enabled = FALSE)
	{
		if (self::$_events === NULL)
		{
			$cache = [];

			try
			{
				$rows = self::_all('SELECT * FROM "prx_597_events" ORDER BY "category", "alias"');
			}
			catch (Throwable $e)
			{
				$rows = [];
			}

			foreach ($rows as $row)
			{
				$cache[(string) $row['alias']] = self::_shape($row);
			}

			self::$_events = $cache;
		}

		if ( ! $only_enabled)
		{
			return self::$_events;
		}

		$out = [];

		foreach (self::$_events as $alias => $event)
		{
			if ( ! empty($event['enabled']))
			{
				$out[$alias] = $event;
			}
		}

		return $out;
	}

	/** Один тип события (или NULL, если центр о нём не знает). */
	public static function event($alias)
	{
		$alias = self::_alias($alias);

		if ($alias === '')
		{
			return NULL;
		}

		$events = self::events();

		return isset($events[$alias]) ? $events[$alias] : NULL;
	}

	/**
	 * Зарегистрировать событие, если центр о нём не знает.
	 * Модуль может кинуть событие и без объявления в манифесте — терять его
	 * нельзя, поэтому реестр доучивается на ходу.
	 */
	public static function ensure($alias, array $defaults = [], $source = 'runtime')
	{
		$alias = self::_alias($alias);

		if ($alias === '')
		{
			return NULL;
		}

		$found = self::event($alias);

		if ($found !== NULL)
		{
			return $found;
		}

		$channels = self::_csv(Arr::get($defaults, 'channels', Arr::get($defaults, 'default_channels', 'in_app')));

		if ( ! $channels)
		{
			$channels = ['in_app'];
		}

		$row = [
			'alias'            => $alias,
			'module'           => self::_cut(Arr::get($defaults, 'module', ''), 80),
			'title'            => self::_cut(Arr::get($defaults, 'title', $alias), 200),
			'description'      => (string) Arr::get($defaults, 'description', ''),
			'category'         => self::_cut(Arr::get($defaults, 'category', 'system'), 40),
			'icon'             => self::_cut(Arr::get($defaults, 'icon', 'fas fa-bell'), 60),
			'default_channels' => implode(',', $channels),
			'allowed_roles'    => implode(',', self::_csv(Arr::get($defaults, 'roles', 'login'))) ?: 'login',
			'ability'          => NULL,
			'mandatory'        => FALSE,
			'enabled'          => TRUE,
			// Склеивать по умолчанию: не склеивают только срочные события,
			// и это пишут в манифесте явно ('digest' => FALSE).
			'digest'           => (bool) Arr::get($defaults, 'digest', TRUE),
			'source'           => $source,
			'placeholders'     => self::_cut(Arr::get($defaults, 'placeholders', ''), 1000),
		];

		self::save_event($alias, $row);

		return self::_shape($row);
	}

	/** Записать тип события (админка или доучивание реестра). Атомарный upsert. */
	public static function save_event($alias, array $data)
	{
		$alias = self::_alias($alias);

		if ($alias === '')
		{
			return FALSE;
		}

		$db = self::_db();

		$channels = implode(',', self::_csv(Arr::get($data, 'default_channels', Arr::get($data, 'channels', 'in_app')))) ?: 'in_app';
		$roles    = implode(',', self::_csv(Arr::get($data, 'allowed_roles', Arr::get($data, 'roles', 'login')))) ?: 'login';
		$ability  = trim((string) Arr::get($data, 'ability', ''));

		$db->query(Database::INSERT,
			'INSERT INTO "prx_597_events"
			     ("alias", "module", "title", "description", "category", "icon",
			      "default_channels", "allowed_roles", "ability", "mandatory",
			      "enabled", "digest", "source", "placeholders", "created_at", "updated_at")
			 VALUES ('.$db->quote($alias).',
			         '.$db->quote(self::_cut(Arr::get($data, 'module', ''), 80)).',
			         '.$db->quote(self::_cut(Arr::get($data, 'title', $alias), 200)).',
			         '.$db->quote((string) Arr::get($data, 'description', '')).',
			         '.$db->quote(self::_cut(Arr::get($data, 'category', 'system'), 40)).',
			         '.$db->quote(self::_cut(Arr::get($data, 'icon', 'fas fa-bell'), 60)).',
			         '.$db->quote($channels).',
			         '.$db->quote($roles).',
			         '.($ability === '' ? 'NULL' : $db->quote(self::_cut($ability, 80))).',
			         '.(! empty($data['mandatory']) ? 'TRUE' : 'FALSE').',
			         '.((! empty($data['enabled']) OR ! array_key_exists('enabled', $data)) ? 'TRUE' : 'FALSE').',
			         '.(! empty($data['digest']) ? 'TRUE' : 'FALSE').',
			         '.$db->quote(self::_cut(Arr::get($data, 'source', 'db'), 16)).',
			         '.$db->quote(self::_cut(Arr::get($data, 'placeholders', ''), 1000)).',
			         now(), now())
			 ON CONFLICT ("alias") DO UPDATE SET
			     "module"           = EXCLUDED."module",
			     "title"           = EXCLUDED."title",
			     "description"     = EXCLUDED."description",
			     "category"        = EXCLUDED."category",
			     "icon"            = EXCLUDED."icon",
			     "default_channels"= EXCLUDED."default_channels",
			     "allowed_roles"   = EXCLUDED."allowed_roles",
			     "ability"         = EXCLUDED."ability",
			     "mandatory"       = EXCLUDED."mandatory",
			     "enabled"         = EXCLUDED."enabled",
			     "digest"          = EXCLUDED."digest",
			     "placeholders"    = EXCLUDED."placeholders",
			     "updated_at"      = now()',
			FALSE);

		// Сбросить кеш: следующий events() перечитает базу.
		self::forget_events();

		return TRUE;
	}

	/** Включить/выключить событие (кнопка в админке). */
	public static function toggle_event($alias, $enabled)
	{
		$db = self::_db();

		$db->query(Database::UPDATE,
			'UPDATE "prx_597_events" SET "enabled" = '.($enabled ? 'TRUE' : 'FALSE').', "updated_at" = now()
			 WHERE "alias" = '.$db->quote(self::_alias($alias)), FALSE);

		self::forget_events();

		return TRUE;
	}

	/** Сбросить кеш реестра (после правки в админке или синхронизации). */
	public static function forget_events()
	{
		self::$_events = NULL;
	}

	/**
	 * Синхронизация с манифестами модулей: новые события из
	 * config/prx_module.php (секция notify.events) попадают в реестр.
	 *
	 * Правки администратора не затираем: обновляем только подписи, описание,
	 * иконку, категорию и плейсхолдеры — то, что приходит из кода. Включать,
	 * выключать и делать событие обязательным — дело администратора.
	 *
	 * @return array ['added' => n, 'updated' => n]
	 */
	public static function sync_manifests($force = FALSE)
	{
		if ( ! class_exists('Manifest'))
		{
			return ['added' => 0, 'updated' => 0, 'note' => 'Manifest недоступен'];
		}

		// Не чаще раза в час: манифесты меняются редко, а запрос идёт в базу.
		$stamp = (int) self::setting('registry.synced_at', 0);

		if ( ! $force AND $stamp > time() - 3600)
		{
			return ['added' => 0, 'updated' => 0, 'note' => 'недавно синхронизировано'];
		}

		$added = 0;
		$updated = 0;
		$seeded = 0;

		foreach (Manifest::section('notify') as $module => $block)
		{
			$list = (array) Arr::get((array) $block, 'events', []);

			foreach ($list as $alias => $def)
			{
				$alias = self::_alias($alias);

				if ($alias === '')
				{
					continue;
				}

				$def = (array) $def;
				$def['module'] = $module;

				$known = self::event($alias);

				if ($known === NULL)
				{
					self::ensure($alias, $def, 'manifest');
					$added++;
					continue;
				}

				// Уже знаем: обновляем только «из кода» поля и только если они
				// реально изменились (иначе updated_at дёргается на каждом запросе).
				$change = [
					'module'       => $module,
					'title'        => self::_cut(Arr::get($def, 'title', $known['title']), 200),
					'description'  => (string) Arr::get($def, 'description', $known['description']),
					'category'     => self::_cut(Arr::get($def, 'category', $known['category']), 40),
					'icon'         => self::_cut(Arr::get($def, 'icon', $known['icon']), 60),
					'placeholders' => self::_cut(Arr::get($def, 'placeholders', $known['placeholders']), 1000),
				];

				$differs = FALSE;

				foreach ($change as $key => $value)
				{
					if ((string) Arr::get($known, $key, '') !== (string) $value)
					{
						$differs = TRUE;
					}
				}

				if ($differs)
				{
					// Каналы и роли из манифеста применяем, только если их не
					// трогал администратор (source = 'manifest').
					if ($known['source'] === 'manifest')
					{
						$change['default_channels'] = implode(',', self::_csv(Arr::get($def, 'channels', $known['channels']))) ?: 'in_app';
						$change['allowed_roles']    = implode(',', self::_csv(Arr::get($def, 'roles', $known['roles']))) ?: 'login';
						$change['digest']           = (bool) Arr::get($def, 'digest', $known['digest']);
					}

					self::save_event($alias, array_merge($known, $change));
					$updated++;
				}
			}

			// Шаблоны из манифеста: «событие × канал» с готовым текстом.
			// Владелец (15.10.2026): «настрой шаблоны» — чтобы уведомление
			// модуля не приходило голым заголовком события. Заводим только то,
			// чего в базе ещё нет: правки администратора не затираем никогда.
			$seeded += self::_seed_templates($module, (array) Arr::get((array) $block, 'templates', []), array_keys($list));
		}

		self::set_setting('registry.synced_at', (string) time());
		self::forget_events();

		return ['added' => $added, 'updated' => $updated, 'templates' => $seeded];
	}

	/**
	 * Завести отсутствующие шаблоны из манифеста модуля.
	 *
	 * Формат в config/prx_module.php:
	 *   'notify' => ['templates' => ['<событие>' => ['<канал>' => ['subject','body','format']]]]
	 *
	 * Существующие строки не трогаем: если администратор правил шаблон (или в
	 * базе уже лежит своя версия), манифест — не указ.
	 *
	 * @param  string $module имя модуля (для журнала)
	 * @param  array  $defs   объявления шаблонов
	 * @param  array  $declared коды событий из того же манифеста
	 * @return int сколько завели
	 */
	/**
	 * Важность события: меньше — важнее (1 критично … 9 фоновое).
	 *
	 * Явно названное значение (в манифесте или в вызове emit) побеждает.
	 * Иначе смотрим на категорию события: «безопасность» не должна ночевать
	 * до утра, а «лайк» не должен будить. Это и есть правило по умолчанию —
	 * модулям не нужно ничего помнить.
	 *
	 * @param  array    $event    строка реестра событий (или её часть)
	 * @param  int|null $explicit значение от модуля, если он его назвал
	 * @return int 1..9
	 */
	public static function priority_for(array $event, $explicit = NULL)
	{
		if ($explicit !== NULL AND (int) $explicit > 0)
		{
			return max(1, min(9, (int) $explicit));
		}

		$category = strtolower(trim((string) Arr::get($event, 'category', '')));
		$map      = self::priority_map();

		if ($category !== '' AND isset($map[$category]))
		{
			return max(1, min(9, (int) $map[$category]));
		}

		return max(1, min(9, (int) self::cfg('priority.default', 5)));
	}

	/**
	 * Разовая правка «склеивать по умолчанию» для уже заведённых событий.
	 *
	 * До 16.10.2026 `digest` был FALSE у всего, что модули регистрировали на
	 * ходу (`source = runtime`) — то есть режим сводки у человека ничего не
	 * менял, а лента наполнялась одиночными сообщениями. Включаем склейку
	 * ровно этим строкам и только один раз: строки из манифестов несут своё
	 * значение явно, а 'manual' — решение администратора, его не трогаем.
	 *
	 * @return int сколько строк поправили
	 */
	public static function apply_digest_default_once()
	{
		if (self::setting('registry.digest_default', '') === '1')
		{
			return 0;
		}

		try
		{
			// "updated_at" <= "created_at" — строка не тронута после заведения,
			// то есть администратор её ещё не открывал: его решение не перебиваем.
			$fixed = (int) self::_val(
				'WITH u AS (UPDATE "prx_597_events" SET "digest" = TRUE, "updated_at" = now()
				              WHERE "source" = \'runtime\' AND "digest" IS FALSE
				                AND "updated_at" <= "created_at" RETURNING 1)
				 SELECT count(*) AS c FROM u', 0);

			self::set_setting('registry.digest_default', '1');

			if ($fixed > 0)
			{
				self::forget_events();

				Kohana::$log->add(Log::INFO, 'prx597: событиям, заведённым модулями, включена склейка в сводку: :n', [':n' => $fixed]);
			}

			return $fixed;
		}
		catch (Throwable $e)
		{
			// Базы под рукой нет — попробуем в следующий час.
			return 0;
		}
	}

	protected static function _seed_templates($module, array $defs, array $declared = [])
	{
		if ( ! $defs)
		{
			return 0;
		}

		$known_channels = array_keys((array) Service_Prx597_Base::channels(FALSE));
		$declared       = array_map(function ($one) { return self::_alias($one); }, $declared);
		$seeded         = 0;

		foreach ($defs as $alias => $channels)
		{
			$alias = self::_alias($alias);

			// Событие должно быть объявлено в ЭТОМ же манифесте рядом: шаблон
			// для чужого или удалённого события — мусор в базе. Проверяем
			// объявление, а не строку в реестре: реестр в этом же проходе
			// только что наполнился из манифестов.
			if ($alias === '' OR ! in_array($alias, $declared, TRUE))
			{
				Kohana::$log->add(Log::WARNING, 'prx597: шаблон :a из :m пропущен — событие не объявлено в манифесте',
					[':a' => (string) $alias, ':m' => (string) $module]);

				continue;
			}

			// templates() отдаёт карту «событие → канал → шаблон», даже когда
			// спросили про одно событие: разворачиваем её, иначе isset() всегда
			// ложь и синхронизация каждый час затирала бы правки администратора
			// (нашёл стенд контракта, 16.10.2026).
			$existing = self::templates($alias);
			$existing = isset($existing[$alias]) ? $existing[$alias] : [];

			foreach ((array) $channels as $channel => $def)
			{
				$channel = (string) $channel;
				$def     = (array) $def;

				if (isset($existing[$channel]) OR ! in_array($channel, $known_channels, TRUE))
				{
					continue;
				}

				if (trim((string) Arr::get($def, 'subject', '')) === '' AND trim((string) Arr::get($def, 'body', '')) === '')
				{
					continue;
				}

				if (self::save_template($alias, $channel, $def, NULL))
				{
					$seeded++;
				}
			}
		}

		return $seeded;
	}

	// ---------------------------------------------------------------- шаблоны

	/** Шаблон события для канала (или NULL). */
	public static function template($alias, $channel)
	{
		$alias = self::_alias($alias);

		if ($alias === '')
		{
			return NULL;
		}

		try
		{
			$row = self::_one('SELECT * FROM "prx_597_templates"
			                    WHERE "event_alias" = '.self::_q($alias).'
			                      AND "channel" = '.self::_q($channel).'
			                    LIMIT 1');
		}
		catch (Throwable $e)
		{
			return NULL;
		}

		if ( ! $row)
		{
			return NULL;
		}

		if ( ! self::_truthy(Arr::get($row, 'is_active', TRUE)))
		{
			return NULL;
		}

		return [
			'event_alias' => (string) $row['event_alias'],
			'channel'     => (string) $row['channel'],
			'subject'     => (string) $row['subject'],
			'body'        => (string) $row['body'],
			'format'      => (string) $row['format'],
			'is_active'   => TRUE,
			'updated_at'  => (string) Arr::get($row, 'updated_at', ''),
		];
	}

	/** Все шаблоны: event_alias => [channel => шаблон]. */
	public static function templates($alias = NULL)
	{
		try
		{
			$rows = self::_all('SELECT * FROM "prx_597_templates"'
				.($alias === NULL ? '' : ' WHERE "event_alias" = '.self::_q(self::_alias($alias)))
				.' ORDER BY "event_alias", "channel"');
		}
		catch (Throwable $e)
		{
			return [];
		}

		$out = [];

		foreach ($rows as $row)
		{
			$out[(string) $row['event_alias']][(string) $row['channel']] = [
				'subject'    => (string) $row['subject'],
				'body'       => (string) $row['body'],
				'format'     => (string) $row['format'],
				'is_active'  => self::_truthy(Arr::get($row, 'is_active', TRUE)),
				'updated_at' => (string) Arr::get($row, 'updated_at', ''),
			];
		}

		return $out;
	}

	/** Сохранить шаблон (админка). Пустой subject+body удаляет шаблон. */
	public static function save_template($alias, $channel, array $data, $user_id = NULL)
	{
		$alias   = self::_alias($alias);
		$channel = self::_cut($channel, 20);
		$subject = trim((string) Arr::get($data, 'subject', ''));
		$body    = trim((string) Arr::get($data, 'body', ''));

		if ($alias === '' OR $channel === '')
		{
			return FALSE;
		}

		$db = self::_db();

		if ($subject === '' AND $body === '')
		{
			$db->query(Database::DELETE,
				'DELETE FROM "prx_597_templates"
				  WHERE "event_alias" = '.$db->quote($alias).' AND "channel" = '.$db->quote($channel), FALSE);

			return TRUE;
		}

		$format = self::_cut(Arr::get($data, 'format', 'text'), 10);

		if ( ! in_array($format, ['text', 'html', 'md'], TRUE))
		{
			$format = 'text';
		}

		$db->query(Database::INSERT,
			'INSERT INTO "prx_597_templates"
			     ("event_alias", "channel", "subject", "body", "format", "is_active", "updated_at", "updated_by")
			 VALUES ('.$db->quote($alias).', '.$db->quote($channel).',
			         '.$db->quote(self::_cut($subject, 300)).', '.$db->quote($body).',
			         '.$db->quote($format).', '.(! empty($data['is_active']) ? 'TRUE' : 'FALSE').',
			         now(), '.((int) $user_id ?: 'NULL').')
			 ON CONFLICT ("event_alias", "channel") DO UPDATE SET
			     "subject"    = EXCLUDED."subject",
			     "body"       = EXCLUDED."body",
			     "format"     = EXCLUDED."format",
			     "is_active"  = EXCLUDED."is_active",
			     "updated_at" = now(),
			     "updated_by" = EXCLUDED."updated_by"',
			FALSE);

		return TRUE;
	}

	// ----------------------------------------------------- настройки пользователя

	/** Матрица настроек пользователя: [event_alias][channel] => bool. */
	public static function subs($user_id)
	{
		try
		{
			$rows = self::_all('SELECT "event_alias", "channel", "enabled" FROM "prx_597_subs"
			                    WHERE "user_id" = '.((int) $user_id));
		}
		catch (Throwable $e)
		{
			return [];
		}

		$out = [];

		foreach ($rows as $row)
		{
			$out[(string) $row['event_alias']][(string) $row['channel']] = self::_truthy($row['enabled']);
		}

		return $out;
	}

	/**
	 * Записать клетку матрицы настроек.
	 *
	 * Включённая клетка включает и сам способ доставки: каналы выключены по
	 * умолчанию, и человек, отметивший событие в колонке «почта», ждёт письма,
	 * а не тишины. Обратное неверно: галочка канала сама ничего не выключает —
	 * клетки остаются как выбраны.
	 */
	public static function save_sub($user_id, $alias, $channel, $enabled)
	{
		$db = self::_db();

		if ($enabled)
		{
			$gate = 'email_enabled';

			if (in_array((string) $channel, ['in_app', 'telegram', 'max'], TRUE))
			{
				$gate = (string) $channel.'_enabled';
			}

			try
			{
				Service_Prx597_Notify::save_prefs((int) $user_id, [$gate => TRUE]);
			}
			catch (Throwable $e)
			{
				// Настройки не записались — клетку всё равно сохраняем: пусть
				// человек видит свой выбор, а доставку дорешает форма каналов.
			}
		}

		$db->query(Database::INSERT,
			'INSERT INTO "prx_597_subs" ("user_id", "event_alias", "channel", "enabled", "updated_at")
			 VALUES ('.((int) $user_id).', '.$db->quote(self::_alias($alias)).', '.$db->quote(self::_cut($channel, 20)).',
			         '.($enabled ? 'TRUE' : 'FALSE').', now())
			 ON CONFLICT ("user_id", "event_alias", "channel") DO UPDATE SET
			     "enabled" = EXCLUDED."enabled", "updated_at" = now()',
			FALSE);

		return TRUE;
	}

	/** Сбросить матрицу пользователя к умолчаниям событий. */
	public static function reset_subs($user_id)
	{
		self::_exec(Database::DELETE,
			'DELETE FROM "prx_597_subs" WHERE "user_id" = '.((int) $user_id));

		return TRUE;
	}

	// -------------------------------------------------------------- планирование

	/**
	 * Каналы, которыми событие дойдёт до пользователя.
	 *
	 * Порядок фильтров (каждый может только сузить список):
	 *   1. событие включено администратором;
	 *   2. пользователь подходит по ролям и праву (ability);
	 *   3. канал разрешён событием;
	 *   4. канал включен в платформе и у пользователя;
	 *   5. обязательное событие (mandatory) нельзя выключить — всегда in_app.
	 *
	 * @param  int   $user_id
	 * @param  array $event  запись реестра (или минимум с ключами alias/channels)
	 * @param  array $prefs  настройки пользователя (Service_Prx597_Notify::prefs)
	 * @param  array $roles  роли пользователя (по умолчанию — считаем сами)
	 * @return array список каналов
	 */
	public static function plan_for($user_id, array $event, array $prefs = NULL, array $roles = NULL)
	{
		$user_id = (int) $user_id;

		if ($user_id <= 0 OR empty($event['enabled']))
		{
			return [];
		}

		if ($roles === NULL)
		{
			$roles = self::roles_of($user_id);
		}

		if ( ! self::user_allowed($event, $roles))
		{
			return [];
		}

		if ($prefs === NULL)
		{
			$prefs = Service_Prx597_Notify::prefs($user_id);
		}

		$subs  = self::subs($user_id);
		$plan  = [];

		foreach ((array) Arr::get($event, 'channels', ['in_app']) as $channel)
		{
			$channel = (string) $channel;

			if ( ! self::channel_enabled($channel))
			{
				continue;
			}

			// Персональный выключатель канала (почта/телеграм/макс/колокольчик).
			$gate = $channel.'_enabled';

			if (array_key_exists($gate, $prefs) AND ! self::_truthy($prefs[$gate]))
			{
				continue;
			}

			// Клетка матрицы «событие × канал». Обязательное событие не выключаем.
			if ( ! self::sub_enabled($event, $channel, $prefs, $subs))
			{
				continue;
			}

			$plan[] = $channel;
		}

		// Обязательное событие всегда оставляет след на сайте: отключить
		// колокольчик нельзя ни пользователю, ни администратору.
		if ( ! empty($event['mandatory']) AND self::channel_enabled('in_app') AND ! in_array('in_app', $plan, TRUE))
		{
			$plan[] = 'in_app';
		}

		return array_values(array_unique($plan));
	}

	/**
	 * Состояние клетки «событие × канал».
	 *
	 * Владелец (16.10.2026): «по умолчанию у пользователей должны быть
	 * отключены уведомления», и он же выбрал: колокольчик на сайте остаётся
	 * включённым. Поэтому пустая клетка значит «нет» для внешних каналов и
	 * «да» для in_app — лента внутри сайта не беспокоит и работает всегда.
	 * Обязательные события (безопасность, деньги) не выключаются вообще.
	 */
	public static function sub_enabled(array $event, $channel, array $prefs, array $subs)
	{
		if ( ! empty($event['mandatory'])) { return TRUE; }
		$alias = (string) $event['alias'];
		if (isset($subs[$alias][$channel])) { return self::_truthy($subs[$alias][$channel]); }
		// Старые флаги формы (on_done/on_failed) — такие же явные отметки,
		// но только для своих двух событий и только когда включены.
		$legacy = ['prx120.job.done' => 'on_done', 'prx120.job.failed' => 'on_failed'];
		if ($channel === 'email' AND isset($legacy[$alias]))
		{
			return self::_truthy(Arr::get($prefs, $legacy[$alias], FALSE));
		}
		// Колокольчик: по умолчанию включён, пока человек сам его не снял.
		return ((string) $channel === 'in_app');
	}

	/**
	 * Положено ли событие пользователю по ролям и правам.
	 * Пустой список ролей события = «всем авторизованным».
	 */
	public static function user_allowed(array $event, array $roles)
	{
		$allowed = self::_csv(Arr::get($event, 'roles', Arr::get($event, 'allowed_roles', 'login')));

		if ($allowed)
		{
			// 'login' — псевдоним: любой вошедший.
			if ( ! in_array('login', $allowed, TRUE) AND ! array_intersect($allowed, $roles))
			{
				return FALSE;
			}
		}

		$ability = trim((string) Arr::get($event, 'ability', ''));

		if ($ability !== '' AND class_exists('ACL'))
		{
			return (bool) ACL::check($roles, $ability);
		}

		return TRUE;
	}

	/** Роли пользователя (кеш на запрос). */
	public static function roles_of($user_id)
	{
		$user_id = (int) $user_id;

		if ($user_id <= 0)
		{
			return [];
		}

		if (isset(self::$_roles[$user_id]))
		{
			return self::$_roles[$user_id];
		}

		$roles = [];

		try
		{
			$user = ORM::factory('User', $user_id);

			if ($user->loaded() AND method_exists($user, 'get_role_names'))
			{
				$roles = array_values(array_filter((array) $user->get_role_names()));
			}
		}
		catch (Throwable $e)
		{
			$roles = [];
		}

		return self::$_roles[$user_id] = $roles;
	}

	/** Сбросить кеш ролей (после смены ролей пользователя). */
	public static function forget_roles()
	{
		self::$_roles = [];
	}

	// ------------------------------------------------------------------ всякое

	/** Категории с подписями (для фильтра в интерфейсе). */
	public static function categories()
	{
		return self::CATEGORIES;
	}

	/** Подпись категории. */
	public static function category_label($category)
	{
		return (string) Arr::get(self::CATEGORIES, $category, 'Разное');
	}

	/**
	 * Важность по категориям: категория → число (1 — будим сразу, 9 — фон).
	 *
	 * Источник — настройка `priority.by_category` (её правит администратор
	 * кнопками на вкладке «Лимиты»), а если её нет — конфиг модуля. Формат
	 * настройки — компактный CSV `security=1,billing=2,…`: одна строка в
	 * таблице настроек вместо JSON, который в базе всё равно нечитаем глазом.
	 * Категория, которой нет ни там, ни тут (событие нового модуля), получает
	 * priority.default — без важности событий не бывает.
	 */
	public static function priority_map()
	{
		$map = (array) self::cfg('priority.by_category', []);
		$raw = trim((string) self::setting('priority.by_category', ''));

		if ($raw === '')
		{
			return $map;
		}

		foreach (explode(',', $raw) as $pair)
		{
			$pair = trim($pair);

			if ($pair === '' OR strpos($pair, '=') === FALSE)
			{
				continue;
			}

			list($category, $value) = explode('=', $pair, 2);
			$category = strtolower(trim($category));

			if ($category === '')
			{
				continue;
			}

			$map[$category] = max(1, min(9, (int) $value));
		}

		return $map;
	}

	/** Шкала для админки: важное сверху, с русскими подписями категорий. */
	public static function priority_rows()
	{
		$map = self::priority_map();
		asort($map, SORT_NUMERIC);

		$out = [];

		foreach ($map as $category => $priority)
		{
			$out[] = [
				'category' => (string) $category,
				'label'    => self::category_label((string) $category),
				'priority' => max(1, min(9, (int) $priority)),
			];
		}

		return $out;
	}

	/**
	 * Поднять или опустить категорию на шкале важности.
	 *
	 * Меняются местами соседи, а не числа: набор значений остаётся тем, что
	 * задан конфигом (1, 2, 3, 5, 6, 7). Иначе «Задачи» уехали бы на 4 и
	 * перестали попадать в суточную сводку (порог priority.digest_from = 5):
	 * администратор, двигая стрелки ради порядка, незаметно менял бы правила
	 * тишины и сводки.
	 *
	 * @param  string $category код категории
	 * @param  int    $dir      -1 — выше (важнее), +1 — ниже
	 * @return int    новая важность, 0 — двигать некуда
	 */
	public static function priority_move($category, $dir)
	{
		$category = strtolower(trim((string) $category));
		$dir      = ((int) $dir < 0) ? -1 : 1;

		$map = self::priority_map();
		asort($map, SORT_NUMERIC);

		$order = array_keys($map);
		$i     = array_search($category, $order, TRUE);

		if ($i === FALSE OR count($order) < 2)
		{
			return 0;
		}

		$j = $i + $dir;

		if ( ! isset($order[$j]))
		{
			return 0;   // категория на краю шкалы
		}

		$values = array_values($map);

		$tmp        = $values[$i];
		$values[$i] = $values[$j];
		$values[$j] = $tmp;

		$saved = [];

		foreach ($order as $k => $one)
		{
			$saved[$one] = (int) $values[$k];
		}

		// В настройку пишем лестницу от важного к фону: администратор потом
		// читает строку в базе и видит тот же порядок, что и на экране.
		asort($saved, SORT_NUMERIC);

		$out = [];

		foreach ($saved as $one => $priority)
		{
			$out[] = $one.'='.$priority;
		}

		self::set_setting('priority.by_category', implode(',', $out));

		return (int) $saved[$category];
	}

	/**
	 * Нормализация alias события: латиница, цифры, точка, подчёркивание,
	 * дефис. Всё остальное вырезаем — alias попадает и в ключи массивов,
	 * и в SQL, и в вебхуки.
	 */
	protected static function _alias($alias)
	{
		$alias = strtolower(trim((string) $alias));

		return (string) preg_replace('/[^a-z0-9._-]+/', '', $alias);
	}

	/** Привести строку БД к форме, с которой удобно работать в коде. */
	protected static function _shape(array $row)
	{
		return [
			'alias'        => (string) $row['alias'],
			'module'       => (string) Arr::get($row, 'module', ''),
			'title'        => (string) Arr::get($row, 'title', ''),
			'description'  => (string) Arr::get($row, 'description', ''),
			'category'     => (string) Arr::get($row, 'category', 'system'),
			'icon'         => (string) Arr::get($row, 'icon', 'fas fa-bell'),
			'channels'     => self::_csv(Arr::get($row, 'default_channels', 'in_app')),
			'roles'        => self::_csv(Arr::get($row, 'allowed_roles', 'login')),
			'ability'      => (string) Arr::get($row, 'ability', ''),
			'mandatory'    => self::_truthy(Arr::get($row, 'mandatory', FALSE)),
			'enabled'      => self::_truthy(Arr::get($row, 'enabled', TRUE)),
			'digest'       => self::_truthy(Arr::get($row, 'digest', TRUE)),
			'source'       => (string) Arr::get($row, 'source', 'db'),
			'placeholders' => (string) Arr::get($row, 'placeholders', ''),
			'updated_at'   => (string) Arr::get($row, 'updated_at', ''),
		];
	}
}
