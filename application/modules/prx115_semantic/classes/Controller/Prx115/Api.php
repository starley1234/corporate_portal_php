<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API семантического поиска (модуль PRX-115). Доступ — только авторизованным.
 *
 *   POST /prx115/api/index    {"collection":"prx401_tasks","entity":"prx401_task",
 *                             "items":[{"id":"12","text":"...","metadata":{}}]}
 *                            → {"ok":true,"job_ids":[...]} — индексация через очередь
 *   GET  /prx115/api/search?q=запрос&collection=prx401_tasks&limit=10&entity=
 *                            → {"ok":true,"results":[...]} — синхронный поиск
 *   GET  /prx115/api/stats    → {"ok":true,"stats":[...]}
 *   GET  /prx115/api/panel    → всё для страницы /prx115: коллекции, очередь,
 *                              состояние модуля, последние поиски (только admin)
 *   POST /prx115/api/check    → проверка эмбеддинг-сервера (только admin)
 *   GET  /prx115/api/status   → живой статус последней задачи semantic.index
 *   POST /prx115/api/retry    {"id":969} → повторить задачу новой (только admin)
 *   POST /prx115/api/vectors  {"collection":…,"entity":…,"items":[{id,vector,…}]}
 *                            → запись векторов от воркера в HTTP-режиме
 *                              (авторизация ТОКЕНОМ воркера, X-Worker-Token)
 *   GET  /prx115/api/health   → состояние модуля: движок, покрытие, проблемы
 *                              (токен воркера ИЛИ администратор)
 *   GET  /prx115/api/coverage → покрытие источников индексации (только admin)
 *   POST /prx115/api/reindex  {"collection":…,"limit":200,"force":bool}
 *                            → догнать отставшие тексты (только admin)
 *   POST /prx115/api/prune    {"collection":…,"older_days":7}
 *                            → убрать векторы удалённых записей (только admin)
 */
class Controller_Prx115_Api extends Controller_Base {

    /** @var bool API не рендерит шаблон */
    public $auto_render = FALSE;

    /**
     * Точки, куда ходит воркер (у него нет пользовательской сессии).
     *
     * Почему это критично. `Controller_Base::before()` для неавторизованного
     * бросает HTTP_Exception_403 и отдаёт HTML-страницу «Access Denied» — то
     * есть до собственной проверки токена дело не доходило. 23.10.2026 из-за
     * этого упала задача 2234: воркер в http-режиме не мог записать векторы и
     * получал «HTTP 403: сервер вернул HTML вместо JSON». Токен при этом был
     * верный — до проверки токена запрос просто не доживал.
     *
     * Теперь эти два действия проходят общий шлюз входа, а личность
     * проверяют сами: vectors — только X-Worker-Token, health — токен воркера
     * или сессия администратора. Остальные действия панели по-прежнему требуют
     * входа пользователя.
     */
    protected $public_actions = ['vectors', 'health'];

    /** @var bool запрос отклонён в before() */
    protected $_halt = FALSE;

    /** @var array тело запроса (JSON) */
    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

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

        $action = (string) $this->request->action();

        // 1. Приём векторов от воркера: у воркера нет пользовательской сессии —
        //    авторизация тем же токеном, что и /prx120/api/* (X-Worker-Token).
        //    Нужно для HTTP-режима: воркер считает векторы и отдаёт их сюда,
        //    иначе задача заканчивалась «Готово: skipped», а поиск был пустым.
        if ($action === 'vectors')
        {
            if ( ! $this->_authorize_worker())
            {
                $this->_halt = TRUE;
            }

            return;
        }

        // health — единственная точка, которую читают И воркер/внешний мониторинг
        // (по токену воркера), И панель (по сессии администратора): что-то одно
        // обязано сработать, иначе 403 с подсказкой про оба пути.
        if ($action === 'health')
        {
            if ($this->_authorize_worker(TRUE))
            {
                return;
            }

            $user = Auth::instance()->get_user();

            if ( ! $user OR ! $this->_is_admin($user))
            {
                $this->_halt = TRUE;
                $this->_respond(['ok' => FALSE, 'error' => 'Нужен токен воркера (X-Worker-Token) или вход администратора'], 403);
            }

            return;
        }

        $user = Auth::instance()->get_user();

        if ( ! $user)
        {
            $this->_halt = TRUE;
            $this->_respond(['ok' => FALSE, 'error' => 'Требуется авторизация'], 403);
            return;
        }

        // 2. Управляющие точки (панель, проверка сервера, повтор задачи) —
        //    только управление: они жгут токены эмбеддингов и ставят задачи.
        if (in_array($action, ['panel', 'check', 'retry', 'coverage', 'reindex', 'prune'], TRUE)
            AND ! $this->_is_admin($user))
        {
            $this->_halt = TRUE;
            $this->_respond(['ok' => FALSE, 'error' => 'Только для администратора'], 403);
        }
    }

    /**
     * Поставить тексты на индексацию (асинхронно, через очередь).
     */
    public function action_index()
    {
        if ($this->_halt) return;

        if ($this->request->method() !== Request::POST)
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);

        $collection = isset($this->_body['collection']) ? (string) $this->_body['collection'] : '';
        $entity     = isset($this->_body['entity'])     ? (string) $this->_body['entity']     : '';
        $items      = isset($this->_body['items']) && is_array($this->_body['items'])
                    ? $this->_body['items'] : [];

        if ($collection === '' OR $entity === '' OR empty($items))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Нужны collection, entity и items'], 400);
        }

        // нормализация items: только безопасные поля
        $clean = [];
        foreach ($items as $item)
        {
            if ( ! is_array($item) OR ! isset($item['id'], $item['text'])) continue;
            $clean[] = [
                'id'       => (string) $item['id'],
                'text'     => (string) $item['text'],
                'metadata' => isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [],
            ];
        }

        if (empty($clean))
            return $this->_respond(['ok' => FALSE, 'error' => 'items пусты: нужны id и text'], 400);

        try
        {
            // queue_index() сам пропускает тексты, чей вектор уже актуален
            // (сравнение по хешу): повторная индексация не жжёт токены.
            // force = TRUE пересчитывает всё (кнопка «Переиндексировать всё»).
            $queued = Service_Prx115_Semantic::queue_index($collection, $entity, $clean, [
                'force' => ! empty($this->_body['force']),
            ]);

            return $this->_respond([
                'ok'      => TRUE,
                'job_ids' => $queued['job_ids'],
                'queued'  => $queued['queued'],
                'skipped' => $queued['skipped'],
            ]);
        }
        catch (Exception $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Семантический поиск (?q=&collection=&limit=&entity=).
     */
    public function action_search()
    {
        if ($this->_halt) return;

        $query      = $this->request->query('q');
        $collection = $this->request->query('collection');
        $limit      = (int) $this->request->query('limit') ?: 10;
        $entity     = $this->request->query('entity') ?: NULL;

        if (trim((string) $query) === '' OR trim((string) $collection) === '')
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Нужны q и collection'], 400);
        }

        $opts = [];

        if ($this->request->query('max_distance') !== NULL AND $this->request->query('max_distance') !== '')
        {
            $opts['max_distance'] = (float) $this->request->query('max_distance');
        }

        if ($this->request->query('hybrid') !== NULL)
        {
            $opts['hybrid'] = (bool) $this->request->query('hybrid');
        }

        // Поиск замеряем и записываем в журнал (prx_115_searches): владелец видит
        // на /prx115, что искали, что нашлось и не отвечал ли всё время кэш.
        $started = microtime(TRUE);

        try
        {
            $results = Service_Prx115_Semantic::search($collection, $query, $limit, $entity, $opts);
            $user    = Auth::instance()->get_user();

            Service_Prx115_Semantic::log_search($collection, $query, $results, [
                'entity'  => (string) $entity,
                'ms'      => (int) round((microtime(TRUE) - $started) * 1000),
                'cached'  => (Service_Prx115_Semantic::last_embed_cached() === TRUE),
                'user_id' => $user ? (int) $user->id : 0,
            ]);

            return $this->_respond([
                'ok'      => TRUE,
                'results' => $results,
                'search'  => Service_Prx115_Semantic::search_config(),
                'used'    => $opts,
            ]);
        }
        catch (Exception $e)
        {
            Service_Prx115_Semantic::log_search($collection, $query, [], [
                'entity' => (string) $entity,
                'ms'     => (int) round((microtime(TRUE) - $started) * 1000),
                'error'  => $e->getMessage(),
            ]);

            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Статистика коллекций.
     */
    public function action_stats()
    {
        if ($this->_halt) return;

        try
        {
            return $this->_respond(['ok' => TRUE, 'stats' => Service_Prx115_Semantic::stats()]);
        }
        catch (Exception $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Всё для страницы /prx115: коллекции, очередь канала, режим поиска.
     */
    public function action_panel()
    {
        if ($this->_halt) return;

        try
        {
            return $this->_respond(['ok' => TRUE, 'panel' => Service_Prx115_Semantic::panel()]);
        }
        catch (Throwable $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Проверить эмбеддинг-сервер: живой вызов, размерность, миллисекунды.
     */
    public function action_check()
    {
        if ($this->_halt) return;

        try
        {
            $check = Service_Prx115_Semantic::embedding_check();

            return $this->_respond(['ok' => (bool) $check['ok'], 'check' => $check], $check['ok'] ? 200 : 502);
        }
        catch (Throwable $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Живой статус последней задачи канала semantic.index.
     *
     * Формат — общий для платформы (partials/live_status): этапы, секундомер,
     * «нет пульса», финал. Панель /prx115 рисует по нему статус-ряд.
     */
    public function action_status()
    {
        if ($this->_halt) return;

        if ( ! class_exists('Service_Prx120_JobQueue'))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Очередь prx120 не подключена'], 503);
        }

        try
        {
            $queue = Service_Prx120_JobQueue::instance();

            // Заходим на панель — заодно разбираем зависшие задачи канала
            // (та же самолечебная логика, что в мониторе воркеров).
            $queue->maybe_maintain();

            // Поля статус-ряда — НА ВЕРХНЕМ уровне: так устроен общий
            // partials/live_status_js (он читает s.status/s.stage/s.stages).
            return $this->_respond($queue->last_status('semantic.index') + ['ok' => TRUE]);
        }
        catch (Throwable $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Повторить задачу канала: тот же payload — новой задачей.
     */
    public function action_retry()
    {
        if ($this->_halt) return;

        if ($this->request->method() !== Request::POST)
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);

        $job_id = (int) ($this->_body['id'] ?? 0);

        if ($job_id <= 0 OR ! class_exists('Service_Prx120_JobQueue'))
            return $this->_respond(['ok' => FALSE, 'error' => 'Нужен id задачи канала semantic.index'], 400);

        try
        {
            $queue  = Service_Prx120_JobQueue::instance();
            $job    = $queue->get($job_id);

            if (empty($job) OR (string) $job['channel'] !== 'semantic.index')
            {
                return $this->_respond(['ok' => FALSE, 'error' => 'Задача не найдена или из другого канала'], 404);
            }

            $new_id = $queue->retry($job_id);

            return $this->_respond(['ok' => $new_id > 0, 'job_id' => $new_id]);
        }
        catch (Throwable $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Запись векторов от воркера (HTTP-режим). Авторизация — токен воркера.
     */
    public function action_vectors()
    {
        if ($this->_halt) return;

        if ($this->request->method() !== Request::POST)
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);

        $collection = isset($this->_body['collection']) ? trim((string) $this->_body['collection']) : '';
        $entity     = isset($this->_body['entity'])     ? trim((string) $this->_body['entity'])     : '';
        $model      = isset($this->_body['model'])      ? (string) $this->_body['model']            : '';
        $items      = isset($this->_body['items']) && is_array($this->_body['items']) ? $this->_body['items'] : [];

        if ($collection === '' OR $entity === '' OR empty($items))
            return $this->_respond(['ok' => FALSE, 'error' => 'Нужны collection, entity и items'], 400);

        // Лимиты: одна задача — один батч (воркер шлёт чанки по 50), вектор
        // разумной размерности. Без лимитов точка превращается в «залей что
        // угодно в базу».
        if (count($items) > 200)
            return $this->_respond(['ok' => FALSE, 'error' => 'Слишком много items в одном запросе (максимум 200)'], 413);

        $clean = [];

        foreach ($items as $item)
        {
            if ( ! is_array($item) OR ! isset($item['id'], $item['vector']) OR ! is_array($item['vector']))
            {
                continue;
            }

            $vector = array_slice(array_values($item['vector']), 0, 4096);

            if (empty($vector))
            {
                continue;
            }

            $clean[] = [
                'id'       => (string) $item['id'],
                'vector'   => array_map('floatval', $vector),
                'text'     => isset($item['text']) ? (string) $item['text'] : '',
                'metadata' => isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [],
            ];
        }

        if (empty($clean))
            return $this->_respond(['ok' => FALSE, 'error' => 'items пусты: нужны id и vector'], 400);

        try
        {
            $stored = Service_Prx115_Semantic::store_vectors($collection, $entity, $clean, $model);

            return $this->_respond(['ok' => TRUE, 'stored' => $stored, 'collection' => $collection]);
        }
        catch (Throwable $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Состояние модуля: движок, покрытие источников, проблемы — одной структурой.
     *
     * Точка для мониторинга: 200 — всё в порядке, 503 — есть проблемы (нет
     * эмбеддинг-сервера, нет таблиц). Сами проблемы перечислены в health.problems.
     */
    public function action_health()
    {
        if ($this->_halt) return;

        try
        {
            $health = Service_Prx115_Semantic::health();

            return $this->_respond(
                ['ok' => (bool) $health['ok'], 'health' => $health],
                $health['ok'] ? 200 : 503
            );
        }
        catch (Throwable $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Покрытие источников индексации (по реестру application/config/prx115.php).
     */
    public function action_coverage()
    {
        if ($this->_halt) return;

        $collection = $this->request->query('collection');

        try
        {
            return $this->_respond([
                'ok'      => TRUE,
                'sources' => Service_Prx115_Semantic::coverage($collection !== NULL ? (string) $collection : NULL),
            ]);
        }
        catch (Throwable $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Догнать отставшие тексты: поставить в очередь то, что изменилось или ещё
     * не проиндексировано (кнопка «Догнать» на панели /prx115).
     */
    public function action_reindex()
    {
        if ($this->_halt) return;

        if ($this->request->method() !== Request::POST)
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);

        $collection = trim((string) (isset($this->_body['collection']) ? $this->_body['collection'] : ''));
        $limit      = (int) (isset($this->_body['limit']) ? $this->_body['limit'] : 200);
        $force      = ! empty($this->_body['force']);

        try
        {
            $result = Service_Prx115_Semantic::reindex(
                $collection !== '' ? $collection : NULL,
                $limit > 0 ? $limit : 200,
                $force ? ['force' => TRUE] : []
            );

            return $this->_respond(['ok' => TRUE] + $result);
        }
        catch (Throwable $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Убрать мусор: векторы записей, которых больше нет в источнике.
     */
    public function action_prune()
    {
        if ($this->_halt) return;

        if ($this->request->method() !== Request::POST)
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);

        $collection = trim((string) (isset($this->_body['collection']) ? $this->_body['collection'] : ''));
        $older_days = (int) (isset($this->_body['older_days']) ? $this->_body['older_days'] : 7);

        try
        {
            $deleted = Service_Prx115_Semantic::prune(
                $collection !== '' ? $collection : NULL,
                $older_days > 0 ? $older_days : 7
            );

            return $this->_respond(['ok' => TRUE, 'deleted' => $deleted]);
        }
        catch (Throwable $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Пользователь — управление?
     */
    protected function _is_admin($user)
    {
        if (method_exists($user, 'has_role') AND $user->has_role('admin'))
        {
            return TRUE;
        }

        try
        {
            foreach ($user->roles->find_all() as $role)
            {
                if ((string) $role->name === 'admin')
                {
                    return TRUE;
                }
            }
        }
        catch (Throwable $e) { /* роли недоступны — значит не админ */ }

        return FALSE;
    }

    /**
     * Проверка токена воркера (как в /prx120/api/*): X-Worker-Token или ?token=.
     */
    protected function _authorize_worker($quiet = FALSE)
    {
        // $quiet = TRUE — «просто проверь»: без ответа и без записи в лог
        // (нужно точке health, где есть второй путь доступа — сессия админа).

        $token = trim((string) getenv('WORKER_API_TOKEN'));

        if ($token === '')
        {
            if (Kohana::$environment === Kohana::DEVELOPMENT)
            {
                return TRUE;
            }

            return $quiet
                ? FALSE
                : $this->_auth_fail('WORKER_API_TOKEN не задан в .env сайта — приём векторов закрыт. Добавьте токен и повторите.');
        }

        $given = $this->request->headers('x-worker-token');

        if ($given === NULL OR trim((string) $given) === '')
        {
            $given = $this->request->query('token');
        }

        if ($given === NULL OR trim((string) $given) === '')
        {
            return $quiet ? FALSE
                : $this->_auth_fail('Токен не передан: ни заголовка X-Worker-Token, ни ?token= в запросе.');
        }

        if ( ! Prx_Gate::worker_request($this->request, TRUE, $token))
        {
            return $quiet ? FALSE
                : $this->_auth_fail('Токен воркера не совпал с WORKER_API_TOKEN сайта (длина на сайте: '.strlen($token).').');
        }

        return TRUE;
    }

    /** Отказ авторизации воркера: запись в лог + JSON-ответ. */
    protected function _auth_fail($reason)
    {
        Kohana::$log->add(Log::ERROR, 'prx115 API: отказ авторизации (:ip): :reason', [
            ':ip'     => Request::$client_ip,
            ':reason' => $reason,
        ]);

        $this->_respond(['ok' => FALSE, 'error' => $reason], 403);

        return FALSE;
    }

    /**
     * Записать JSON в ответ (выполнение продолжится — используйте с $_halt).
     */
    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
