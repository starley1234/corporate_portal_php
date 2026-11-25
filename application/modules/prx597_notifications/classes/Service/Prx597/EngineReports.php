<?php defined('SYSPATH') or die('No direct script access.');

/** Внутренние административные SELECT-отчёты. Не запускает доставку и не меняет статусы. */
final class Service_Prx597_EngineReports extends Service_Prx597_Base
{
	public static function journal($limit = 100, array $filter = [])
	{
		$where = [];
		$params = [];

		$channel = trim((string) Arr::get($filter, 'channel', ''));

		if ($channel !== '')
		{
			$where[] = 'l."channel" = :channel';
			$params[':channel'] = (string) $channel;
		}

		$status = trim((string) Arr::get($filter, 'status', ''));

		if ($status !== '')
		{
			$where[] = 'l."ok" = '.($status === 'ok' ? 'TRUE' : 'FALSE');
		}

		$event = trim((string) Arr::get($filter, 'event', ''));

		if ($event !== '')
		{
			$where[] = 'o."event_alias" = :event';
			$params[':event'] = (string) $event;
		}

		// Код события живёт в очереди, а не в журнале: джойним, чтобы в
		// журнале было видно, что именно не дошло, а не только «канал упал».
		$sql = 'SELECT l.*, o."event_alias" FROM "prx_597_log" l
		         LEFT JOIN "prx_597_outbox" o ON o."id" = l."outbox_id"'
			.($where ? ' WHERE '.implode(' AND ', $where) : '')
			.' ORDER BY l."id" DESC LIMIT '.(int) max(1, min(500, (int) $limit));

		try
		{
			return self::_all($sql, $params);
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	public static function queue($limit = 50, array $status = ['pending', 'sending'])
	{
		$status = array_values(array_filter(array_map('strval', $status)));
		$list   = [];
		$params = [];

		foreach ($status as $i => $one)
		{
			$list[] = ':st'.$i;
			$params[':st'.$i] = (string) $one;
		}

		$sql = 'SELECT * FROM "prx_597_outbox"
		          WHERE "status" IN ('.implode(', ', $list).')
		          ORDER BY "next_attempt_at" ASC, "id" ASC
		          LIMIT '.(int) max(1, min(200, (int) $limit));

		try
		{
			$rows = self::_all($sql, $params);
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
}
