<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Движок доставки (prx597).
 *
 * Это то самое «асинхронное ядро», только в пределах монолита: PHP пишет
 * сообщения в outbox (в той же транзакции, что и бизнес-событие), а движок
 * разбирает их отдельным проходом — его дёргает «Пиналка» (prx603, раз в 5
 * минут), администратор кнопкой в /prx597/admin или python-воркер, если он
 * появится: для воркера есть тот же HTTP-эндпоинт /prx597/api/outbox с токеном.
 *
 * Порядок работы с одним сообщением:
 *   1. Захват пачки — FOR UPDATE SKIP LOCKED (два прохода не возьмут одно);
 *   2. Проверка политики: канал жив, привязка активна, событие включено,
 *      пользователь всё ещё подходит по ролям;
 *   3. Тихие часы — отложить до утра;
 *   4. Лимиты — отложить до следующего окна;
 *   5. Рендер шаблона (данные события + подстановки);
 *   6. Отправка каналом;
 *   7. Успех — sent, неудача — attempts+1 и пауза, после лимита попыток — dead
 *      (это наш DLQ: сообщение не пропадает и не крутится вечно).
 *
 * Ни один шаг не бросает исключение наружу: упавший канал — причина повтора,
 * а не сломанный запрос пользователя.
 */
class Service_Prx597_Engine extends Service_Prx597_Base
{
	/** Ключ advisory-блокировки: два прохода одновременно не работают. */
	const LOCK = 5975970;

	// ------------------------------------------------------------------ проход

	/**
	 * Один проход доставки.
	 *
	 * @param  int  $limit сколько сообщений взять
	 * @return array ['taken','sent','failed','deferred','skipped','dead','locked']
	 */
	public static function run($limit = NULL)
	{
		if ( ! self::lock())
		{
			return ['taken' => 0, 'sent' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => 0, 'dead' => 0,
				'locked' => TRUE, 'note' => 'уже выполняется другой проход'];
		}

		try
		{
			// Модуль поднимается и без наката миграций: таблицы создаём на месте,
			// а реестр событий подтягиваем из манифестов не чаще раза в час.
			Service_Prx597_Notify::ensure_tables();

			try
			{
				Service_Prx597_Registry::sync_manifests();
			}
			catch (Throwable $e)
			{
				// Реестр — не доставка: не смогли синхронизировать — работаем
				// на том, что уже знаем.
				self::_log(Log::WARNING, 'синхронизация реестра событий: :e', [':e' => $e->getMessage()]);
			}

			// Застрявшие в sending (процесс умер) возвращаем в работу.
			self::_requeue_stuck();

			// Заодно убираем старые отметки присутствия: строка живёт минуты,
			// а не годы, и «кто на сайте» не должно копиться в базе.
			Service_Prx597_Notify::presence_gc();

			$limit = (int) ($limit === NULL ? self::setting('limits.batch_limit', self::cfg('limits.batch_limit', 50)) : $limit);
			$limit = max(1, min(500, $limit));

			$rows = self::_take_batch($limit);

			$stat = ['taken' => count($rows), 'sent' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => 0, 'dead' => 0, 'locked' => FALSE];

			foreach ($rows as $row)
			{
				$outcome = self::_deliver((array) $row);

				if (isset($stat[$outcome]))
				{
					$stat[$outcome]++;
				}
			}

			return $stat;
		}
		finally
		{
			self::unlock();
		}
	}

	/** Попробовать захватить блокировку прохода. */
	public static function lock()
	{
		try
		{
			$got = self::_val('SELECT pg_try_advisory_lock('.self::LOCK.')', FALSE);
		}
		catch (Throwable $e)
		{
			return FALSE;
		}

		return self::_truthy($got);
	}

	/** Отпустить блокировку. */
	public static function unlock()
	{
		try
		{
			self::_val('SELECT pg_advisory_unlock('.self::LOCK.')', TRUE);
		}
		catch (Throwable $e)
		{
			// сессия закрылась — блокировка снимется сама
		}
	}

	/**
	 * Взять пачку сообщений: FOR UPDATE SKIP LOCKED, чтобы параллельный
	 * воркер не забрал те же строки.
	 */
	protected static function _take_batch($limit)
	{
		try
		{
			return self::_all(
				'SELECT * FROM "prx_597_outbox"
				  WHERE "status" = \'pending\'
				    AND "next_attempt_at" <= now()
				    AND "group_key" IS NULL
				  ORDER BY "priority" ASC, "id" ASC
				  LIMIT '.(int) $limit.'
				  FOR UPDATE SKIP LOCKED');
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'не удалось взять пачку: :e', [':e' => $e->getMessage()]);

			return [];
		}
	}

	/** Сообщения, «зависшие» в sending из-за упавшего процесса. */
	protected static function _requeue_stuck($minutes = 30)
	{
		self::_exec(Database::UPDATE,
			'UPDATE "prx_597_outbox" SET "status" = \'pending\', "last_error" = \'процесс прервался — возвращено в очередь\'
			  WHERE "status" = \'sending\' AND "created_at" < now() - interval \''.(int) $minutes.' minutes\'');
	}

	// ------------------------------------------------------------- одно сообщение

	/**
	 * Доставить одно сообщение.
	 *
	 * @param  array $row строка prx_597_outbox
	 * @return string 'sent' | 'failed' | 'deferred' | 'skipped' | 'dead'
	 */
	protected static function _deliver(array $row)
	{
		$id      = (int) Arr::get($row, 'id', 0);
		$user_id = (int) Arr::get($row, 'user_id', 0);
		$channel = (string) Arr::get($row, 'channel', '');
		$alias   = (string) Arr::get($row, 'event_alias', 'system');

		if ($id <= 0 OR $user_id <= 0 OR $channel === '')
		{
			return 'skipped';
		}

		// Помечаем «в работе» — параллельный проход это сообщение не возьмёт
		// даже если транзакция нашего прохода ещё не закрыта.
		self::_status($id, 'sending');

		$event = Service_Prx597_Registry::event($alias);

		if ($event === NULL)
		{
			$event = Service_Prx597_Registry::ensure($alias, ['title' => (string) Arr::get($row, 'subject', $alias)]);
		}

		$driver = Service_Prx597_Base::channel_driver($channel);

		if ( ! class_exists($driver) OR ! call_user_func([$driver, 'enabled']))
		{
			self::_defer($id, 'Канал '.Service_Prx597_Registry::channel_label($channel).' недоступен', 3600);

			return 'deferred';
		}

		// --- политика: событие включено, пользователь подходит, канал разрешён ---
		$prefs = Service_Prx597_Notify::prefs($user_id);
		$plan  = Service_Prx597_Registry::plan_for($user_id, $event, $prefs);

		if ( ! in_array($channel, $plan, TRUE))
		{
			self::_skip($id, 'Пользователь отключил этот канал или событие');

			return 'skipped';
		}

		// --- привязка канала жива? -------------------------------------------
		$destination = call_user_func([$driver, 'destination'], $user_id);

		if ($destination === '')
		{
			self::_skip($id, 'Нет активной привязки канала: '.Service_Prx597_Registry::channel_label($channel));

			return 'skipped';
		}

		// --- а он это уже видел на сайте? -------------------------------------
		// «Уведомление наружу — просьба о действии, а не отчёт»: если человек
		// прочитал событие в колокольчике, письмо и сообщение в мессенджер ему
		// больше не нужны. Сообщение не теряется — уходит в журнал с причиной.
		$seen = self::_seen_on_site($row);

		if ($seen !== '')
		{
			self::_skip($id, $seen);

			return 'skipped';
		}

		// --- а он сам это сделал и прямо сейчас на сайте? ---------------------
		// Тот же «закон зеркала», но перепроверенный в момент отправки: между
		// постановкой в очередь и отправкой проходят минуты, и человек мог
		// зайти на сайт уже после неё (владелец, 16.10.2026: «запустил обзор,
		// он сделался, я его смотрю — и мне приходит уведомление!»).
		$own = self::_self_and_online($row);

		if ($own !== '')
		{
			self::_skip($id, $own);

			return 'skipped';
		}

		$priority = (int) Arr::get($row, 'priority', 5);

		// --- тихие часы ------------------------------------------------------
		if (Service_Prx597_Limits::quiet_now($prefs, $channel, $priority))
		{
			$wake = Service_Prx597_Limits::next_wakeup($prefs, $channel);
			self::_defer($id, 'Тихие часы — ждём утра', max(60, $wake - time()));

			return 'deferred';
		}

		// --- лимиты -----------------------------------------------------------
		$limit_check = Service_Prx597_Limits::check($user_id, $channel, $alias);

		if (empty($limit_check['ok']))
		{
			self::_defer($id, (string) $limit_check['reason'], max(60, (int) $limit_check['retry_after']));

			return 'deferred';
		}

		// --- рендер и отправка -------------------------------------------------
		$payload = self::_payload($row);

		$message = Service_Prx597_Render::message($event, $channel, (array) Arr::get($payload, 'data', []), [
			'title'   => (string) Arr::get($row, 'subject', ''),
			'body'    => (string) Arr::get($row, 'body', ''),
			'href'    => (string) Arr::get($row, 'href', ''),
			'user_id' => $user_id,
		]);

		$message = array_merge($message, [
			'user_id'     => $user_id,
			'destination' => $destination,
			'href'        => (string) Arr::get($row, 'href', ''),
			'event_alias' => $alias,
			'priority'    => $priority,
			'data'        => (array) Arr::get($payload, 'data', []),
		]);

		try
		{
			$result = call_user_func([$driver, 'send'], $message);
		}
		catch (Throwable $e)
		{
			$result = ['error' => mb_substr($e->getMessage(), 0, 300)];
		}

		$attempt = (int) Arr::get($row, 'attempts', 0) + 1;

		if ( ! empty($result['ok']))
		{
			self::_status($id, 'sent', [
				'attempts'    => $attempt,
				'last_error'  => NULL,
				'destination' => $destination,
			]);
			self::_log_row($row, $attempt, TRUE, 'ok', (string) Arr::get($result, 'id', ''));

			return 'sent';
		}

		return self::_failed_delivery($row, $result);
	}

	/**
	 * Прочитано или убрано в архив на сайте? Тогда наружу не шлём.
	 *
	 * Возвращает причину для журнала или пустую строку. Проверка дешёвая
	 * (одна строка по ключу) и стоит перед тихими часами и лимитами: не
	 * тратим ни окно отправки, ни ведро счётчика на то, что человек уже видел.
	 */
	protected static function _seen_on_site(array $row)
	{
		$inbox_id = (int) Arr::get($row, 'inbox_id', 0);
		$channel  = trim((string) Arr::get($row, 'channel', ''));

		if ($inbox_id <= 0 OR $channel === '' OR $channel === 'in_app')
		{
			return '';
		}

		if ( ! self::_truthy(self::cfg('delivery.skip_when_read', TRUE)))
		{
			return '';
		}

		try
		{
			$inbox = self::_one('SELECT "read_at", "archived_at" FROM "prx_597_notifications"
			                      WHERE "id" = '.$inbox_id);
		}
		catch (Throwable $e)
		{
			// Таблицы нет или база недоступна — не мешаем доставке.
			return '';
		}

		if ( ! $inbox)
		{
			return '';
		}

		if ( ! empty($inbox['read_at']))
		{
			return 'Прочитано на сайте — наружу не шлём';
		}

		if ( ! empty($inbox['archived_at'])
			AND self::_truthy(self::cfg('delivery.skip_when_archived', TRUE)))
		{
			return 'Убрано в архив на сайте — наружу не шлём';
		}

		return '';
	}

	/**
	 * «Своё» событие, а человек уже на сайте? Наружу не шлём.
	 *
	 * Признак едет в строке очереди (`payload.self`): его ставит Notify::emit
	 * для успешного итога, который человек сам и заказал — готовый обзор,
	 * собранная модель, выполненная его задача. В момент постановки в очередь
	 * человека на сайте могло не быть, а к моменту отправки он уже смотрит
	 * результат: письмо становится отчётом о том, что он видит сам.
	 *
	 * Решение принимается здесь, а не только при постановке, потому что
	 * очередь живёт минутами (сводка — до суток). Сбой и чужое событие
	 * признака не имеют и проверку не проходят: их будим всегда.
	 *
	 * Возвращает причину для журнала или пустую строку. Правило выключается
	 * настройкой presence.skip_self.
	 */
	protected static function _self_and_online(array $row)
	{
		if ( ! self::_truthy(self::setting('presence.skip_self', self::cfg('presence.skip_self', TRUE))))
		{
			return '';
		}

		$payload = self::_payload($row);

		if ( ! self::_truthy(Arr::get($payload, 'self', FALSE)))
		{
			return '';
		}

		$user_id = (int) Arr::get($row, 'user_id', 0);

		if ($user_id <= 0 OR ! Service_Prx597_Notify::seen_recently($user_id))
		{
			return '';
		}

		return 'Своё событие, вы на сайте — наружу не шлём';
	}

	/** Одинаковые повторы, блокировки бота и DLQ для сообщений и сводок. */
	protected static function _failed_delivery(array $row, array $result)
	{
		$id = (int) $row['id'];
		$user_id = (int) $row['user_id'];
		$channel = (string) $row['channel'];
		$attempt = (int) Arr::get($row, 'attempts', 0) + 1;
		$error = (string) Arr::get($result, 'error', 'неизвестная ошибка канала');

		// Пользователь заблокировал бота — привязку снимаем, иначе канал будет
		// долбить мёртвый чат до конца лимита попыток.
		if ( ! empty($result['blocked']))
		{
			Service_Prx597_Bind::unbind($user_id, $channel);
			self::_skip($id, $error);

			return 'skipped';
		}

		self::_log_row($row, $attempt, FALSE, 'error', $error);

		$max_attempts = (int) self::setting('limits.max_attempts', self::cfg('limits.max_attempts', 5));

		if ($attempt >= max(1, $max_attempts))
		{
			self::_status($id, 'dead', ['attempts' => $attempt, 'last_error' => self::_cut($error, 300)]);

			return 'dead';
		}

		self::_fail($id, $attempt, $error);

		return 'failed';
	}

	// ------------------------------------------------------------------ склейка

	/**
	 * Разослать дайджесты: все отложенные сообщения одной группы склеиваются
	 * в одно письмо (одно сообщение в мессенджер).
	 *
	 * Группа задаётся при постановке в outbox: group_key = 'digest:<channel>:<deadline>'.
	 *
	 * @return array ['groups','sent','failed','skipped','deferred','items']
	 */
	public static function flush_digests($limit = 50)
	{
		if ( ! self::lock())
		{
			return ['groups' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'deferred' => 0, 'items' => 0, 'locked' => TRUE];
		}

		try
		{
			$rows = self::_all(
				'SELECT * FROM "prx_597_outbox"
				  WHERE "status" = \'pending\'
				    AND "next_attempt_at" <= now()
				    AND "group_key" IS NOT NULL
				    AND "group_key" LIKE \'digest:%\'
				  ORDER BY "id" ASC
				  LIMIT '.(int) max(1, min(500, (int) $limit)).'
				  FOR UPDATE SKIP LOCKED');

			$groups = self::_digest_groups($rows);

			$stat = ['groups' => count($groups), 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'deferred' => 0, 'items' => count($rows)];

			foreach ($groups as $key => $items)
			{
				$outcome = self::_send_digest((string) $key, $items);

				if (isset($stat[$outcome]))
				{
					$stat[$outcome]++;
				}
				else
				{
					$stat['failed']++;
				}
			}

			return $stat;
		}
		finally
		{
			self::unlock();
		}
	}

	/** group_key исторически общий: никогда не склеиваем разных получателей. */
	protected static function _digest_groups(array $rows)
	{
		$groups = [];
		foreach ($rows as $row)
		{
			$key = json_encode([(int) $row['user_id'], (string) $row['channel'], (string) $row['group_key']]);
			$groups[$key][] = (array) $row;
		}
		return $groups;
	}

	/** Собрать и отправить одну сводку. */
	protected static function _send_digest($group_key, array $items)
	{
		$first = reset($items);

		if ( ! is_array($first))
		{
			return 'skipped';
		}

		$channel = (string) Arr::get($first, 'channel', '');
		$user_id = (int) Arr::get($first, 'user_id', 0);

		if ($channel === '' OR $user_id <= 0)
		{
			self::_skip((int) Arr::get($first, 'id', 0), 'Сводка без канала или получателя');

			return 'skipped';
		}

		$driver = Service_Prx597_Base::channel_driver($channel);

		if ( ! class_exists($driver) OR ! call_user_func([$driver, 'enabled']))
		{
			foreach ($items as $item)
			{
				self::_defer((int) $item['id'], 'Канал недоступен', 1800);
			}

			return 'deferred';
		}

		$prefs = Service_Prx597_Notify::prefs($user_id);
		$allowed = [];
		foreach ($items as $item)
		{
			if ((int) $item['user_id'] !== $user_id OR (string) $item['channel'] !== $channel)
			{
				self::_log(Log::ERROR, 'отказ отправки сводки: смешаны получатели или каналы');
				return 'failed';
			}
			$event = Service_Prx597_Registry::event((string) $item['event_alias']);
			if ($event === NULL OR ! in_array($channel, Service_Prx597_Registry::plan_for($user_id, $event, $prefs), TRUE))
			{
				self::_skip((int) $item['id'], 'Событие или канал больше недоступны пользователю');
				continue;
			}

			// Из сводки тоже выкидываем то, что человек уже прочитал на сайте:
			// иначе письмо «раз в сутки» придёт ради новостей, которые он видел.
			$seen = self::_seen_on_site($item);

			if ($seen !== '')
			{
				self::_skip((int) $item['id'], $seen);
				continue;
			}

			// И то же для «своего» события: раз человек на сайте, он смотрит
			// результат сам — присылать его сводкой позже нечего.
			$own = self::_self_and_online($item);

			if ($own !== '')
			{
				self::_skip((int) $item['id'], $own);
				continue;
			}

			$allowed[] = $item;
		}
		$items = $allowed;
		if ( ! $items) { return 'skipped'; }

		$destination = call_user_func([$driver, 'destination'], $user_id);

		if ($destination === '')
		{
			foreach ($items as $item)
			{
				self::_skip((int) $item['id'], 'Нет активной привязки канала');
			}

			return 'skipped';
		}

		if (Service_Prx597_Limits::quiet_now($prefs, $channel, 5))
		{
			$wake = Service_Prx597_Limits::next_wakeup($prefs, $channel);

			foreach ($items as $item)
			{
				self::_defer((int) $item['id'], 'Тихие часы — сводка ждёт утра', max(60, $wake - time()));
			}

			return 'deferred';
		}

		$limit_check = Service_Prx597_Limits::check($user_id, $channel, '');

		if (empty($limit_check['ok']))
		{
			foreach ($items as $item)
			{
				self::_defer((int) $item['id'], (string) $limit_check['reason'], max(60, (int) $limit_check['retry_after']));
			}

			return 'deferred';
		}

		// --- «одно письмо в сутки»: больше сводок за день не отправляем ------
		// Не теряем: переносим на следующее суточное окно. Иначе десять
		// заказов за вечер превратятся в десять писем «раз в сутки».
		$per_day = (int) self::cfg('digest.per_day', 1);

		if ($per_day > 0)
		{
			$sent_today = (int) self::_val(
				'SELECT count(*) AS c FROM "prx_597_outbox"
				  WHERE "user_id" = '.$user_id.'
				    AND "channel" = '.self::_q($channel).'
				    AND "status" = \'sent\'
				    AND "group_key" LIKE \'digest:%\'
				    AND "sent_at" >= date_trunc(\'day\', now())', 0);

			if ($sent_today >= $per_day)
			{
				$left = 86400 - ((int) gmdate('H') * 3600 + (int) gmdate('i') * 60 + (int) gmdate('s'));

				foreach ($items as $item)
				{
					self::_defer((int) $item['id'], 'Сводка за сутки уже отправлена — ждём следующего окна', max(600, $left));
				}

				return 'deferred';
			}
		}

		$max_items = (int) self::cfg('digest.max_items', 20);
		$shown     = array_slice($items, 0, $max_items);
		$extra     = max(0, count($items) - count($shown));

		$subject = 'Сводка уведомлений ('.count($items).')';

		// Одинаковые события — одна строка: «Обзор готов: X (и ещё 3)».
		// Иначе сводка из двадцати однотипных строк читается как спам.
		$lines = [];
		$order = [];

		foreach ($shown as $item)
		{
			$title = trim((string) $item['subject']);

			if ($title === '')
			{
				$title = trim((string) $item['body']);
			}

			$text = trim((string) $item['body']);

			if ($text === $title)
			{
				$text = '';
			}

			$key = (string) $item['event_alias'].'|'.$title;

			if (isset($lines[$key]))
			{
				$lines[$key]['count']++;
				continue;
			}

			$lines[$key] = ['title' => $title, 'text' => $text, 'count' => 1];
			$order[] = $key;
		}

		$body = '';

		foreach ($order as $key)
		{
			$line  = $lines[$key];
			$body .= '• '.$line['title'].($line['count'] > 1 ? ' (и ещё '.($line['count'] - 1).')' : '')."\n";

			if ($line['text'] !== '')
			{
				$body .= '  '.preg_replace('~\s+~u', ' ', $line['text'])."\n";
			}
		}

		if ($extra > 0)
		{
			$body .= "\n".'И ещё уведомлений: '.$extra;
		}

		$body .= "\n\n".'Все уведомления: '.self::base_url().'/prx597';

		try
		{
			$result = call_user_func([$driver, 'send'], [
				'user_id'     => $user_id,
				'destination' => $destination,
				'subject'     => $subject,
				'body'        => trim($body),
				'format'      => 'text',
				'href'        => '/prx597',
				'event_alias' => 'digest',
				'priority'    => 5,
			]);
		}
		catch (Throwable $e)
		{
			$result = ['error' => mb_substr($e->getMessage(), 0, 300)];
		}

		$ids = [];

		foreach ($items as $item)
		{
			$ids[] = (int) $item['id'];
		}

		if ( ! empty($result['ok']))
		{
			self::_batch_status($ids, 'sent', NULL);

			foreach ($items as $item)
			{
				self::_log_row((array) $item, (int) Arr::get($item, 'attempts', 0) + 1, TRUE, 'ok', 'digest');
			}

			return 'sent';
		}
		foreach ($items as $item)
		{
			self::_failed_delivery((array) $item, $result);
		}

		return 'failed';
	}

	// --------------------------------------------------------------- состояние

	/** Успех/провал/пропуск: смена статуса строки outbox. */
	protected static function _status($id, $status, array $extra = [])
	{
		$db = self::_db();
		$set = ['"status" = '.$db->quote($status)];

		if ($status === 'sent')
		{
			$set[] = '"sent_at" = now()';
		}

		foreach ($extra as $column => $value)
		{
			$set[] = '"'.$column.'" = '.($value === NULL ? 'NULL' : (is_int($value) ? (string) $value : $db->quote((string) $value)));
		}

		self::_exec(Database::UPDATE,
			'UPDATE "prx_597_outbox" SET '.implode(', ', $set).' WHERE "id" = '.((int) $id));
	}

	/** Отложить: вернуть в pending с новой попыткой через $delay секунд. */
	protected static function _defer($id, $reason, $delay = 600)
	{
		$db = self::_db();

		self::_exec(Database::UPDATE,
			'UPDATE "prx_597_outbox" SET "status" = \'pending\',
			     "last_error" = '.$db->quote(self::_cut($reason, 300)).',
			     "next_attempt_at" = now() + interval \''.max(30, (int) $delay).' seconds\'
			 WHERE "id" = '.((int) $id));
	}

	/** Пропустить навсегда (причину пишем в журнал и в строку). */
	protected static function _skip($id, $reason)
	{
		$db = self::_db();

		self::_exec(Database::UPDATE,
			'UPDATE "prx_597_outbox" SET "status" = \'skipped\',
			     "last_error" = '.$db->quote(self::_cut($reason, 300)).'
			 WHERE "id" = '.((int) $id));

		$row = self::_one('SELECT * FROM "prx_597_outbox" WHERE "id" = '.((int) $id));

		if ($row)
		{
			self::_log_row($row, (int) Arr::get($row, 'attempts', 0), FALSE, 'skipped', $reason);
		}
	}

	/** Неудача с паузой: экспоненциальная отсрочка из конфига. */
	protected static function _fail($id, $attempt, $error)
	{
		$backoff = (array) self::cfg('limits.backoff', [60, 300, 1800, 7200]);
		$index   = max(0, min(count($backoff) - 1, $attempt - 1));
		$delay   = (int) Arr::get($backoff, $index, 3600);

		$db = self::_db();

		self::_exec(Database::UPDATE,
			'UPDATE "prx_597_outbox" SET "status" = \'pending\',
			     "attempts" = '.(int) $attempt.',
			     "last_error" = '.$db->quote(self::_cut($error, 300)).',
			     "next_attempt_at" = now() + interval \''.$delay.' seconds\'
			 WHERE "id" = '.((int) $id));
	}

	/** Массовая смена статуса (сводка). */
	protected static function _batch_status(array $ids, $status, $error = NULL)
	{
		if ( ! $ids)
		{
			return;
		}

		$db  = self::_db();
		$ids = implode(',', array_map('intval', $ids));

		self::_exec(Database::UPDATE,
			'UPDATE "prx_597_outbox" SET "status" = '.$db->quote($status)
			.', "last_error" = '.($error === NULL ? 'NULL' : $db->quote(self::_cut($error, 300)))
			.($status === 'sent' ? ', "sent_at" = now(), "attempts" = "attempts" + 1' : '')
			.' WHERE "id" IN ('.$ids.')');
	}

	/** JSON-поле payload строки outbox. */
	protected static function _payload(array $row)
	{
		$raw = Arr::get($row, 'payload', NULL);

		if (is_array($raw))
		{
			return $raw;
		}

		$data = json_decode((string) $raw, TRUE);

		return is_array($data) ? $data : [];
	}

	// ------------------------------------------------------------------- журнал

	/** Строка журнала доставки. */
	protected static function _log_row(array $row, $attempt, $ok, $code, $message)
	{
		$db = self::_db();

		try
		{
			$db->query(Database::INSERT,
				'INSERT INTO "prx_597_log" ("outbox_id", "user_id", "channel", "attempt", "ok", "response_code", "message", "created_at")
				 VALUES ('.((int) Arr::get($row, 'id', 0)).', '.((int) Arr::get($row, 'user_id', 0)).',
				         '.$db->quote(self::_cut((string) Arr::get($row, 'channel', ''), 20)).',
				         '.((int) $attempt).', '.($ok ? 'TRUE' : 'FALSE').',
				         '.$db->quote(self::_cut((string) $code, 20)).',
				         '.$db->quote(self::_cut((string) $message, 2000)).', now())', FALSE);
		}
		catch (Throwable $e)
		{
			// Журнал — не главное: потеря записи не должна ронять доставку.
		}
	}

	/** Журнал доставки для админки. */
	public static function journal($limit = 100, array $filter = [])
	{
		$where = [];

		$channel = trim((string) Arr::get($filter, 'channel', ''));

		if ($channel !== '')
		{
			$where[] = 'l."channel" = '.self::_q($channel);
		}

		$status = trim((string) Arr::get($filter, 'status', ''));

		if ($status !== '')
		{
			$where[] = 'l."ok" = '.($status === 'ok' ? 'TRUE' : 'FALSE');
		}

		$event = trim((string) Arr::get($filter, 'event', ''));

		if ($event !== '')
		{
			$where[] = 'o."event_alias" = '.self::_q($event);
		}

		// Код события живёт в очереди, а не в журнале: джойним, чтобы в
		// журнале было видно, что именно не дошло, а не только «канал упал».
		$sql = 'SELECT l.*, o."event_alias" FROM "prx_597_log" l
		         LEFT JOIN "prx_597_outbox" o ON o."id" = l."outbox_id"'
			.($where ? ' WHERE '.implode(' AND ', $where) : '')
			.' ORDER BY l."id" DESC LIMIT '.(int) max(1, min(500, (int) $limit));

		try
		{
			return self::_all($sql);
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	// ------------------------------------------------------- управление: очередь

	/**
	 * Очередь доставки для страницы управления: что ждёт отправки и почему.
	 *
	 * Причина ожидания уже лежит в строке: движок пишет её в `last_error`,
	 * когда откладывает сообщение (тихие часы, лимит, недоступный канал).
	 * Отдельно помечаем склейку в сводку (`group_key`) — такие строки движок
	 * специально не берёт в обычный проход.
	 *
	 * @param  int   $limit  сколько строк показать
	 * @param  array $status какие статусы считаем «в очереди»
	 * @return array строки с полем reason — словами, почему ждёт
	 */
	public static function queue($limit = 50, array $status = ['pending', 'sending'])
	{
		$status = array_values(array_filter(array_map('strval', $status)));
		$list   = [];

		foreach ($status as $one)
		{
			$list[] = self::_q($one);
		}

		$sql = 'SELECT * FROM "prx_597_outbox"
		          WHERE "status" IN ('.implode(', ', $list).')
		          ORDER BY "next_attempt_at" ASC, "id" ASC
		          LIMIT '.(int) max(1, min(200, (int) $limit));

		try
		{
			$rows = self::_all($sql);
		}
		catch (Throwable $e)
		{
			return [];
		}

		foreach ($rows as $i => $row)
		{
			$rows[$i]['reason'] = self::waiting_reason((array) $row);
		}

		return $rows;
	}

	/** Почему сообщение ещё не ушло — словами для человека. */
	public static function waiting_reason(array $row)
	{
		$error = trim((string) Arr::get($row, 'last_error', ''));

		if ($error !== '')
		{
			return $error;
		}

		if (trim((string) Arr::get($row, 'group_key', '')) !== '')
		{
			return 'Ждёт сводку: склеится с другими сообщениями этой группы';
		}

		$status = (string) Arr::get($row, 'status', 'pending');

		if ($status === 'sending')
		{
			return 'Взято движком: доставка идёт прямо сейчас';
		}

		$attempts = (int) Arr::get($row, 'attempts', 0);

		if ($attempts > 0)
		{
			return 'Повтор после ошибки (попытка '.$attempts.')';
		}

		return 'Ещё не время попытки: движок возьмёт сообщение сам';
	}

	/**
	 * Что реально ушло по каналам за последние часы (журнал доставки).
	 * Это и есть «отправлено»: колокольчик-рассылка пишется в ленту и сюда не
	 * попадает — её видно в recent_inbox().
	 */
	public static function recent_sent($limit = 50, $hours = 24)
	{
		$sql = 'SELECT l.*, o."event_alias", o."subject" FROM "prx_597_log" l
		          LEFT JOIN "prx_597_outbox" o ON o."id" = l."outbox_id"
		          WHERE l."ok" = TRUE AND l."created_at" > now() - interval \''.(int) max(1, $hours).' hours\'
		          ORDER BY l."id" DESC LIMIT '.(int) max(1, min(200, (int) $limit));

		try
		{
			return self::_all($sql);
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	/**
	 * Что легло в ленту (колокольчик) за последние часы: сюда попадают и
	 * модульные события, и «объявление всем», и адресное сообщение от системы.
	 * Без этого списка вопрос «я отправлял рассылку — где посмотреть?» не имеет
	 * ответа: в очереди доставки таких строк нет.
	 */
	public static function recent_inbox($limit = 50, $hours = 24)
	{
		$sql = 'SELECT * FROM "prx_597_notifications"
		          WHERE "created_at" > now() - interval \''.(int) max(1, $hours).' hours\'
		          ORDER BY "id" DESC LIMIT '.(int) max(1, min(200, (int) $limit));

		try
		{
			return self::_all($sql);
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	/** Подпись получателя: имя и адрес, если пользователь ещё есть в базе. */
	public static function user_label($user_id)
	{
		$user_id = (int) $user_id;

		if ($user_id <= 0)
		{
			return '—';
		}

		try
		{
			$user = ORM::factory('User', $user_id);

			if ($user->loaded())
			{
				$name = '';

				try
				{
					$profile = $user->profile;

					if ($profile AND $profile->loaded() AND trim((string) $profile->full_name) !== '')
					{
						$name = trim((string) $profile->full_name);
					}
				}
				catch (Throwable $e)
				{
					$name = '';
				}

				if ($name === '')
				{
					try { $name = trim((string) $user->username); } catch (Throwable $e) { $name = ''; }
				}

				$email = '';

				try { $email = trim((string) $user->email); } catch (Throwable $e) { $email = ''; }

				$label = trim($name.($email !== '' ? ' <'.$email.'>' : ''));

				return $label !== '' ? $label : '#'.$user_id;
			}
		}
		catch (Throwable $e)
		{
			// пользователя нет — показываем хотя бы номер
		}

		return '#'.$user_id;
	}

	// -------------------------------------------------------------------- отчёты

	/** KPI для страницы управления. */
	public static function stats()
	{
		$out = [
			'outbox'   => [],
			'inbox'    => 0,
			'unread'   => 0,
			'users'    => 0,
			'bindings' => [],
			'bots'     => [],
		];

		try
		{
			$rows = self::_all('SELECT "status", count(*) AS c FROM "prx_597_outbox" GROUP BY "status"');

			foreach ($rows as $row)
			{
				$out['outbox'][(string) $row['status']] = (int) $row['c'];
			}
		}
		catch (Throwable $e)
		{
			$out['outbox'] = [];
		}

		try
		{
			$out['inbox']  = (int) self::_val('SELECT count(*) AS c FROM "prx_597_notifications"', 0);
			$out['unread'] = (int) self::_val('SELECT COALESCE(sum("count"), 0) AS c FROM "prx_597_unread"', 0);
			$out['users']  = (int) self::_val('SELECT count(*) AS c FROM "prx_597_prefs"', 0);

			$binds = self::_all('SELECT "channel", "status", count(*) AS c FROM "prx_597_channels" GROUP BY "channel", "status"');

			foreach ($binds as $row)
			{
				$out['bindings'][(string) $row['channel']][(string) $row['status']] = (int) $row['c'];
			}
		}
		catch (Throwable $e)
		{
			// нет таблиц — нет статистики
		}

		try
		{
			$out['bots'] = self::_all('SELECT * FROM "prx_597_bots" ORDER BY "channel"');
		}
		catch (Throwable $e)
		{
			$out['bots'] = [];
		}

		// Сколько ушло за сутки — по журналу доставки (каналы: почта, боты).
		// Внутренняя рассылка и адресные сообщения идут прямо в ленту, поэтому
		// считаем их отдельно (inbox_24h): иначе «отправлено за сутки» честно
		// показывает ноль после успешной рассылки.
		try
		{
			$out['sent_24h'] = (int) self::_val(
				'SELECT count(*) AS c FROM "prx_597_log" WHERE "ok" = TRUE AND "created_at" > now() - interval \'24 hours\'', 0);
			$out['failed_24h'] = (int) self::_val(
				'SELECT count(*) AS c FROM "prx_597_log" WHERE "ok" = FALSE AND "created_at" > now() - interval \'24 hours\'', 0);
			$out['inbox_24h'] = (int) self::_val(
				'SELECT count(*) AS c FROM "prx_597_notifications" WHERE "created_at" > now() - interval \'24 hours\'', 0);
		}
		catch (Throwable $e)
		{
			$out['sent_24h'] = 0;
			$out['failed_24h'] = 0;
			$out['inbox_24h'] = 0;
		}

		// Плоские ключи для карточек в управлении: чтобы вид не считал сам.
		$out['bindings_by']      = $out['bindings'];
		$out['bindings']         = 0;
		$out['bindings_active']  = 0;

		foreach ($out['bindings_by'] as $statuses)
		{
			foreach ($statuses as $status => $count)
			{
				$out['bindings'] += (int) $count;

				if ($status === 'active')
				{
					$out['bindings_active'] += (int) $count;
				}
			}
		}

		$out['pending'] = (int) Arr::get($out['outbox'], 'pending', 0)
		                + (int) Arr::get($out['outbox'], 'sending', 0);
		$out['dead']    = (int) Arr::get($out['outbox'], 'dead', 0);
		$out['failed']  = (int) Arr::get($out['outbox'], 'failed', 0);

		// «Отложено» — это ещё не наступившее время следующей попытки:
		// пауза из-за лимитов, тихих часов или ожидания сводки.
		try
		{
			$out['deferred'] = (int) self::_val(
				'SELECT count(*) AS c FROM "prx_597_outbox"
				  WHERE "status" IN (\'pending\', \'failed\') AND "next_attempt_at" > now()', 0);
		}
		catch (Throwable $e)
		{
			$out['deferred'] = 0;
		}

		$out['sent_today'] = (int) Arr::get($out, 'sent_24h', 0);

		return $out;
	}

	/** очередь «мертвых» сообщений (DLQ) для админки. */
	public static function dead($limit = 50)
	{
		try
		{
			return self::_all('SELECT * FROM "prx_597_outbox" WHERE "status" IN (\'dead\', \'failed\')
			                    ORDER BY "id" DESC LIMIT '.(int) max(1, min(200, (int) $limit)));
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	/** Вернуть DLQ в работу (кнопка администратора). */
	public static function retry_dead($limit = 50)
	{
		$db = self::_db();

		$db->query(Database::UPDATE,
			'UPDATE "prx_597_outbox" SET "status" = \'pending\', "attempts" = 0, "next_attempt_at" = now()
			  WHERE "id" IN (SELECT "id" FROM "prx_597_outbox" WHERE "status" IN (\'dead\', \'failed\') ORDER BY "id" DESC LIMIT '
			.((int) max(1, min(500, (int) $limit))).')', FALSE);

		return TRUE;
	}

	// ------------------------------------------------------------------- чистка

	/**
	 * Очистка: удаляем старые строки. Администратор жмёт кнопку в управлении,
	 * сроки берём из настроек (умолчания — из конфига).
	 */
	/**
	 * Убрать из очереди НЕотправленное (кнопка администратора).
	 *
	 * В очереди копится не только то, что вот-вот уйдёт: письма людям без
	 * привязки, сообщения выключенного канала, фоновые события модулей, которые
	 * никто не просил. Само такое не исчезнет: плановая «Очистка» снимает
	 * только resolved-строки старше срока хранения (60 дней), а ожидающие
	 * живут вечно. Поэтому у администратора есть ручная уборка.
	 *
	 * Отправленное («sent») не трогаем никогда: это история доставки, её
	 * снимает «Очистка» по возрасту.
	 *
	 * @param  string $scope waiting — то, что ещё ждёт отправки (pending, sending);
	 *                       failed  — то, что уже не уйдёт (failed, dead);
	 *                       all     — и то, и другое.
	 * @return array ['waiting' => int, 'failed' => int, 'total' => int, 'unmarked' => int]
	 */
	public static function purge_unsent($scope = 'all')
	{
		$scope = (string) $scope;

		// Незнакомое значение — это не «убери всё», а «ничего не делаем»:
		// кнопка присылает ровно два понятных значения, остальное — чужая
		// правка формы, и угадывать за неё опаснее, чем промолчать.
		if ( ! in_array($scope, ['waiting', 'failed', 'all'], TRUE))
		{
			self::_log(Log::WARNING, 'очередь: уборка неотправленного с непонятной областью «:s»', [':s' => $scope]);

			return ['waiting' => 0, 'failed' => 0, 'total' => 0, 'unmarked' => 0, 'scope' => 'unknown'];
		}

		$groups = [];

		if ($scope === 'waiting' OR $scope === 'all')
		{
			$groups['waiting'] = ['pending', 'sending'];
		}

		if ($scope === 'failed' OR $scope === 'all')
		{
			$groups['failed'] = ['failed', 'dead'];
		}

		$out = ['waiting' => 0, 'failed' => 0, 'total' => 0, 'unmarked' => 0, 'scope' => $scope];

		foreach ($groups as $key => $statuses)
		{
			$in = implode(', ', array_map(function ($one) { return self::_q($one); }, $statuses));

			try
			{
				// Сначала запоминаем строки колокольчика: они помечены
				// «email_status = pending», и без письма метка осталась бы
				// висеть в ленте навсегда.
				$ids = self::_all('SELECT DISTINCT "inbox_id" FROM "prx_597_outbox"
				                    WHERE "status" IN ('.$in.') AND "inbox_id" IS NOT NULL');

				$out[$key] = (int) self::_val(
					'WITH d AS (DELETE FROM "prx_597_outbox" WHERE "status" IN ('.$in.') RETURNING 1)
					 SELECT count(*) AS c FROM d', 0);

				if ($ids)
				{
					$list = implode(', ', array_map(function ($row) { return (int) $row['inbox_id']; }, $ids));

					$out['unmarked'] += (int) self::_val(
						'WITH u AS (UPDATE "prx_597_notifications"
						              SET "email_status" = \'none\', "email_error" = NULL
						            WHERE "id" IN ('.$list.') AND "email_status" <> \'sent\' RETURNING 1)
						 SELECT count(*) AS c FROM u', 0);
				}
			}
			catch (Throwable $e)
			{
				self::_log(Log::ERROR, 'очередь: уборка неотправленного сорвалась: :e', [':e' => $e->getMessage()]);
			}
		}

		$out['total'] = $out['waiting'] + $out['failed'];

		if ($out['total'] > 0)
		{
			self::_log(Log::INFO, 'очередь: администратор убрал неотправленное — ждало :w, не доставлено :f',
				[':w' => $out['waiting'], ':f' => $out['failed']]);
		}

		return $out;
	}

	/**
	 * Какие события наполняют ленту: код, сколько записей и когда последняя.
	 * Нужно управлению, чтобы мусорное событие можно было узнать в лицо и убрать.
	 *
	 * @param  int $days  окно наблюдения
	 * @param  int $limit сколько строк показать
	 * @return array [['event_alias' => '…', 'c' => 12, 'last_at' => '…'], …]
	 */
	public static function feed_events($days = 7, $limit = 12)
	{
		$sql = 'SELECT "event_alias", count(*) AS "c",
		               count(*) FILTER (WHERE "created_at" > now() - interval \'1 hour\') AS "c_hour",
		               max("created_at") AS "last_at"
		          FROM "prx_597_notifications"
		         WHERE "created_at" > now() - interval \''.(int) max(1, (int) $days).' days\'
		         GROUP BY "event_alias"
		         ORDER BY count(*) DESC, "event_alias" ASC
		         LIMIT '.(int) max(1, min(50, (int) $limit));

		try
		{
			return self::_all($sql);
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	/**
	 * Убрать из ленты все записи одного события (у всех получателей).
	 *
	 * Это уборка мусора, а не «отключить событие»: чтобы оно не приходило
	 * снова, его выключают на вкладке «События». Очередь доставки не трогаем —
	 * у неё своя кнопка.
	 *
	 * @param  string $alias код события
	 * @return array ['ok' => bool, 'deleted' => int, 'users' => int, 'error' => string]
	 */
	public static function purge_feed_event($alias)
	{
		$alias = self::_cut(trim((string) $alias), 120);

		if ($alias === '')
		{
			return ['ok' => FALSE, 'deleted' => 0, 'users' => 0, 'error' => 'Не указано событие'];
		}

		try
		{
			// Кого затронули — считаем ДО удаления: счётчик непрочитанных ведёт
			// триггер, но после массовой уборки его стоит сверить.
			$users = array_slice(self::_all('SELECT DISTINCT "user_id" FROM "prx_597_notifications"
			                                  WHERE "event_alias" = '.self::_q($alias).' LIMIT 500'), 0, 500);

			$row = self::_one('WITH d AS (DELETE FROM "prx_597_notifications"
			                     WHERE "event_alias" = '.self::_q($alias).' RETURNING "user_id")
			                   SELECT count(*) AS "c", count(DISTINCT "user_id") AS "users" FROM d');
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'лента: уборка события :a сорвалась: :e', [':a' => $alias, ':e' => $e->getMessage()]);

			return ['ok' => FALSE, 'deleted' => 0, 'users' => 0, 'error' => 'Уборка не удалась'];
		}

		$deleted = (int) Arr::get((array) $row, 'c', 0);
		$touched = (int) Arr::get((array) $row, 'users', 0);

		if ($deleted > 0 AND $users)
		{
			$list = implode(', ', array_map(function ($one) { return (int) $one['user_id']; }, $users));

			try
			{
				self::_exec(Database::INSERT,
					'INSERT INTO "prx_597_unread" ("user_id", "count", "updated_at")
					 SELECT n."user_id", count(*), now() FROM "prx_597_notifications" n
					  WHERE n."user_id" IN ('.$list.') AND n."read_at" IS NULL AND n."archived_at" IS NULL
					  GROUP BY n."user_id"
					 ON CONFLICT ("user_id") DO UPDATE
					     SET "count" = EXCLUDED."count", "updated_at" = now()');

				self::_exec(Database::UPDATE,
					'UPDATE "prx_597_unread" SET "count" = 0, "updated_at" = now()
					  WHERE "user_id" IN ('.$list.') AND "count" <> 0
					    AND NOT EXISTS (SELECT 1 FROM "prx_597_notifications" n
					                     WHERE n."user_id" = "prx_597_unread"."user_id"
					                       AND n."read_at" IS NULL AND n."archived_at" IS NULL)');
			}
			catch (Throwable $e)
			{
				self::_log(Log::WARNING, 'лента: счётчики непрочитанного не сверены: :e', [':e' => $e->getMessage()]);
			}
		}

		if ($deleted > 0)
		{
			self::_log(Log::INFO, 'лента: администратор убрал записи события :a — :n у :u получателей',
				[':a' => $alias, ':n' => $deleted, ':u' => $touched]);
		}

		return ['ok' => TRUE, 'deleted' => $deleted, 'users' => $touched, 'error' => ''];
	}

	public static function cleanup(array $days = [])
	{
		$inbox  = (int) Arr::get($days, 'inbox', self::setting('cleanup.inbox_days', self::cfg('cleanup.inbox_days', 180)));
		$outbox = (int) Arr::get($days, 'outbox', self::setting('cleanup.outbox_days', self::cfg('cleanup.outbox_days', 60)));
		$log    = (int) Arr::get($days, 'log', self::setting('cleanup.log_days', self::cfg('cleanup.log_days', 60)));

		$result = ['inbox' => 0, 'outbox' => 0, 'log' => 0, 'rate' => 0];

		try
		{
			$result['inbox'] = (int) self::_val(
				'WITH d AS (DELETE FROM "prx_597_notifications" WHERE "created_at" < now() - interval \''.$inbox.' days\' RETURNING 1)
				 SELECT count(*) AS c FROM d', 0);

			$result['outbox'] = (int) self::_val(
				'WITH d AS (DELETE FROM "prx_597_outbox" WHERE "status" IN (\'sent\', \'skipped\', \'dead\')
				     AND "created_at" < now() - interval \''.$outbox.' days\' RETURNING 1)
				 SELECT count(*) AS c FROM d', 0);

			$result['log'] = (int) self::_val(
				'WITH d AS (DELETE FROM "prx_597_log" WHERE "created_at" < now() - interval \''.$log.' days\' RETURNING 1)
				 SELECT count(*) AS c FROM d', 0);

			// Вёдра лимитов живут максимум сутки — это скользящие счётчики.
			$result['rate'] = (int) self::_val(
				'WITH d AS (DELETE FROM "prx_597_rate" WHERE "updated_at" < now() - interval \'2 days\' RETURNING 1)
				 SELECT count(*) AS c FROM d', 0);
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'очистка: :e', [':e' => $e->getMessage()]);
		}

		return $result;
	}
}
