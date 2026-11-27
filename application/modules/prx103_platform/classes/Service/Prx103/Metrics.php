<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx103: состояние для внешнего мониторинга — /health и /health/metrics.
 *
 *  · liveness() — «жив ли сайт»: PHP отвечает и PostgreSQL принимает
 *    запрос. Публично (балансировщик, uptime-робот), без подробностей.
 *  · prometheus() — метрики в текстовом формате Prometheus 0.0.4: очередь
 *    prx120 в целом и по каналам, токены и стоимость LLM за сутки,
 *    непримененные миграции. Каждый блок в try/catch: модуля или таблицы
 *    нет — блок пропускается, страница не падает (как Service_Prx103_Health).
 */
class Service_Prx103_Metrics {

	/** @return array ok, db, db_ms */
	public static function liveness(): array
	{
		$t0 = microtime(TRUE);

		try
		{
			$db = (int) (Prx_Db::one('SELECT 1 AS one')['one'] ?? 0) === 1;
		}
		catch (Throwable $e)
		{
			$db = FALSE;
		}

		return [
			'ok'    => $db,
			'db'    => $db,
			'db_ms' => round((microtime(TRUE) - $t0) * 1000, 1),
		];
	}

	public static function prometheus(): string
	{
		$out  = [];
		$live = self::liveness();

		self::_metric($out, 'prx_up', 'gauge', 'Сайт отвечает (1)', [['', 1]]);
		self::_metric($out, 'prx_db_up', 'gauge', 'PostgreSQL принимает запросы', [['', $live['db'] ? 1 : 0]]);
		self::_metric($out, 'prx_db_latency_seconds', 'gauge', 'Время SELECT 1', [['', $live['db_ms'] / 1000]]);

		if ( ! $live['db'])
		{
			return implode("\n", $out)."\n";
		}

		self::_try(function () use (&$out) {
			if ( ! class_exists('Service_Prx120_JobQueue')) return;
			$m = Service_Prx120_JobQueue::instance()->platform_metrics();
			if ( ! $m) return;
			self::_metric($out, 'prx_queue_jobs', 'gauge', 'Задачи очереди по статусу', [
				['status="pending"', (int) $m['pending']],
				['status="processing"', (int) $m['processing']],
			]);
			self::_metric($out, 'prx_queue_failed_24h', 'gauge', 'Упавшие задачи за 24 ч', [['', (int) $m['failed24']]]);
			self::_metric($out, 'prx_queue_done_24h', 'gauge', 'Выполненные задачи за 24 ч', [['', (int) $m['done24']]]);
			self::_metric($out, 'prx_queue_stuck', 'gauge', 'processing без пульса дольше 15 мин', [['', (int) $m['stuck']]]);
		});

		self::_try(function () use (&$out) {
			if ( ! class_exists('Service_Prx120_Channels')) return;
			$pending = $processing = $oldest = $failed = $limited = [];
			foreach (Service_Prx120_Channels::overview() as $c)
			{
				$l = 'channel="'.self::_label($c['channel']).'"';
				$pending[]    = [$l, $c['pending']];
				$processing[] = [$l, $c['processing']];
				$failed[]     = [$l, $c['failed_24h']];
				$oldest[]     = [$l, (int) ($c['oldest_pending_sec'] ?? 0)];
				$limited[]    = [$l, ($c['max_concurrent'] > 0 OR $c['min_interval_sec'] > 0) ? 1 : 0];
			}
			self::_metric($out, 'prx_queue_channel_pending', 'gauge', 'Ожидают по каналу', $pending);
			self::_metric($out, 'prx_queue_channel_processing', 'gauge', 'В работе по каналу', $processing);
			self::_metric($out, 'prx_queue_channel_failed_24h', 'gauge', 'Упали за 24 ч по каналу', $failed);
			self::_metric($out, 'prx_queue_channel_oldest_pending_seconds', 'gauge', 'Сколько ждёт самая старая доступная задача', $oldest);
			self::_metric($out, 'prx_queue_channel_limited', 'gauge', 'У канала задан лимит (prx_120_channel_limits)', $limited);
		});

		self::_try(function () use (&$out) {
			if ( ! class_exists('Service_Prx120_Cost')) return;
			$rows = Service_Prx120_Cost::tokens_by_model(24);
			$tokens = 0;
			foreach ($rows as $r) { $tokens += (int) $r['ptokens'] + (int) $r['ctokens']; }
			$agg = Service_Prx120_Cost::aggregate($rows, '')[''] ?? ['rub' => 0.0, 'unpriced_tokens' => 0];
			self::_metric($out, 'prx_llm_tokens_24h', 'gauge', 'Токены LLM за 24 ч', [['', $tokens]]);
			self::_metric($out, 'prx_llm_cost_rub_24h', 'gauge', 'Стоимость LLM за 24 ч, руб. (config/prx120_prices.php)', [['', round((float) $agg['rub'], 4)]]);
			self::_metric($out, 'prx_llm_unpriced_tokens_24h', 'gauge', 'Токены моделей без цены за 24 ч', [['', (int) $agg['unpriced_tokens']]]);
		});

		self::_try(function () use (&$out) {
			if ( ! class_exists('Service_Prx111_Migrator')) return;
			self::_metric($out, 'prx_migrations_pending', 'gauge', 'Неприменённые миграции PostgreSQL',
				[['', count(Service_Prx111_Migrator::instance()->pending())]]);
		});

		return implode("\n", $out)."\n";
	}

	/** Сравнить токен мониторинга (PRX_METRICS_TOKEN): пустой — запрещено. */
	public static function token_ok(?string $given): bool
	{
		$want = trim((string) getenv('PRX_METRICS_TOKEN'));

		return $want !== '' AND $given !== NULL AND hash_equals($want, trim($given));
	}

	protected static function _try(callable $block): void
	{
		try
		{
			$block();
		}
		catch (Throwable $e)
		{
			Kohana::$log->add(Log::WARNING, 'prx103 metrics: :e', [':e' => $e->getMessage()]);
		}
	}

	/** @param array $samples [[labels, value], …] */
	protected static function _metric(array &$out, string $name, string $type, string $help, array $samples): void
	{
		if ( ! $samples)
		{
			return;
		}

		$out[] = '# HELP '.$name.' '.str_replace(["\\", "\n"], ['\\\\', '\\n'], $help);
		$out[] = '# TYPE '.$name.' '.$type;

		foreach ($samples as [$labels, $value])
		{
			$out[] = $name.($labels !== '' ? '{'.$labels.'}' : '').' '.self::_num($value);
		}
	}

	protected static function _num($v): string
	{
		if (is_int($v))
		{
			return (string) $v;
		}

		$s = rtrim(rtrim(sprintf('%.6F', (float) $v), '0'), '.');

		return $s === '' || $s === '-0' ? '0' : $s;
	}

	/** Значение метки: экранирование по формату Prometheus. */
	protected static function _label(string $v): string
	{
		return str_replace(["\\", '"', "\n"], ['\\\\', '\\"', '\\n'], $v);
	}
}
