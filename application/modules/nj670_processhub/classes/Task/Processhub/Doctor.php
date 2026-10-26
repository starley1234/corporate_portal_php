<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Самопроверка ProcessHub: почему воркер «не в сети» и что делать.
 *
 *   bin/console processhub:doctor
 *   bin/console processhub:doctor --verbose=1
 *
 * Команда ничего не меняет: печатает окружение сайта, пульс воркера, очередь
 * и последние ошибки каналов nj670.*. Тот же статус виден на /process/admin.
 */
class Task_Processhub_Doctor extends Minion_Task
{
	protected $_options = [
		'verbose' => '0',
	];

	protected function _execute(array $params)
	{
		$verbose = in_array(strtolower((string) Arr::get($params, 'verbose', '0')), ['1', 'true', 'yes', 'on'], TRUE);
		$ok = TRUE;

		Minion_CLI::write('');
		Minion_CLI::write('ProcessHub — самопроверка');
		Minion_CLI::write('========================');

		// 1. База и миграции.
		try
		{
			$tables = Service_Nj670_Process::db_tables();
			$missing = array_diff(Service_Nj670_Process::required_tables(), $tables);

			if ($missing)
			{
				$ok = FALSE;
				Minion_CLI::write('[!] Нет таблиц: '.implode(', ', $missing));
				Minion_CLI::write('    Выполните: bin/console db:migrate');
			}
			else
			{
				Minion_CLI::write('[+] Таблицы nj_670_* на месте ('.count($tables).' шт.)');
			}
		}
		catch (Throwable $e)
		{
			$ok = FALSE;
			Minion_CLI::write('[!] База недоступна: '.$e->getMessage());
			Minion_CLI::write('    Проверьте DB_* в .env сайта.');
		}

		// 2. Токен приёмной на сайте.
		$token = trim((string) getenv('WORKER_API_TOKEN'));

		if ($token === '')
		{
			$ok = FALSE;
			Minion_CLI::write('[!] WORKER_API_TOKEN на сайте не задан — воркер не сможет ни забрать задачу, ни прислать пульс.');
		}
		else
		{
			Minion_CLI::write('[+] WORKER_API_TOKEN задан ('.strlen($token).' знаков)');
		}

		// 3. Пульс воркера.
		try
		{
			$status = Service_Nj670_Process::worker_status();

			Minion_CLI::write('');
			Minion_CLI::write('Воркер: '.($status['online'] ? 'НА СВЯЗИ' : 'НЕ ОТВЕЧАЕТ')
				.' (признак: '.$status['source'].')');
			Minion_CLI::write('  пульс        : '.(string) ($status['last_ping_at'] ?: 'не было'));
			Minion_CLI::write('  окно пульса  : '.$status['presence_minutes'].' мин');
			Minion_CLI::write('  имя/версия   : '
				.($status['worker'] !== '' ? $status['worker'] : '—')
				.' / '.($status['version'] !== '' ? $status['version'] : '—'));
			Minion_CLI::write('  каналы       : '.($status['channels'] ? implode(', ', $status['channels']) : '—'));
			Minion_CLI::write('  задач nj670  : '.$status['queued'].' в очереди');
			Minion_CLI::write('  вывод        : '.$status['hint']);

			if ( ! $status['online'])
			{
				$ok = FALSE;
				Minion_CLI::write('');
				Minion_CLI::write('Что проверить:');
				Minion_CLI::write('  1) воркер запущен: python -m n2j_workers ... (см. systemd/докер)');
				Minion_CLI::write('  2) workers/.env: NJ670_API_BASE=<сайт>/process/w');
				Minion_CLI::write('  3) workers/.env: NJ670_SITE_TOKEN = WORKER_API_TOKEN сайта');
				Minion_CLI::write('  4) с воркера доступен сайт: curl -H "X-Worker-Token: …" -X POST <сайт>/process/w/ping');
				Minion_CLI::write('  5) если пульс мешает — NJ670_WORKER_FORCE_ONLINE=1 (временно, на сайте)');
			}
		}
		catch (Throwable $e)
		{
			$ok = FALSE;
			Minion_CLI::write('[!] Статус воркера не получен: '.$e->getMessage());
		}

		// 4. Очередь модуля.
		try
		{
			$jobs = Service_Nj670_Process::queue_snapshot(10);

			Minion_CLI::write('');
			Minion_CLI::write('Очередь (последние задачи nj670.*):');

			if ( ! $jobs)
			{
				Minion_CLI::write('  пусто');
			}

			foreach ($jobs as $job)
			{
				Minion_CLI::write('  #'.(int) $job['id'].' '.$job['channel'].' · '.$job['status']
					.' · попыток '.(int) $job['attempts']
					.' · '.Service_Nj670_Repo::date_text($job['created_at'])
					.($job['error'] !== '' ? ' · '.UTF8::substr((string) $job['error'], 0, 160) : ''));
			}
		}
		catch (Throwable $e)
		{
			Minion_CLI::write('[!] Очередь не прочитана: '.$e->getMessage());
		}

		// 5. Настройки модуля.
		if ($verbose)
		{
			Minion_CLI::write('');
			Minion_CLI::write('Настройки:');

			foreach (Service_Nj670_Process::config() as $key => $value)
			{
				Minion_CLI::write('  '.$key.' = '.(is_scalar($value) ? var_export($value, TRUE) : gettype($value)));
			}
		}

		Minion_CLI::write('');
		Minion_CLI::write($ok ? 'Итог: всё на месте.' : 'Итог: есть замечания — см. пункты [!] выше.');
	}
}
