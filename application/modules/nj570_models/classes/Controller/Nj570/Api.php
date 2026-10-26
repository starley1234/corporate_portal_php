<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API 3D-конструктора:
 *  * /3d/api/status/<code> — статус модели и ревизий (poll из UI);
 *  * /3d/api/upload — приём файлов от воркера (X-Worker-Token).
 */
class Controller_Nj570_Api extends Controller {

    public function before()
    {
        parent::before();

        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
    }

    /** GET /3d/api/status/<code>. */
    public function action_status()
    {
        $code  = (string) $this->request->param('code');
        $model = Service_Nj570_Models::by_code($code);

        if ($model === NULL)
        {
            $this->response->body(json_encode(['error' => 'not_found']));

            return;
        }

        // Чтение статуса — по коду-капабилити (страница модели открыта по коду)

        $revs = [];

        foreach (Service_Nj570_Models::revisions((int) $model['id']) as $r)
        {
            // живой статус задачи очереди — для прогресс-бара в UI
            $job = NULL;

            if ( ! empty($r['job_id']))
            {
                $j = Service_Nj120_JobQueue::instance()->get((int) $r['job_id']);

                if ($j AND in_array($j['status'], ['pending', 'processing'], TRUE))
                {
                    $started = strtotime((string) ($j['claimed_at'] ?: $j['created_at']));

                    $job = [
                        'status'      => (string) $j['status'],
                        'elapsed_sec' => $started ? max(0, time() - $started) : 0,
                    ];
                }
            }

            // Общий контракт статус-ряда (partials/live_status) + авто-стоп
            // молчащего воркера. Прогресс-бар страницы модели рисуется своим JS,
            // но «висеть вечно» он больше не может: тишина ~4 мин или застой
            // прогресса 10 мин гасят задачу, и она уходит в честную ошибку.
            $live = (! empty($r['job_id']) && class_exists('Service_Nj120_JobQueue'))
                ? Service_Nj120_JobQueue::instance()->status_of((int) $r['job_id'])
                : [];

            if ( ! empty($live['found']) AND ($live['status'] ?? '') === 'running')
            {
                try
                {
                    $queue   = Service_Nj120_JobQueue::instance();
                    $stopped = FALSE;

                    if ($live['silent_sec'] !== NULL AND (int) $live['silent_sec'] > $queue->silent_stop_sec())
                    {
                        $stopped = $queue->auto_stop_silent((int) $r['job_id']) > 0;
                    }

                    if ( ! $stopped AND $queue->auto_stop_stalled((int) $r['job_id']) > 0)
                    {
                        $stopped = TRUE;
                    }

                    if ($stopped)
                    {
                        $live = $queue->status_of((int) $r['job_id']);
                    }
                }
                catch (Throwable $e)
                {
                    Kohana::$log->add(Log::WARNING, 'nj570: авто-остановка застрявшей сборки: :e',
                        [':e' => $e->getMessage()]);
                }
            }

            $revs[] = [
                'rev_no'     => (int) $r['rev_no'],
                'status'     => (string) $r['status'],
                'error'      => (string) $r['error'],
                'summary'    => (string) $r['summary'],
                'params'     => $r['params'] ? json_decode((string) $r['params'], TRUE) : NULL,
                'has_image'  => ! empty($r['has_image']),
                'job'        => $job,
                // тот же контракт, что у общего статус-ряда: status_live, stage,
                // stages, elapsed_sec, silent_sec — страница может показать ряд
                // без своего поллинга (см. partials/live_status_js)
                'live'       => [
                    'status_live' => (string) ($live['status'] ?? ''),
                    'stage'       => (string) ($live['stage'] ?? ''),
                    'stages'      => (array) ($live['stages'] ?? []),
                    'elapsed_sec' => (int) ($live['elapsed_sec'] ?? 0),
                    'silent_sec'  => $live['silent_sec'] ?? NULL,
                    'found'       => (bool) ($live['found'] ?? FALSE),
                ],
                'files'      => array_map(function ($f) {
                    return ['id' => (int) $f['id'], 'kind' => (string) $f['kind'], 'size' => (int) $f['size']];
                }, (array) $r['files']),
                'created_at' => (string) $r['created_at'],
            ];
        }

        $this->response->body(json_encode([
            'code'     => (string) $model['code'],
            'status'   => (string) $model['status'],
            'title'    => (string) $model['title'],
            'clarify'  => $model['clarify'] ? json_decode((string) $model['clarify'], TRUE) : NULL,
            'revisions' => $revs,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** GET /3d/api/storage — диагностика хранилища (только токен воркера). */
    public function action_storage()
    {
        if ( ! $this->_auth())
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'auth']));

            return;
        }

        $dir = Service_Nj570_Models::storage_dir();
        $files = [];

        if (is_dir($dir))
        {
            foreach (['stl', 'png'] as $ext)
            {
                $list = (array) glob($dir.'/*/*.'.$ext);
                usort($list, fn ($a, $b) => filemtime($b) <=> filemtime($a));

                foreach (array_slice($list, 0, 5) as $f)
                {
                    $files[] = ['f' => str_replace($dir.'/', '', $f), 'size' => filesize($f)];
                }
            }
        }

        $this->response->body(json_encode([
            'dir'         => $dir,
            'is_dir'      => is_dir($dir),
            'is_writable' => is_dir($dir) ? is_writable($dir) : FALSE,
            'writable_root' => is_dir(dirname($dir)) ? is_writable(dirname($dir)) : FALSE,
            'count'       => is_dir($dir) ? count((array) glob($dir.'/*/*')) : 0,
            'recent'      => $files,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** POST /3d/api/upload — файл от воркера (multipart: revision_id, kind, file). */
    public function action_upload()
    {
        if ( ! $this->_auth())
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'auth']));

            return;
        }

        $revision_id = (int) $this->request->post('revision_id');
        $kind        = $this->request->post('kind') === 'stl' ? 'stl' : 'png';

        if (empty($_FILES['file']['tmp_name']) OR ! is_uploaded_file($_FILES['file']['tmp_name']))
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'no_file']));

            return;
        }

        $bytes = file_get_contents($_FILES['file']['tmp_name']);
        $res   = Service_Nj570_Models::store_file($revision_id, $kind, $bytes);

        if (isset($res['error']))
        {
            $this->response->status(400);
            $this->response->body(json_encode($res));

            return;
        }

        $this->response->body(json_encode(['ok' => TRUE, 'id' => $res['id']]));
    }

    /** Статический токен (как в nj120): X-Worker-Token или ?token=. */
    protected function _auth()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        if ($token === '')
        {
            return FALSE;
        }

        $given = (string) $this->request->headers('x-worker-token');

        if ($given === '')
        {
            $given = (string) $this->request->query('token');
        }

        return hash_equals($token, $given);
    }

    public function after()
    {
        parent::after();
    }
}
