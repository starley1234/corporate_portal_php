<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /health и /health/metrics — для внешнего мониторинга (без сессии).
 *
 *  GET /health          → 200 {"ok":true,"db":true,"db_ms":…} или 503 при
 *                         недоступной БД. Публично: только «жив/не жив».
 *  GET /health/metrics  → метрики Prometheus (text/plain; version=0.0.4).
 *                         Доступ: заголовок «Authorization: Bearer <PRX_METRICS_TOKEN>»
 *                         (или X-Metrics-Token), либо вход администратором.
 *                         Пустой PRX_METRICS_TOKEN — только администратор.
 */
class Controller_Prx103_Health extends Controller {

	public function before()
	{
		parent::before();

		$this->response->headers('Cache-Control', 'no-store');
	}

	public function action_index()
	{
		$live = Service_Prx103_Metrics::liveness();

		$this->response
			->status($live['ok'] ? 200 : 503)
			->headers('Content-Type', 'application/json; charset=utf-8')
			->body(json_encode($live, JSON_UNESCAPED_UNICODE));
	}

	public function action_metrics()
	{
		if ( ! $this->_metrics_allowed())
		{
			$this->response
				->status(403)
				->headers('Content-Type', 'text/plain; charset=utf-8')
				->body("forbidden: нужен Authorization: Bearer <PRX_METRICS_TOKEN> или вход администратором\n");

			return;
		}

		$this->response
			->headers('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
			->body(Service_Prx103_Metrics::prometheus());
	}

	protected function _metrics_allowed(): bool
	{
		$auth  = (string) $this->request->headers('authorization');
		$token = preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)
			? $m[1]
			: $this->request->headers('x-metrics-token');

		if (Service_Prx103_Metrics::token_ok($token !== NULL ? (string) $token : NULL))
		{
			return TRUE;
		}

		try
		{
			return Auth::instance()->logged_in('admin');
		}
		catch (Throwable $e)
		{
			return FALSE;
		}
	}
}
