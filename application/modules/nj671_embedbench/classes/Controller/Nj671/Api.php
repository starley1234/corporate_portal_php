<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Служебный JSON API «Стенда эмбеддингов» (NJ-671).
 *
 *   GET  /embedbench/api/status?id=N     состояние прогона (живой статус-ряд)
 *   POST /embedbench/api/worker_result   приёмка отчёта от воркера
 *
 * Первая точка — для страницы результата: она опрашивает её, пока прогон
 * считается. Контракт ответа общий для платформы (`Service_Nj120_JobQueue::
 * live_status`), поэтому подходит готовый `partials/live_status`.
 *
 * Вторая — для воркера, только по `X-Worker-Token`: тот же механизм, что у
 * VLM-сервисов (`Controller_Vision_Base::action_worker_result`). Здесь он
 * написан заново по одной причине: у стенда нет кадров, и тащить весь
 * фото-конвейер (`photo`, `photo_file`) ради приёмки jsonb было бы лишним.
 */
class Controller_Nj671_Api extends Controller
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
        // Приёмка — только воркером; всё остальное — только администратором.
        if ( ! Auth::instance()->logged_in('admin'))
        {
            return $this->_out([
                'ok'    => FALSE,
                'error' => 'auth',
                'login' => '/auth/login?return_url=embedbench',
            ], 401);
        }

        $id = (int) ($this->_in['id'] ?? 0);

        if ($id <= 0 OR ! Vision::run_get('nj_671_runs', $id))
        {
            return $this->_out(['ok' => FALSE, 'error' => 'прогон не найден'], 404);
        }

        $watch = Vision::run_watch('nj_671_runs', $id, 'nj671.bench');
        $run   = $watch['run'];

        if ( ! $run)
        {
            return $this->_out(['ok' => FALSE, 'error' => 'прогон не найден'], 404);
        }

        $live  = Vision::run_live($run);
        $shape = Service_Nj671_Bench::shape((array) $run['result']);

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
            // Короткая сводка: страница перезагрузится сама, но если JS выключен,
            // клиент всё равно поймёт, чем кончилось.
            'winner'      => (string) ($shape['decision']['winner'] ?? ''),
            'summary'     => (string) ($shape['decision']['summary'] ?? ''),
        ]);
    }

    /** Приёмка отчёта воркера. */
    public function action_worker_result()
    {
        if ( ! Vision::worker_token_ok())
        {
            Log_Plain::event(Log::WARNING, 'nj671: приёмка отчёта — неверный токен воркера');

            return $this->_out(['ok' => FALSE, 'error' => 'Неверный токен воркера'], 403);
        }

        $id = (int) ($this->_in['run_id'] ?? 0);

        if ($id <= 0 OR ! Vision::run_get('nj_671_runs', $id))
        {
            return $this->_out(['ok' => FALSE, 'error' => 'Прогон не найден'], 404);
        }

        // Отчёт целиком (включая decision) кладём в result: страница ничего не
        // досчитывает. Заголовок прогона воркер присылает человеческий — им и
        // подписываем строку истории, если воркер его прислал.
        try
        {
            $status = Vision::run_apply('nj_671_runs', $id, $this->_in);
        }
        catch (Throwable $e)
        {
            $reason = get_class($e).': '.$e->getMessage();

            Log_Plain::event(Log::ERROR,
                'nj671: приёмка прогона :run упала — прогон останется queued: :e',
                [':run' => $id, ':e' => $reason],
                [':run' => $id, ':e' => $reason, 'exception' => $e]);

            return $this->_out([
                'ok'    => FALSE,
                'error' => 'приёмка результата упала: '.mb_substr($reason, 0, 300),
            ], 500);
        }

        Log_Plain::event(Log::INFO, 'nj671: отчёт прогона :run принят (:status)',
            [':run' => $id, ':status' => $status]);

        return $this->_out(['ok' => TRUE, 'status' => $status]);
    }

    protected function _out(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $this->response;
    }
}
