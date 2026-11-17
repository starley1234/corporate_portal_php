<?php defined('SYSPATH') or die('No direct script access.');

/**
 * HTTP-API очереди задач (модуль PRX-120) для python-воркеров.
 *
 * Нужен, когда воркеры работают на отдельном сервере без доступа к PostgreSQL.
 * Аутентификация — статический токен: заголовок X-Worker-Token (или ?token=),
 * значение из WORKER_API_TOKEN. Пустой токен в настройках = API закрыт
 * (кроме KOHANA_ENV=development, где он разрешён для локальной отладки).
 *
 *   POST /prx120/api/claim        {"channels": ["embeddings.index"], "worker": "w1"}
 *   POST /prx120/api/heartbeat/<id>  {"worker": "w1", "note": "..."}
 *   POST /prx120/api/complete/<id>   {"worker": "w1", "result": {...}}
 *   POST /prx120/api/fail/<id>       {"worker": "w1", "error": "...", "retry": true}
 *   POST /prx120/api/release_mine    {"worker": "w1"} — задачи прошлого запуска
 *   GET  /prx120/api/job/<id>
 *   GET  /prx120/api/stats
 *
 * Ответ всегда JSON: {"ok": true|false, ...} или {"ok": false, "error": "..."}.
 */
class Controller_Prx120_Api extends Controller {

    /** @var bool API не рендерит шаблоны */
    public $auto_render = FALSE;

    /** @var Service_Prx120_JobQueue */
    protected $_queue;

    /** @var array тело запроса (JSON) */
    protected $_body = [];

    /** @var bool TRUE = запрос отклонён до выполнения действия */
    protected $_halt = FALSE;

    /** @var string|null причина отказа авторизации (для ответа 403) */
    protected $_auth_error = NULL;

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
        $this->_queue = Service_Prx120_JobQueue::instance();

        // Тело запроса: JSON или обычный POST
        $raw = $this->request->body();
        if ($raw)
        {
            $decoded = json_decode($raw, TRUE);
            $this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();
        }
        else
        {
            $this->_body = (array) $this->request->post();
        }

        if ( ! $this->_authorize())
        {
            $this->_respond(['ok' => FALSE, 'error' => $this->_auth_error ?: 'Недействительный токен воркера'], 403);
        }
    }

    /**
     * Захват задачи из очереди.
     */
    public function action_claim()
    {
        if ($this->_halt) return;

        if ($this->request->method() !== Request::POST)
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);

        // Самоисцеление: возвращаем в очередь задачи, воркер которых «умер»
        // не отпустив их (пример: fail не прошёл из-за сбоя сайта). Дешёвый
        // UPDATE, зовём примерно каждый пятый claim — воркеры опрашивают
        // постоянно, так что застрявшая задача вернётся сама за минуту.
        if (mt_rand(1, 5) === 1)
        {
            try { $this->_queue->requeue_stale(); }
            catch (Exception $e) { /* best effort */ }
        }

        $channels = isset($this->_body['channels']) && is_array($this->_body['channels'])
            ? array_map('strval', $this->_body['channels'])
            : [];
        $worker = isset($this->_body['worker']) ? (string) $this->_body['worker'] : 'unknown';

        $job = $this->_queue->claim($channels, $worker);

        return $this->_respond(['ok' => TRUE, 'job' => $job]);
    }

    /**
     * Сигнал «воркер жив» по задаче.
     */
    public function action_heartbeat()
    {
        if ($this->_halt) return;

        $job_id = (int) $this->request->param('id');
        $worker = isset($this->_body['worker']) ? (string) $this->_body['worker'] : '';
        $note   = isset($this->_body['note'])   ? (string) $this->_body['note']   : NULL;

        $ok = $this->_queue->heartbeat($job_id, $worker, $note);
        return $this->_respond(['ok' => $ok]);
    }

    /**
     * Промежуточный рапорт воркера (пульс + событие 'progress' в журнале).
     */
    public function action_progress()
    {
        if ($this->_halt) return;

        $job_id = (int) $this->request->param('id');
        $worker = isset($this->_body['worker']) ? (string) $this->_body['worker'] : '';
        $note   = isset($this->_body['note'])   ? (string) $this->_body['note']   : NULL;

        $ok = $this->_queue->progress($job_id, $worker, $note);
        return $this->_respond(['ok' => $ok]);
    }

    /**
     * Задача выполнена.
     */
    public function action_complete()
    {
        if ($this->_halt) return;

        $job_id = (int) $this->request->param('id');
        $worker = isset($this->_body['worker']) ? (string) $this->_body['worker'] : '';
        $result = isset($this->_body['result']) ? $this->_body['result'] : NULL;

        // Скобки обязательны: AND слабее тернарника и присваивания. Без них
        // $usage получал bool, и расход воркера не записывался вовсе.
        $usage  = (isset($this->_body['usage']) AND is_array($this->_body['usage']))
            ? $this->_body['usage'] : NULL;

        $ok = $this->_queue->complete($job_id, $worker, $result, $usage);
        return $this->_respond(['ok' => $ok]);
    }

    /**
     * Задача упала (возможен retry).
     */
    public function action_fail()
    {
        if ($this->_halt) return;

        $job_id = (int) $this->request->param('id');
        $worker = isset($this->_body['worker']) ? (string) $this->_body['worker'] : '';
        $error  = isset($this->_body['error'])  ? (string) $this->_body['error']  : 'unknown error';
        $retry  = ! isset($this->_body['retry']) || (bool) $this->_body['retry'];
        $usage  = (isset($this->_body['usage']) AND is_array($this->_body['usage']))
            ? $this->_body['usage'] : NULL;

        $ok = $this->_queue->fail($job_id, $worker, $error, $retry, $usage);
        return $this->_respond(['ok' => $ok]);
    }

    /**
     * Задачи ЭТОГО воркера, оставшиеся от предыдущего запуска процесса.
     *
     * Воркер зовёт это при старте (BaseWorker._release_orphans): процесс могли
     * убить (OOM, деплой, systemd-restart), строки остались в processing с
     * мёртвым пульсом — владелец видел «Выполняется, пульс 20 мин» без конца.
     * Доставленные артефакты закрываются как 'done' (reconcile), остальные
     * возвращаются в очередь немедленно.
     */
    public function action_release_mine()
    {
        if ($this->_halt) return;

        $worker  = isset($this->_body['worker']) ? (string) $this->_body['worker'] : '';
        $released = $this->_queue->release_mine($worker);

        return $this->_respond(['ok' => TRUE, 'released' => (int) $released]);
    }

    /**
     * Статус задачи.
     */
    public function action_job()
    {
        if ($this->_halt) return;

        $job = $this->_queue->get((int) $this->request->param('id'));
        return $this->_respond(['ok' => $job !== NULL, 'job' => $job]);
    }

    /**
     * Сводка по очереди.
     */
    public function action_stats()
    {
        if ($this->_halt) return;

        return $this->_respond(['ok' => TRUE, 'stats' => $this->_queue->stats(), 'version' => $this->_app_version()]);
    }

    /**
     * Обслуживание очереди: вернуть в pending задачи, чей воркер молчит
     * дольше timeout_sec (вызывается пиналкой prx603 каждые 10 минут).
     * Без этого падение воркера = задача навсегда в 'processing'.
     */
    public function action_maintain()
    {
        if ($this->_halt) return;

        $count = $this->_queue->requeue_stale();

        return $this->_respond(['ok' => TRUE, 'requeued' => (int) $count]);
    }

    /**
     * Версия задеплоенного кода (короткий хэш git-коммита) — чтобы видеть,
     * докатилось ли обновление до сервера, не заходя на него по SSH.
     */
    protected function _app_version()
    {
        static $version = NULL;

        if ($version !== NULL)
            return $version;

        $version = 'unknown';
        $root    = realpath(DOCROOT.'..');

        if ($root === FALSE)
            return $version;

        $head = @file_get_contents($root.'/.git/HEAD');

        if ($head AND preg_match('~ref:\s*(\S+)~', $head, $m))
        {
            $ref = $m[1];
            $sha = @file_get_contents($root.'/.git/'.$ref);

            if ( ! $sha)
            {
                // ref распакован: ищем в packed-refs
                $packed = @file_get_contents($root.'/.git/packed-refs');
                if ($packed AND preg_match('~^(\S+)\s+'.preg_quote($ref, '~').'$~m', $packed, $pm))
                    $sha = $pm[1];
            }

            if ($sha)
                $version = substr(trim($sha), 0, 7);
        }

        return $version;
    }

    // ---------------------------------------------------------------------

    /**
     * Выполнение запроса с перехватом исключений: машинный API отвечает
     * JSON-ошибкой (класс + сообщение), а не HTML-страницей 500.
     */
    public function execute()
    {
        try
        {
            return parent::execute();
        }
        catch (Throwable $e)
        {
            $this->_respond([
                'ok'    => FALSE,
                'error' => get_class($e).': '.$e->getMessage(),
            ], 500);

            return $this->response;
        }
    }

    /**
     * Проверка токена воркера.
     *
     * При отказе — разные сообщения (какой именно сценарий сорвался) и запись
     * в application/logs (имена переданных способов, НЕ значения токенов).
     */
    protected function _authorize()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        // Токен не задан: в development пускаем локально, иначе API закрыт.
        if ($token === '')
        {
            if (Kohana::$environment !== Kohana::DEVELOPMENT)
            {
                $this->_auth_fail('WORKER_API_TOKEN не задан в .env сайта — API очереди закрыт. Добавьте токен в .env в корне сайта и повторите.');
                return FALSE;
            }

            return TRUE;
        }

        $given    = $this->request->headers('x-worker-token');
        $via      = 'header';
        $received = ($given !== NULL AND trim((string) $given) !== '');

        if ( ! $received)
        {
            $given    = $this->request->query('token');
            $via      = 'query';
            $received = ($given !== NULL AND trim((string) $given) !== '');
        }

        if ( ! $received)
        {
            $this->_auth_fail('Токен не передан: ни заголовка X-Worker-Token, ни ?token= в запросе. Если заголовок посылался — его срезал прокси/веб-сервер перед PHP.');
            return FALSE;
        }

        if ( ! hash_equals($token, trim((string) $given)))
        {
            $this->_auth_fail('Токен воркера получен ('.$via.'), но не совпал с WORKER_API_TOKEN сайта. Сверьте значения посимвольно (длина на сайте: '.strlen($token).').');
            return FALSE;
        }

        return TRUE;
    }

    /** Записать отказ авторизации в журнал приложения и подготовить 403. */
    protected function _auth_fail($reason)
    {
        $this->_halt       = TRUE;
        $this->_auth_error = $reason;

        Kohana::$log->add(Log::ERROR, 'prx120 API: отказ авторизации (:ip): :reason', [
            ':ip'     => Request::$client_ip,
            ':reason' => $reason,
        ]);
    }

    /**
     * JSON-ответ (единственный формат этого API).
     */
    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
