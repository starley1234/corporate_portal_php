<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Антиспам: лимиты, дедупликация, тихие часы (prl597).
 *
 * Центр уведомлений обязан уметь молчать. Три механизма:
 *
 *   1. Лимиты по «вёдрам» (таблица prl_597_rate): час и день на пользователя
 *      и канал, час на всю платформу, день на событие. Ведро — просто строка
 *      вида u:12:email:h:2026-10-09T15, счётчик растёт атомарным upsert.
 *      Превысили — не теряем сообщение, а откладываем его до следующего окна
 *      (next_attempt_at), поэтому всплеск рассылается «как получится», но
 *      не превращается в спам и не удаляется.
 *   2. Дедупликация: у сообщения может быть dedup_key (например «задача 123
 *      упала»). Повтор с тем же ключом внутри окна молча отбрасывается —
 *      модуль может дёргать событие сколько угодно часто.
 *   3. Тихие часы: ночью мессенджеры не дёргаем. Сообщение ждёт утра, если
 *      только оно не критичное (приоритет 1).
 *
 * Все числа — из настроек администратора (prl_597_settings), умолчания —
 * из config/prl597_notifications.php. Администратор видит их в /prl597/admin/limits.
 */
class Service_Prl597_Limits extends Service_Prl597_Base
{
	// ------------------------------------------------------------------ лимиты

	/**
	 * Проверить и списать попытку.
	 *
	 * @param  int    $user_id
	 * @param  string $channel
	 * @param  string $event_alias
	 * @return array ['ok' => bool, 'reason' => string, 'retry_after' => int сек]
	 */
	public static function check($user_id, $channel, $event_alias = '')
	{
		$checks = [
			['bucket' => self::bucket_user($user_id, $channel, 'h'), 'limit' => self::limit('per_user_hour', $channel), 'window' => 'hour', 'what' => 'в час на пользователя'],
			['bucket' => self::bucket_user($user_id, $channel, 'd'), 'limit' => self::limit('per_user_day', $channel), 'window' => 'day', 'what' => 'в сутки на пользователя'],
			['bucket' => self::bucket_global($channel, 'h'), 'limit' => self::limit('per_hour', $channel), 'window' => 'hour', 'what' => 'в час на всю платформу'],
		];

		if ($event_alias !== '')
		{
			$checks[] = [
				'bucket' => self::bucket_event($user_id, $channel, $event_alias, 'd'),
				'limit'  => self::limit('per_event_day', $channel),
				'window' => 'day',
				'what'   => 'в сутки на это событие',
			];

			// Предохранитель на источник: событие сыпет всем подряд.
			$checks[] = [
				'bucket' => self::bucket_source($channel, $event_alias, 'h'),
				'limit'  => self::limit('source_hour', $channel),
				'window' => 'hour',
				'what'   => 'в час на событие «'.$event_alias.'» у всех',
				'source' => (string) $event_alias,
			];
		}

		foreach ($checks as $check)
		{
			$limit = (int) $check['limit'];

			if ($limit <= 0)
			{
				continue;   // 0 = «не ограничивать»
			}

			$used = self::consume($check['bucket']);

			if ($used > $limit)
			{
				if (isset($check['source']))
				{
					self::_alert_source((string) $check['source'], $channel, $used, $limit);
				}

				return [
					'ok'          => FALSE,
					'reason'      => 'лимит «'.$check['what'].'»: '.$limit.' на канал '.self::channel_label($channel),
					'retry_after' => self::window_left($check['window']),
				];
			}
		}

		return ['ok' => TRUE, 'reason' => '', 'retry_after' => 0];
	}

	/**
	 * Атомарно увеличить ведро и вернуть новое значение счётчика.
	 * Один запрос вместо «прочитать, проверить, записать» — два параллельных
	 * пинка не проскочат лимит одновременно.
	 */
	public static function consume($bucket)
	{
		try
		{
			$row = self::_one(
				'INSERT INTO "prl_597_rate" ("bucket", "count", "updated_at")
				 VALUES ('.self::_q($bucket).', 1, now())
				 ON CONFLICT ("bucket") DO UPDATE SET "count" = "prl_597_rate"."count" + 1, "updated_at" = now()
				 RETURNING "count"');
		}
		catch (Throwable $e)
		{
			// Нет таблицы или база недоступна — не превращаем это в тишину:
			// считаем, что лимит не выбран (сообщение уйдёт).
			return 0;
		}

		return (int) Arr::get((array) $row, 'count', 0);
	}

	/** Сколько уже «накапало» в ведре (без увеличения). */
	public static function used($bucket)
	{
		try
		{
			return (int) self::_val('SELECT "count" FROM "prl_597_rate" WHERE "bucket" = '.self::_q($bucket), 0);
		}
		catch (Throwable $e)
		{
			return 0;
		}
	}

	/** Обнулить все вёдра (кнопка администратора «сбросить счётчики»). */
	public static function reset()
	{
		self::_exec(Database::DELETE, 'DELETE FROM "prl_597_rate"');

		return TRUE;
	}

	// ------------------------------------------------------------------ вёдра

	/** Ведро пользователя: u:<user>:<channel>:<h|d>:<окно>. */
	public static function bucket_user($user_id, $channel, $window = 'h')
	{
		return 'u:'.((int) $user_id).':'.self::_cut($channel, 20).':'.$window.':'.self::_window($window);
	}

	/** Общее ведро платформы: g:<channel>:<h|d>:<окно>. */
	public static function bucket_global($channel, $window = 'h')
	{
		return 'g:'.self::_cut($channel, 20).':'.$window.':'.self::_window($window);
	}

	/** Ведро источника: ge:<channel>:<alias>:<h|d>:<окно> — событие у всех людей. */
	public static function bucket_source($channel, $event_alias, $window = 'h')
	{
		return 'ge:'.self::_cut($channel, 20).':'.self::_cut($event_alias, 60).':'.$window.':'.self::_window($window);
	}

	/**
	 * Запись администратору: событие упёрлось в предохранитель.
	 *
	 * Пишем только в момент первого превышения (used == limit + 1): иначе
	 * каждое сообщение переписывало бы отметку и панель шумела бы сама.
	 */
	protected static function _alert_source($alias, $channel, $used, $limit)
	{
		if ((int) $used !== (int) $limit + 1)
		{
			return;
		}

		$alert = [
			'alias'   => (string) $alias,
			'channel' => (string) $channel,
			'count'   => (int) $used,
			'limit'   => (int) $limit,
			'at'      => time(),
		];

		try
		{
			self::set_setting('alert.source', json_encode($alert, JSON_UNESCAPED_UNICODE));

			self::_log(Log::WARNING, 'предохранитель: событие :a идёт слишком часто (:n за час при лимите :l) — рассылка приостановлена',
				[':a' => (string) $alias, ':n' => (int) $used, ':l' => (int) $limit]);
		}
		catch (Throwable $e)
		{
			// Отметка — не повод ронять доставку.
		}
	}

	/**
	 * Последнее срабатывание предохранителя для панели управления.
	 *
	 * @param  int $hours сколько часов считать свежим
	 * @return array|null ['alias','channel','count','limit','at'] или NULL
	 */
	public static function source_alert($hours = 24)
	{
		$raw = (string) self::setting('alert.source', '');

		if ($raw === '')
		{
			return NULL;
		}

		$data = json_decode($raw, TRUE);

		if ( ! is_array($data) OR empty($data['at']))
		{
			return NULL;
		}

		if ((int) $data['at'] < time() - max(1, (int) $hours) * 3600)
		{
			return NULL;   // старое: панель не должна пугать прошлой неделей
		}

		return $data;
	}

	/** Ведро «пользователь × событие × канал»: ue:<user>:<alias>:<channel>:<окно>. */
	public static function bucket_event($user_id, $channel, $event_alias, $window = 'd')
	{
		return 'ue:'.((int) $user_id).':'.self::_cut($event_alias, 60).':'.self::_cut($channel, 20).':'.$window.':'.self::_window($window);
	}

	/** Метка текущего окна: час или день по UTC (считаем одинаково для всех). */
	protected static function _window($window)
	{
		return $window === 'd' ? gmdate('Y-m-d') : gmdate('Y-m-d\TH');
	}

	/** Сколько секунд осталось до конца окна. */
	public static function window_left($window)
	{
		if ($window === 'd')
		{
			return 86400 - ((int) gmdate('H') * 3600 + (int) gmdate('i') * 60 + (int) gmdate('s'));
		}

		return 3600 - ((int) gmdate('i') * 60 + (int) gmdate('s'));
	}

	// ----------------------------------------------------------------- лимиты

	/**
	 * Значение лимита: настройка администратора или умолчание из конфига.
	 * Ключ вида 'per_user_hour.email'.
	 */
	public static function limit($scope, $channel, $default = NULL)
	{
		$key     = 'limits.'.$scope.'.'.$channel;
		$setting = self::setting($key, NULL);

		if ($setting !== NULL AND $setting !== '')
		{
			return (int) $setting;
		}

		if ($default !== NULL)
		{
			return (int) $default;
		}

		return (int) self::cfg('limits.'.$scope.'.'.$channel, 0);
	}

	/** Все лимиты одной таблицей (для страницы настроек). */
	public static function table()
	{
		$rows = [];

		foreach (['per_user_hour', 'per_user_day', 'per_hour', 'per_event_day'] as $scope)
		{
			foreach (self::channels(FALSE) as $channel => $info)
			{
				$rows[$scope][$channel] = self::limit($scope, $channel);
			}
		}

		return $rows;
	}

	// -------------------------------------------------------------- дедупликация

	/** Окно дедупликации, секунды. */
	public static function dedup_window()
	{
		$seconds = (int) self::setting('limits.dedup_window', self::cfg('limits.dedup_window', 300));

		return $seconds > 0 ? $seconds : 0;
	}

	/**
	 * Собрать ключ дедупликации. Пустой результат — дедупликация не нужна.
	 *
	 * @param string $event_alias
	 * @param array  $payload  события (в нём ищем код/ид сущности)
	 * @param string $explicit явный ключ от вызывающего модуля
	 */
	public static function dedup_key($event_alias, array $payload = [], $explicit = '')
	{
		$key = trim((string) $explicit);

		if ($key === '')
		{
			$key = trim((string) Arr::get($payload, 'dedup_key', ''));
		}

		if ($key === '')
		{
			// У события есть «сущность» (задача, документ) — склеиваем по ней,
			// иначе двадцать однотипных сообщений превратятся в одно лишь
			// потому, что модуль забыл про ключ.
			$entity = Arr::get($payload, 'entity_id', Arr::get($payload, 'code', NULL));

			if ($entity !== NULL AND $entity !== '')
			{
				$key = (string) $entity;
			}
		}

		if ($key === '')
		{
			return '';
		}

		$window = self::dedup_window();

		// Ключ живёт столько же, сколько окно: utc-метка окна входит в ключ,
		// поэтому «то же самое через час» снова дойдёт до получателя.
		$stamp = $window > 0 ? (string) (int) (time() / max(1, $window)) : '0';

		return self::_cut($event_alias, 60).'|'.self::_cut($key, 80).'|'.$stamp;
	}

	// ------------------------------------------------------------- тихие часы

	/**
	 * Сейчас у получателя «тихо» и канал молчит?
	 *
	 * @param array  $prefs   настройки пользователя (quiet_from/quiet_to/tz)
	 * @param string $channel
	 * @param int    $priority приоритет сообщения (1 — критичное, всегда шлём)
	 * @return bool
	 */
	public static function quiet_now(array $prefs, $channel, $priority = 5)
	{
		if ( ! self::_truthy(self::setting('quiet.enabled', self::cfg('quiet.enabled', TRUE))))
		{
			return FALSE;
		}

		if ( ! in_array((string) $channel, self::quiet_channels(), TRUE))
		{
			return FALSE;
		}

		if ((int) $priority <= (int) self::cfg('priority.quiet_skip', 1))
		{
			return FALSE;   // критичное не ждёт утра
		}

		list($from, $to) = self::quiet_bounds($prefs);

		if ($from < 0)
		{
			return FALSE;
		}

		$hour = self::local_hour($prefs);

		if ($from === $to)
		{
			return FALSE; // одинаковые границы — пользователь выключил тихие часы
		}

		// Окно может переходить через полночь: 22 → 8.
		return $from < $to ? ($hour >= $from AND $hour < $to) : ($hour >= $from OR $hour < $to);
	}

	/** Часы тишины пользователя: [from, to] или [-1, -1], если не заданы. */
	public static function quiet_bounds(array $prefs)
	{
		$from = isset($prefs['quiet_from']) ? (int) $prefs['quiet_from'] : -1;
		$to   = isset($prefs['quiet_to']) ? (int) $prefs['quiet_to'] : -1;

		if ($from < 0 OR $to < 0)
		{
			$from = (int) self::cfg('quiet.from', -1);
			$to   = (int) self::cfg('quiet.to', -1);
		}

		if ($from < 0 OR $to < 0 OR $from > 23 OR $to > 23)
		{
			return [-1, -1];
		}

		return [$from, $to];
	}

	/** Текущий час в часовом поясе пользователя. */
	public static function local_hour(array $prefs)
	{
		$tz = trim((string) Arr::get($prefs, 'tz', '')) ?: (string) self::cfg('quiet.tz', 'Europe/Moscow');

		try
		{
			$date = new DateTime('now', new DateTimeZone($tz));
		}
		catch (Throwable $e)
		{
			$date = new DateTime('now', new DateTimeZone('Europe/Moscow'));
		}

		return (int) $date->format('G');
	}

	/**
	 * Когда канал «проснётся»: unix-метка начала следующего разрешённого часа
	 * в часовом поясе пользователя. Используем как next_attempt_at.
	 */
	public static function next_wakeup(array $prefs, $channel = '')
	{
		list($from, $to) = self::quiet_bounds($prefs);

		if ($from < 0)
		{
			return time();
		}

		$tz = trim((string) Arr::get($prefs, 'tz', '')) ?: (string) self::cfg('quiet.tz', 'Europe/Moscow');

		try
		{
			$zone = new DateTimeZone($tz);
		}
		catch (Throwable $e)
		{
			$zone = new DateTimeZone('Europe/Moscow');
		}

		$now = new DateTime('now', $zone);

		// Ищем ближайший час, который уже не входит в тихое окно.
		for ($step = 1; $step <= 24; $step++)
		{
			$candidate = clone $now;
			$candidate->add(new DateInterval('PT'.$step.'H'));
			$candidate->setTime((int) $candidate->format('G'), 0, 0);

			$hour = (int) $candidate->format('G');
			$quiet = $from < $to ? ($hour >= $from AND $hour < $to) : ($hour >= $from OR $hour < $to);

			if ( ! $quiet)
			{
				return (int) $candidate->getTimestamp();
			}
		}

		return time() + 3600;
	}

	// ------------------------------------------------------------------ склейка

	/**
	 * До какого времени отложить сообщение, если пользователь выбрал дайджест.
	 * 'off' — не откладывать (0).
	 */
	public static function digest_deadline(array $prefs, $event = NULL, $priority = 5)
	{
		$mode = trim((string) Arr::get($prefs, 'digest_mode', '')) ?: (string) self::cfg('digest.default', 'off');

		if ($mode === 'off' OR $mode === '')
		{
			return 0;
		}

		if ((int) $priority <= (int) self::cfg('priority.quiet_skip', 1))
		{
			return 0;   // критичное летит сразу
		}

		if ($event !== NULL AND empty($event['digest']))
		{
			return 0;   // событие не для склейки
		}

		// Меньше — важнее: срочное уходит сразу, склеиваем обычное и фоновое.
		if ((int) $priority < (int) self::cfg('priority.digest_from', 5))
		{
			return 0;
		}

		$tz = trim((string) Arr::get($prefs, 'tz', '')) ?: (string) self::cfg('quiet.tz', 'Europe/Moscow');

		try
		{
			$zone = new DateTimeZone($tz);
		}
		catch (Throwable $e)
		{
			$zone = new DateTimeZone('Europe/Moscow');
		}

		$now = new DateTime('now', $zone);

		if ($mode === 'daily')
		{
			$at   = (int) self::cfg('digest.daily_at', 9);
			$next = clone $now;
			$next->setTime($at, 0, 0);

			if ($next <= $now)
			{
				$next->add(new DateInterval('P1D'));
			}

			return (int) $next->getTimestamp();
		}

		// hourly: начало следующего часа
		$next = clone $now;
		$next->setTime((int) $now->format('G'), (int) self::cfg('digest.hour_at', 0), 0);
		$next->add(new DateInterval('PT1H'));

		return (int) $next->getTimestamp();
	}
}
