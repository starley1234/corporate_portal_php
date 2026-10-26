<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Служебный JSON API «Сканера безопасности ИИ-моделей» (NJ-672).
 *
 *   GET  /aiscan/api/status?id=N     состояние прогона (живой статус-ряд)
 *   GET  /aiscan/api/export?id=N     полный машинный отчёт (CI/архив)
 *   POST /aiscan/api/worker_result   приёмка отчёта от воркера
 *
 * Первые две точки — только администратору (в отчёте адреса целей и
 * доказательства ответов), третья — только воркеру по X-Worker-Token.
 * Контракт статуса общий для платформы (`Vision::run_live`), поэтому
 * подходит готовый `partials/live_status`.
 */
class Controller_Nj672_Api extends Controller
{
    public $auto_render = FALSE;

    /** @var array тело запроса (JSON или форма) */
    protected $_in = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

        $raw     = (string) $this->request->body();
        $decoded = $raw !== '' ? json_decode($raw, TRUE) : NULL;

        $this->_in = is_array($decoded)
            ? $decoded
            : ($this->request->post() ?: (array) $this->request->query());
    }

    /** Состояние прогона для живого статус-ряда. */
    public function action_status()
    {
        if ( ! $this->_admin())
        {
            return $this->_out([
                'ok'    => FALSE,
                'error' => 'auth',
                'login' => '/auth/login?return_url=aiscan',
            ], 401);
        }

        $id = (int) ($this->_in['id'] ?? 0);

        if ($id <= 0 OR ! Vision::run_get('nj_672_runs', $id))
        {
            return $this->_out(['ok' => FALSE, 'error' => 'аудит не найден'], 404);
        }

        $watch = Vision::run_watch('nj_672_runs', $id, 'nj672.scan');
        $run   = $watch['run'];

        if ( ! $run)
        {
            return $this->_out(['ok' => FALSE, 'error' => 'аудит не найден'], 404);
        }

        $live    = Vision::run_live($run);
        $summary = (array) (((array) $run['result'])['summary'] ?? []);

        return $this->_out([
            'ok'          => TRUE,
            'run_id'      => $id,
            'status'      => (string) $run['status'],
            'status_live' => (string) $live['status'],
            'stage'       => (string) $live['stage'],
            'stages'      => (array) $live['stages'],
            'elapsed_sec' => (int) $live['elapsed_sec'],
            'silent_sec'  => $live['silent_sec'],
            'attempts'    => (int) $live['attempts'],
            'note'        => $live['note'] !== '' ? (string) $live['note'] : (string) $watch['hint'],
            'error'       => (string) $run['error'],
            'security_score' => $summary['security_score'] ?? NULL,
            'risk_level'  => (string) ($summary['risk_level'] ?? ''),
        ]);
    }

    /** Машинный отчёт целиком: тот же jsonb, что хранится в прогоне. */
    public function action_export()
    {
        if ( ! $this->_admin())
        {
            return $this->_out(['ok' => FALSE, 'error' => 'auth'], 401);
        }

        $id  = (int) ($this->_in['id'] ?? 0);
        $run = $id > 0 ? Vision::run_get('nj_672_runs', $id) : NULL;

        if ( ! $run)
        {
            return $this->_out(['ok' => FALSE, 'error' => 'аудит не найден'], 404);
        }

        $this->response->headers('Content-Disposition',
            'attachment; filename="aiscan-report-'.$id.'.json"');

        return $this->_out([
            'ok'     => TRUE,
            'run_id' => $id,
            'status' => (string) $run['status'],
            'created_at' => (string) ($run['created_at'] ?? ''),
            'finished_at' => (string) ($run['finished_at'] ?? ''),
            'error'  => (string) $run['error'],
            'report' => (array) $run['result'],
        ]);
    }

    /** Приёмка отчёта воркера. */
    public function action_worker_result()
    {
        if ( ! Vision::worker_token_ok())
        {
            Log_Plain::event(Log::WARNING, 'nj672: приёмка отчёта — неверный токен воркера');

            return $this->_out(['ok' => FALSE, 'error' => 'Неверный токен воркера'], 403);
        }

        $id = (int) ($this->_in['run_id'] ?? 0);

        if ($id <= 0 OR ! Vision::run_get('nj_672_runs', $id))
        {
            return $this->_out(['ok' => FALSE, 'error' => 'Прогон не найден'], 404);
        }

        // Отчёт целиком кладём в result: сайт ничего не досчитывает. Ключ
        // цели воркер в отчёт не кладёт (контракт движка + его тест), но
        // страховка дёшева: известное поле секрета вырезаем и здесь.
        unset($this->_in['api_key'], $this->_in['target']['api_key']);

        try
        {
            $status = Vision::run_apply('nj_672_runs', $id, $this->_in);
        }
        catch (Throwable $e)
        {
            $reason = get_class($e).': '.$e->getMessage();

            Log_Plain::event(Log::ERROR,
                'nj672: приёмка прогона :run упала — прогон останется queued: :e',
                [':run' => $id, ':e' => $reason],
                [':run' => $id, ':e' => $reason, 'exception' => $e]);

            return $this->_out([
                'ok'    => FALSE,
                'error' => 'приёмка результата упала: '.mb_substr($reason, 0, 300),
            ], 500);
        }

        Log_Plain::event(Log::INFO, 'nj672: отчёт прогона :run принят (:status)',
            [':run' => $id, ':status' => $status]);

        return $this->_out(['ok' => TRUE, 'status' => $status]);
    }

    protected function _admin()
    {
        return Auth::instance()->logged_in('admin');
    }

    protected function _out(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $this->response;
    }
}
