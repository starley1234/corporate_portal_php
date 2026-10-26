<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-662: «быстрый старт» — подбор участников под тему (канал nj662.quick).
 *
 * Порт идеи Service_Lib100_QuickStart из наработок владельца: в исходном
 * «Симпозиуме ИИ» кнопка «быстрый старт» сама придумывала участников
 * (generate_agent_config) — имена, характеры, роли. В наработках это делал PHP
 * прямо в запросе; здесь работа уходит серверному воркеру, потому что вызов
 * модели занимает десятки секунд, а веб-процесс должен отвечать быстро (та же
 * причина, по которой ход диалога идёт через очередь).
 *
 * Разделение простое: этот класс ставит задачу и переводит диалог в статус
 * «подбираю агентов», а принимает готовых участников
 * Service_Nj662_Dialog::apply_quick (POST /dialog/api/quick).
 */
class Service_Nj662_Quick {

	/** Подобрать участников и (по желанию) сразу начать разговор. */
	public static function queue($dialog_id, $autostart = FALSE, $model = '')
	{
		$dialog = Service_Nj662_Dialog::dialog($dialog_id);

		if ( ! $dialog)
		{
			return ['error' => 'Диалог не найден'];
		}

		if ( ! class_exists('Service_Nj120_JobQueue'))
		{
			return ['error' => 'Очередь задач nj120 недоступна — подбор участников не поставить'];
		}

		$db = Service_Nj662_Dialog::db();

		$db->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET status = \'quick\', error = \'\',
				stage = \'Подготовка\', stage_note = \'подбираю участников под тему\',
				stage_at = now(), updated_at = now()
			 WHERE id = '.(int) $dialog_id, FALSE);

		$payload = [
			'dialog_id' => (int) $dialog_id,
			'topic'     => (string) $dialog['topic'],
			'brief'     => (string) $dialog['brief'],
			'model'     => trim((string) $model) !== '' ? (string) $model : (string) $dialog['a_model'],
			'autostart' => (bool) $autostart,
		];

		try
		{
			$job_id = Service_Nj120_JobQueue::push(Service_Nj662_Dialog::QUICK_CHANNEL, $payload, [
				'module'       => 'nj662',
				'visitor_id'   => 'u:'.(int) $dialog['user_id'],
				'created_by'   => (int) $dialog['user_id'],
				'max_attempts' => 2,
				'timeout_sec'  => 900,
			]);
		}
		catch (Throwable $e)
		{
			Kohana::$log->add(Log::ERROR,
				'nj662: очередь не приняла подбор участников диалога :id: :class: :e',
				[':id' => (int) $dialog_id, ':class' => get_class($e), ':e' => $e->getMessage()]);

			Service_Nj662_Dialog::quick_failed($dialog_id, 'Очередь не приняла задачу: '.$e->getMessage());

			return ['error' => 'Очередь не приняла задачу: '.$e->getMessage()];
		}

		$db->query(Database::UPDATE,
			'UPDATE nj_662_dialogs SET job_id = '.(int) $job_id.', updated_at = now()
			 WHERE id = '.(int) $dialog_id, FALSE);

		Service_Nj662_Dialog::event($dialog_id, 'quick',
			$autostart ? 'Подбираю участников, разговор начнётся автоматически' : 'Подбираю участников под тему');

		return ['ok' => TRUE, 'job_id' => $job_id];
	}
}
