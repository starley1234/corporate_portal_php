<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Загрузка демо-сценария ProcessHub из консоли.
 *
 *   bin/console processhub:seed-demo --scenario=procurement
 *   bin/console processhub:seeddemo --user=admin@n2j.ru --fresh=1
 *
 * Дефис в имени задачи допускается: точка входа снимает его перед Minion
 * (`seed-demo` → `seeddemo`), иначе класс `Task_Processhub_Seed-demo` не найти.
 *
 * Команда идемпотентна: без --fresh возвращает существующий демо-процесс,
 * с --fresh пересобирает его. Печатает код и ссылку, чтобы стенд можно было
 * открыть сразу после установки.
 */
class Task_Processhub_Seeddemo extends Minion_Task
{
	protected $_options = [
		'scenario' => 'procurement',
		'user'     => '',
		'fresh'    => '0',
	];

	protected function _execute(array $params)
	{
		$scenario = trim((string) Arr::get($params, 'scenario', 'procurement'));

		if ($scenario !== 'procurement')
		{
			Minion_CLI::write('Неизвестный сценарий: '.$scenario.'. Доступен: procurement.');
			return;
		}

		try
		{
			$user_id = Service_Nj670_Process::resolve_user_id((string) Arr::get($params, 'user', ''));

			if ($user_id <= 0)
			{
				Minion_CLI::write('Не найден пользователь. Укажите --user=admin@n2j.ru или --user=<id>.');
				return;
			}

			$fresh = in_array(strtolower((string) Arr::get($params, 'fresh', '0')), ['1', 'true', 'yes', 'on'], TRUE);
			$done = Service_Nj670_Demo::seed($user_id, $scenario, ['fresh' => $fresh]);

			if ( ! Arr::get($done, 'ok'))
			{
				Minion_CLI::write('Ошибка: '.Arr::get($done, 'error', 'неизвестно'));
				return;
			}

			$process = $done['process'];
			$url = rtrim((string) Kohana::$config->load('site_config')->get('site_url', ''), '/');

			// Повторный запуск возвращает существующий процесс без id версий —
			// берём указатели из строки процесса, иначе CLI печатал «версия #0».
			$audit_id = (int) ($done['audit_id'] ?? 0);
			$plan_id = (int) ($done['plan_version_id'] ?? Arr::get($process, 'plan_version_id', 0));
			$fact_id = (int) ($done['fact_version_id'] ?? Arr::get($process, 'fact_version_id', 0));

			if ($audit_id <= 0)
			{
				$audit = Service_Nj670_Process::latest_audit((int) $process['id']);
				$audit_id = $audit ? (int) $audit['id'] : 0;
			}

			Minion_CLI::write('');
			Minion_CLI::write(Arr::get($done, 'exists') ? 'Демо уже было загружено:' : 'Демо загружено:');
			Minion_CLI::write('  процесс : '.$process['title']);
			Minion_CLI::write('  код     : '.$process['code']);
			Minion_CLI::write('  адрес   : '.($url !== '' ? $url : '').'/process/'.$process['code']);
			Minion_CLI::write('  аудит   : #'.$audit_id);
			Minion_CLI::write('  план    : версия #'.$plan_id);
			Minion_CLI::write('  факт    : версия #'.$fact_id);
			Minion_CLI::write('');
			Minion_CLI::write('Дальше: откройте холст, переключите «План/Факт» и нажмите «Выпустить DOCX/PDF».');
		}
		catch (Throwable $e)
		{
			Minion_CLI::write('Ошибка демо: '.$e->getMessage());
			Kohana::$log->add(Log::ERROR, 'nj670 seed-demo: :e', [':e' => $e->getMessage()]);
		}
	}

}
