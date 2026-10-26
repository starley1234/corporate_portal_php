<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API «Трендов GitHub» (nj593) для воркера и страницы обзора:
 *  status/<code>  — публичный статус (поллинг страницы обзора);
 *  progress       — промежуточный прогресс (воркер, токен);
 *  item           — одна позиция обзора (воркер, токен);
 *  complete       — завершение: заголовок + вступление (воркер, токен);
 *  create         — автосоздание «обзора дня» по расписанию (токен);
 *  cancel         — прервать сборку из интерфейса (администратор, CSRF).
 * Ошибки возвращаются JSON-ом с понятной причиной — до красного баннера
 * доезжает текст, а не HTML-страница.
 */
class Controller_Nj593_Api extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;
    }

    /** GET /trends/api/status/<code> — статус и позиции (публично, как обзор). */
    public function action_status()
    {
        $code = (string) $this->request->param('code');
        $r = Service_Nj593_Trends::by_code($code);

        if ($r === NULL OR ! Service_Nj593_Trends::can_read($r, $this->can('nj593.admin')))
        {
            $this->response->status(404);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->body(json_encode(['error' => 'not_found'], JSON_UNESCAPED_UNICODE));

            return;
        }

        $r = Service_Nj593_Trends::reconcile($r);

        $items = [];

        foreach (Service_Nj593_Trends::items((int) $r['id']) as $it)
        {
            $items[] = [
                'rank'      => (int) $it['rank'],
                'full_name' => (string) $it['full_name'],
                'url'       => (string) $it['url'],
                'title'     => (string) $it['title'],
            ];
        }

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
                    'attempts'    => (int) $j['attempts'],
                ];
            }
        }

        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode([
            'code'       => (string) $r['code'],
            'status'     => (string) $r['status'],
            'error'      => (string) $r['error'],
            'progress'   => (string) $r['progress'],
            'title'      => (string) $r['title'],
            'rdate'      => (string) $r['rdate'],
            'period'     => (int) $r['period_days'],
            'items_ready'=> count($items),
            'item_count' => (int) $r['item_count'],
            'model_used' => $this->can('nj593.admin') ? (string) ($r['model_used'] ?? '') : '',
            'items'      => $items,
            'job'        => $job,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** POST /trends/api/progress — промежуточный прогресс воркера. */
    public function action_progress()
    {
        if ( ! $this->_auth())
        {
            $this->_denied('auth');

            return;
        }

        $review_id = (int) $this->request->post('review_id');
        $phase     = (string) $this->request->post('phase');

        if ($review_id <= 0 OR $phase === '')
        {
            $this->_bad('need review_id и phase');

            return;
        }

        Service_Nj593_Trends::set_progress($review_id, $phase);
        $this->_ok();
    }

    /** POST /trends/api/item — одна позиция обзора (факты + тексты ИИ). */
    public function action_item()
    {
        if ( ! $this->_auth())
        {
            $this->_denied('auth');

            return;
        }

        $review_id = (int) $this->request->post('review_id');
        $rank      = (int) $this->request->post('rank');

        if ($review_id <= 0 OR $rank <= 0)
        {
            $this->_bad('need review_id и rank');

            return;
        }

        $topics = json_decode((string) $this->request->post('topics'), TRUE);
        $item = [
            'full_name'     => (string) $this->request->post('full_name'),
            'url'           => (string) $this->request->post('url'),
            'description'   => (string) $this->request->post('description'),
            'language'      => (string) $this->request->post('language'),
            'stars'         => (int) $this->request->post('stars'),
            'forks'         => (int) $this->request->post('forks'),
            'gh_created_at' => (string) $this->request->post('gh_created_at'),
            'topics'        => is_array($topics) ? $topics : [],
            'title'         => (string) $this->request->post('title'),
            'what'          => (string) $this->request->post('what'),
            'why'           => (string) $this->request->post('why'),
            'use_for'       => (string) $this->request->post('use_for'),
            'apply'         => (string) $this->request->post('apply'),
            'score'         => (int) $this->request->post('score'),
        ];

        try
        {
            $res = Service_Nj593_Trends::add_item($review_id, $rank, $item,
                trim((string) $this->request->post('model_used')));
        }
        catch (Throwable $e)
        {
            // причина сразу доезжает до воркера и в красный баннер UI
            Kohana::$log->add(Log::ERROR, 'nj593: item: :e', [':e' => $e->getMessage()]);
            $this->_bad('сбой сохранения: '.$e->getMessage()
                .' @ '.basename((string) $e->getFile()).':'.$e->getLine());

            return;
        }

        if (isset($res['error']))
        {
            $this->_bad((string) $res['error']);

            return;
        }

        $this->_ok(['id' => (int) ($res['id'] ?? 0)]);
    }

    /** POST /trends/api/complete — заголовок, вступление, фактическая модель. */
    public function action_complete()
    {
        if ( ! $this->_auth())
        {
            $this->_denied('auth');

            return;
        }

        $review_id  = (int) $this->request->post('review_id');
        $title      = (string) $this->request->post('title');
        $summary    = (string) $this->request->post('summary');
        $model_used = (string) $this->request->post('model_used');
        $takeaways  = (string) $this->request->post('takeaways');

        if ($review_id <= 0)
        {
            $this->_bad('need review_id');

            return;
        }

        try
        {
            $res = Service_Nj593_Trends::complete($review_id, $title, $summary, $model_used, $takeaways);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj593: complete: :e', [':e' => $e->getMessage()]);
            $this->_bad('сбой завершения: '.$e->getMessage()
                .' @ '.basename((string) $e->getFile()).':'.$e->getLine());

            return;
        }

        if (isset($res['error']))
        {
            $this->_bad((string) $res['error']);

            return;
        }

        $this->_ok();
    }

    /**
     * POST /trends/api/create — автосоздание «обзора дня» (токен воркера).
     * Вызывается таймером на сервере воркера: пример в README модуля.
     */
    public function action_create()
    {
        if ( ! $this->_auth())
        {
            $this->_denied('auth');

            return;
        }

        $admin_id = (int) $this->_db_first_admin();

        if ($admin_id <= 0)
        {
            $this->_bad('в системе нет администратора — некому владеть обзором');

            return;
        }

        $res = Service_Nj593_Trends::create(
            $admin_id,
            trim((string) $this->request->post('date')),
            (int) $this->request->post('period'),
            (int) $this->request->post('count'),
            (string) $this->request->post('language'),
            (int) $this->request->post('min_stars'),
            (string) $this->request->post('focus')
        );

        if (isset($res['error']))
        {
            $this->_bad((string) $res['error']);

            return;
        }

        $this->_ok(['code' => $res['code']]);
    }

    /**
     * POST /trends/api/cancel — ПРЕРВАТЬ сборку обзора из интерфейса.
     *
     * Права: только nj593.admin (остальные получат 403). CSRF обязателен —
     * эндпоинт меняет состояние, а страница обзора публичная.
     *
     * Снимает задачу в очереди с force (воркер прекратит работу в течение
     * ~минуты: он замечает отказ heartbeat) и переводит обзор в 'cancelled'.
     * Уже собранные позиции сохраняются.
     */
    public function action_cancel()
    {
        if ( ! $this->can('nj593.admin'))
        {
            Log_Plain::event(Log::WARNING, 'nj593: отказ в остановке сборки — требуется nj593.admin');
            $this->response->status(403);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->body(json_encode(['error' => 'Сборкой управляет только администратор'], JSON_UNESCAPED_UNICODE));

            return;
        }

        $code = trim((string) $this->request->post('code'));
        $csrf = (string) $this->request->post('csrf');

        if ($code === '')
        {
            $this->_bad('need code');

            return;
        }

        if ( ! Security::check($csrf))
        {
            $this->response->status(403);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->body(json_encode(
                ['error' => 'csrf', 'message' => 'Сессия устарела — обновите страницу'],
                JSON_UNESCAPED_UNICODE));

            return;
        }

        $r = Service_Nj593_Trends::by_code($code);

        if ($r === NULL)
        {
            $this->_bad('Обзор не найден');

            return;
        }

        $res = Service_Nj593_Trends::cancel((int) $r['id']);

        if (isset($res['error']))
        {
            $this->_bad((string) $res['error']);

            return;
        }

        $this->_ok([
            'status' => (string) ($res['status'] ?? 'cancelled'),
            'items'  => (int) ($res['items'] ?? 0),
        ]);
    }

    /** Токен воркера (WORKER_API_TOKEN сайта, заголовок X-Worker-Token). */
    protected function _auth()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        if ($token === '')
        {
            return FALSE;
        }

        $given = (string) $this->request->headers('x-worker-token');

        return $given !== '' AND hash_equals($token, $given);
    }

    protected function _db_first_admin()
    {
        try
        {
            $row = DB::select('u.id')
                ->from(['users', 'u'])
                ->join(['roles_users', 'ru'], 'INNER')->on('ru.user_id', '=', 'u.id')
                ->join(['roles', 'r'], 'INNER')->on('r.id', '=', 'ru.role_id')
                ->where('r.name', '=', 'admin')
                ->order_by('u.id')
                ->limit(1)
                ->execute()->current();

            return $row ? (int) $row['id'] : 0;
        }
        catch (Exception $e)
        {
            return 0;
        }
    }

    protected function _ok(array $extra = [])
    {
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode(['ok' => TRUE] + $extra, JSON_UNESCAPED_UNICODE));
    }

    protected function _bad($error)
    {
        $this->response->status(400);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode(['error' => (string) $error], JSON_UNESCAPED_UNICODE));
    }

    protected function _denied($error)
    {
        $this->response->status(401);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode(['error' => (string) $error], JSON_UNESCAPED_UNICODE));
    }
}
