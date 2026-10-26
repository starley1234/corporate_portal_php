<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj669_Solid3dWorker extends Controller_Nj120_Api
{
	/** Без development-обхода и без токенов в URL/логах прокси. */
	protected function _authorize()
	{
		$want = trim((string) getenv('WORKER_API_TOKEN'));
		$given = trim((string) $this->request->headers('x-worker-token'));
		if ($want !== '' AND $given !== '' AND hash_equals($want, $given)) { return TRUE; }
		$this->_auth_fail('NJ669: отсутствует или не совпал заголовок токена воркера.');
		return FALSE;
	}

	public function action_assist_context() { return $this->accept('assist_context'); }
	public function action_assist_result() { return $this->accept('assist_result'); }
	public function action_assist_fail() { return $this->accept('assist_fail'); }
	public function action_context() { return $this->accept('context'); }
	public function action_upload() { return $this->accept('upload'); }
	public function action_result() { return $this->accept('result'); }
	public function action_fail() { return $this->accept('fail'); }
	public function action_verify_context() { return $this->accept('verify_context'); }
	public function action_verify_result() { return $this->accept('verify_result'); }
	public function action_verify_fail() { return $this->accept('verify_fail'); }
	public function action_export_context() { return $this->accept('export_context'); }
	public function action_export_upload() { return $this->accept('export_upload'); }
	public function action_export_result() { return $this->accept('export_result'); }
	public function action_export_fail() { return $this->accept('export_fail'); }

	protected function accept($action)
	{
		if ($this->_halt) { return; }
		if ($this->request->method() !== Request::POST) { return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405); }
		try
		{
			if (str_starts_with($action, 'assist_')) { return $this->_respond(Service_Nj669_Assist::worker($this->_body, $action)); }
			if (str_starts_with($action, 'export_')) { return $this->_respond(Service_Nj669_Export::worker($this->_body, $action)); }
			if (str_starts_with($action, 'verify_')) { return $this->_respond(Service_Nj669_Solid3d::verify_worker($this->_body, $action)); }
			return $this->_respond(Service_Nj669_Solid3d::worker($this->_body, $action));
		}
		catch (Throwable $e)
		{
			Log_Plain::event(Log::ERROR, 'NJ669 worker :action, объект :id: :error', [':action' => $action, ':id' => (int) Arr::get($this->_body, str_starts_with($action, 'assist_') ? 'task_id' : 'revision_id', 0), ':error' => $e->getMessage()]);
			return $this->_respond(['ok' => FALSE, 'error' => $e instanceof DomainException ? $e->getMessage() : 'Ошибка приёмки; см. журнал сайта.'], $e instanceof DomainException ? 409 : 500);
		}
	}
}
