<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Приёмная python-воркера: /process/w/<context|graph|audit|report|fail|ping>.
 *
 * Токен строгий (WORKER_API_TOKEN), development-обход не включается: воркер
 * пишет схемы и файлы, «пустим без токена» здесь дороже, чем неудобство.
 */
class Controller_Nj670_Worker extends Controller_Nj120_Api
{
	protected function _authorize()
	{
		$want  = trim((string) getenv('WORKER_API_TOKEN'));
		$given = trim((string) $this->request->headers('x-worker-token'));

		if ($want !== '' AND $given !== '' AND hash_equals($want, $given))
		{
			return TRUE;
		}

		$this->_auth_fail('NJ670: нет заголовка токена воркера или он не совпал.');
		return FALSE;
	}

	public function action_index()
	{
		return $this->_handle('context');
	}

	public function action_context()
	{
		return $this->_handle('context');
	}

	public function action_graph()
	{
		return $this->_handle('graph');
	}

	public function action_audit()
	{
		return $this->_handle('audit');
	}

	public function action_report()
	{
		return $this->_handle('report');
	}

	public function action_fail()
	{
		return $this->_handle('fail');
	}

	/**
	 * Пульс «воркер жив». Нужен, когда задач нет: иначе сайт не отличает
	 * простаивающий воркер от выключенного и зря уходит в правиловый фолбэк.
	 */
	public function action_ping()
	{
		return $this->_handle('ping');
	}

	public function action_ontology()
	{
		return $this->_handle('ontology');
	}

	public function action_ontology_fail()
	{
		return $this->_handle('ontology_fail');
	}

	protected function _handle($action)
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		try
		{
			$result = Service_Nj670_Process::worker($this->_body, $action);

			return $this->_respond($result);
		}
		catch (Throwable $e)
		{
			Log_Plain::event(Log::ERROR, 'NJ670 worker :action, объект :id: :error', [
				':action' => $action,
				':id'     => (int) Arr::get($this->_body, 'version_id',
					(int) Arr::get($this->_body, 'audit_id',
						(int) Arr::get($this->_body, 'report_id', 0))),
				':error'  => $e->getMessage(),
			]);

			return $this->_respond([
				'ok'    => FALSE,
				'error' => $e instanceof DomainException
					? $e->getMessage()
					: 'Ошибка приёмки; подробности в журнале сайта.',
			], $e instanceof DomainException ? 409 : 500);
		}
	}
}
