<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx100: «Состояние платформы» для /admin — одна сводка вместо обхода
 * шести страниц. Каждая карточка считается своим запросом в try/catch:
 * отсутствующий модуль или таблица превращаются в «—», а не в 500.
 *
 * Каждая карточка: ['key', 'title', 'icon', 'value', 'unit', 'note', 'href',
 * 'tone' => ok|warn|bad|muted, 'rows' => [[label, value], …]].
 */
class Service_Prx103_Health {

	protected static function _db()
	{
		return Database::instance();
	}

	/** Одна строка SQL → массив или [] (ошибка — не повод ронять страницу). */
	protected static function _one($sql)
	{
		try
		{
			$row = self::_db()->query(Database::SELECT, $sql, FALSE)->current();

			return $row ? (array) $row : [];
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	public static function fmt($n)
	{
		$n = (int) $n;

		if ($n >= 1000000)
		{
			return rtrim(rtrim(number_format($n / 1000000, 1, ',', ''), '0'), ',').' млн';
		}

		if ($n >= 1000)
		{
			return number_format($n / 1000, $n < 10000 ? 1 : 0, ',', '').' тыс.';
		}

		return (string) $n;
	}

	/**
	 * Все карточки сводки.
	 *
	 * @return array
	 */
	public static function snapshot()
	{
		return array_values(array_filter([
			self::queue(),
			self::workers(),
			self::budgets(),
			self::errors(),
			self::users(),
			self::migrations(),
		]));
	}

	/** Очередь prx120: ожидание, в работе, провалы за сутки, зависшие. */
	public static function queue()
	{
		if ( ! class_exists('Service_Prx120_JobQueue'))
		{
			return NULL;
		}

		$r = self::_one("SELECT count(*) FILTER (WHERE status = 'pending')    AS pending,
		                        count(*) FILTER (WHERE status = 'processing') AS processing,
		                        count(*) FILTER (WHERE status = 'failed' AND coalesce(finished_at, created_at) > now() - interval '24 hours') AS failed24,
		                        count(*) FILTER (WHERE status = 'done'   AND coalesce(finished_at, created_at) > now() - interval '24 hours') AS done24,
		                        count(*) FILTER (WHERE status = 'processing' AND coalesce(heartbeat_at, claimed_at, created_at) < now() - interval '15 minutes') AS stuck
		                   FROM prx_120_jobs");

		if ( ! $r)
		{
			return NULL;
		}

		$stuck   = (int) $r['stuck'];
		$failed  = (int) $r['failed24'];
		$pending = (int) $r['pending'];

		return [
			'key'   => 'queue',
			'title' => 'Очередь задач',
			'icon'  => 'fas fa-list-check',
			'value' => (string) ((int) $r['processing']),
			'unit'  => 'в работе',
			'tone'  => $stuck > 0 ? 'bad' : (($failed > 0 OR $pending > 20) ? 'warn' : 'ok'),
			'note'  => $stuck > 0 ? 'Зависших: '.$stuck.' — кнопка «Прибрать очередь» ниже' : 'Зависших нет',
			'href'  => '/prx120/monitor',
			'rows'  => [
				['Ожидают', (string) $pending],
				['Готово за 24 ч', (string) ((int) $r['done24'])],
				['Провалено за 24 ч', (string) $failed],
			],
		];
	}

	/** Воркеры: сколько отметились за сутки и давно ли последний пульс. */
	public static function workers()
	{
		if ( ! class_exists('Service_Prx120_JobQueue'))
		{
			return NULL;
		}

		$r = self::_one("SELECT count(DISTINCT claimed_by) FILTER (WHERE coalesce(heartbeat_at, finished_at, created_at) > now() - interval '24 hours') AS day,
		                        count(DISTINCT claimed_by) FILTER (WHERE coalesce(heartbeat_at, finished_at, created_at) > now() - interval '10 minutes') AS live,
		                        extract(epoch FROM now() - max(coalesce(heartbeat_at, finished_at)))::int AS ago
		                   FROM prx_120_jobs WHERE claimed_by IS NOT NULL AND claimed_by <> ''");

		if ( ! $r)
		{
			return NULL;
		}

		$ago  = $r['ago'] === NULL ? -1 : (int) $r['ago'];
		$live = (int) $r['live'];
		$ago_txt = $ago < 0 ? 'пульса ещё не было' : ($ago < 90 ? 'пульс '.$ago.' с назад' : 'пульс '.round($ago / 60).' мин назад');

		return [
			'key'   => 'workers',
			'title' => 'Воркеры',
			'icon'  => 'fas fa-heart-pulse',
			'value' => (string) $live,
			'unit'  => 'на связи',
			'tone'  => $live > 0 ? 'ok' : (($ago >= 0 AND $ago < 3600) ? 'warn' : 'bad'),
			'note'  => $ago_txt.($live === 0 ? ' — проверьте службу воркеров' : ''),
			'href'  => '/prx120/monitor',
			'rows'  => [
				['Отметились за сутки', (string) ((int) $r['day'])],
			],
		];
	}

	/** Бюджеты токенов: расход за сегодня и кто упёрся в потолок. */
	public static function budgets()
	{
		if ( ! class_exists('Service_Prx120_Budget'))
		{
			return NULL;
		}

		$r = self::_one("SELECT coalesce(sum(prompt_tokens + completion_tokens), 0) AS tokens, count(*) AS jobs
		                   FROM prx_120_usage WHERE created_at >= date_trunc('day', now())");

		$over = 0;
		$warn = 0;

		foreach (Service_Prx120_Budget::top_today(50) as $row)
		{
			$st = (array) ($row['state'] ?? []);

			if ( ! empty($st['over']))
			{
				$over++;
			}
			elseif ( ! empty($st['warn']))
			{
				$warn++;
			}
		}

		return [
			'key'   => 'budgets',
			'title' => 'Токены сегодня',
			'icon'  => 'fas fa-coins',
			'value' => self::fmt((int) ($r['tokens'] ?? 0)),
			'unit'  => 'токенов',
			'tone'  => $over > 0 ? 'bad' : ($warn > 0 ? 'warn' : 'ok'),
			'note'  => $over > 0 ? 'Исчерпали бюджет: '.$over.' — очередь им отказывает' : ($warn > 0 ? 'Близко к потолку: '.$warn : 'Никто не упёрся в лимит'),
			'href'  => '/prx120/budgets',
			'rows'  => [
				['Вызовов ИИ за сегодня', (string) ((int) ($r['jobs'] ?? 0))],
			],
		];
	}

	/** Ошибки: системный лог за сегодня (файл) + провалы модулей. */
	public static function errors()
	{
		$n_log = self::_log_errors_today();
		$fails = (int) (self::_one("SELECT count(*) AS c FROM prx_120_jobs WHERE status = 'failed' AND coalesce(finished_at, created_at) > now() - interval '24 hours'")['c'] ?? 0);

		return [
			'key'   => 'errors',
			'title' => 'Ошибки за сутки',
			'icon'  => 'fas fa-heart-crack',
			'value' => (string) $n_log,
			'unit'  => 'в системном логе',
			'tone'  => $n_log > 20 ? 'bad' : (($n_log > 0 OR $fails > 0) ? 'warn' : 'ok'),
			'note'  => $fails > 0 ? 'Проваленных задач: '.$fails : 'Задачи без провалов',
			'href'  => '/prx100/errors',
			'rows'  => [],
		];
	}

	/** Сколько строк ERROR/CRITICAL/EMERGENCY в сегодняшнем файле лога Kohana. */
	protected static function _log_errors_today()
	{
		$file = APPPATH.'logs/'.date('Y/m/d').'.php';

		if ( ! is_file($file) OR filesize($file) > 20 * 1024 * 1024)
		{
			return 0;
		}

		$n = 0;

		try
		{
			$fh = fopen($file, 'rb');

			while (($line = fgets($fh)) !== FALSE)
			{
				if (preg_match('~--- (?:ERROR|CRITICAL|EMERGENCY|ALERT): ~', $line))
				{
					$n++;
				}
			}

			fclose($fh);
		}
		catch (Throwable $e)
		{
			return 0;
		}

		return $n;
	}

	/** Пользователи: всего и новые за неделю. */
	public static function users()
	{
		// у users нет даты создания (ядро Kohana Auth: logins, last_login) —
		// считаем активность по last_login
		$r = self::_one("SELECT count(*) AS total,
		                        count(*) FILTER (WHERE to_timestamp(NULLIF(last_login, 0)) > now() - interval '7 days') AS week,
		                        count(*) FILTER (WHERE to_timestamp(NULLIF(last_login, 0)) > now() - interval '24 hours') AS active
		                   FROM users");

		if ( ! $r)
		{
			return NULL;
		}

		return [
			'key'   => 'users',
			'title' => 'Пользователи',
			'icon'  => 'fas fa-users',
			'value' => (string) ((int) $r['total']),
			'unit'  => 'аккаунтов',
			'tone'  => 'muted',
			'note'  => 'Заходили за сутки: '.(int) $r['active'],
			'href'  => '/prx595/admin',
			'rows'  => [
				['Заходили за неделю', (string) ((int) $r['week'])],
			],
		];
	}

	/** Миграции PostgreSQL, которые ещё не применены. */
	public static function migrations()
	{
		if ( ! class_exists('Service_Prx111_Migrator'))
		{
			return NULL;
		}

		try
		{
			$pending = count(Service_Prx111_Migrator::instance()->pending());
		}
		catch (Throwable $e)
		{
			return NULL;
		}

		return [
			'key'   => 'migrations',
			'title' => 'Миграции PG',
			'icon'  => 'fas fa-database',
			'value' => (string) $pending,
			'unit'  => 'не применено',
			'tone'  => $pending > 0 ? 'bad' : 'ok',
			'note'  => $pending > 0 ? 'Новый код ждёт схему — примените на /migrate_pg' : 'Схема актуальна',
			'href'  => '/migrate_pg',
			'rows'  => [],
		];
	}

	/**
	 * Быстрые действия /admin. Возвращает текст для flash.
	 *
	 * @param  string $act
	 * @return string
	 */
	public static function run_action($act)
	{
		switch ($act)
		{
			case 'queue_maintain':
				if ( ! class_exists('Service_Prx120_JobQueue'))
				{
					return 'Очередь prx120 не подключена';
				}

				$q = Service_Prx120_JobQueue::instance();
				$a = (int) $q->reconcile_outcomes(300);
				$b = (int) $q->requeue_stale();

				return 'Очередь прибрана: закрыто доставленных '.$a.', разобрано зависших '.$b;

			case 'cache_clear':
				try
				{
					Cache::instance()->delete_all();

					return 'Кэш приложения очищен';
				}
				catch (Throwable $e)
				{
					return 'Кэш не очищен: '.$e->getMessage();
				}

			default:
				return 'Неизвестное действие';
		}
	}

}
