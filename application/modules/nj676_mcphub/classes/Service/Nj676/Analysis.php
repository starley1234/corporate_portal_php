<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ИИ-анализ каталога MCP (nj676): «что у нас реально работает и чего не хватает».
 *
 * Зачем. К 24.10.2026 в каталоге 1045 карточек, 17 готовых к вызову и 124
 * инструмента в схемах — но ни один счётчик не отвечает на вопрос владельца:
 * «что из этого живое, что сломано и почему, и какие темы агенты спрашивают
 * впустую?». Числа показывают состояние, но не дают решения. Решение даёт
 * модель, которой нужен срез данных.
 *
 * Правила платформы соблюдаются:
 *   * модель живёт в воркере, а не на сайте — HTTP-запрос не ждёт LLM;
 *   * работа идёт через очередь nj120 (канал `nj676.analysis`);
 *   * свои таблицы — с префиксом `nj_676_` (миграция 016);
 *   * снимок хранится рядом с отчётом: без него отчёт невозможно проверить.
 *
 * Два среза:
 *   live — состояние каталога: кто отвечает, кто сломан и почему, что чинить
 *          первым (ключа нет, адрес не проходит, проба падает, вызовы падают);
 *   gaps — пробелы: темы, которые агенты спрашивают, а каталог молчит.
 * `both` — оба раздела в одном отчёте, по умолчанию.
 */
class Service_Nj676_Analysis
{
    /** Что можно попросить у модели. */
    const MODES = [
        'both' => 'состояние и пробелы',
        'live' => 'состояние каталога',
        'gaps' => 'чего не хватает',
    ];

    /** Сколько записей истории показывать в админке. */
    const HISTORY = 6;

    // ---------------------------------------------------------------------
    // Снимок: что именно видит модель
    // ---------------------------------------------------------------------

    /**
     * Срез каталога для модели.
     *
     * Сознательно компактный: у модели ограниченный контекст, а ей нужны
     * примеры и причины, а не тысяча слагов. Всё, что не влезло, описано
     * числами в `catalog` — модели хватает масштаба.
     *
     * @return array<string,mixed>
     */
    public static function snapshot()
    {
        $stats = Service_Nj676_McpHub::stats();
        $ready = Service_Nj676_McpHub::ready_overview();
        $probe = Service_Nj676_McpHub::probe_backlog();
        $calls = Service_Nj676_Telemetry::stats();

        return [
            'generated_at' => date('c'),
            'catalog' => [
                'servers' => (int) ($stats['servers'] ?? 0),
                'tools' => (int) ($stats['tools'] ?? 0),
                'categories' => (int) ($stats['categories'] ?? 0),
                'operational_probe' => (int) ($stats['operational'] ?? 0),
            ],
            'ready' => [
                'servers' => (int) ($ready['servers'] ?? 0),
                'tools' => (int) ($ready['tools'] ?? 0),
                'error' => (string) ($ready['error'] ?? ''),
            ],
            'probe' => [
                'remote' => (int) ($probe['remote'] ?? 0),
                'never' => (int) ($probe['never'] ?? 0),
                'stale' => (int) ($probe['stale'] ?? 0),
                'fresh' => (int) ($probe['fresh'] ?? 0),
                'stale_days' => (int) ($probe['days'] ?? 7),
            ],
            'calls' => $calls,
            'live' => self::_live_list($ready),
            'blocked' => self::_blocked(),
            'failing' => self::_failing(),
            'demand' => self::_demand(),
        ];
    }

    /** Кто готов к вызову прямо сейчас — короткий список примеров. */
    protected static function _live_list(array $ready)
    {
        $out = [];

        foreach (array_slice((array) ($ready['list'] ?? []), 0, 20) as $server)
        {
            $tools = [];
            foreach (array_slice((array) ($server['tools'] ?? []), 0, 4) as $tool)
            {
                if (is_array($tool) AND ! empty($tool['name']))
                {
                    $tools[] = (string) $tool['name'];
                }
            }

            $out[] = [
                'slug' => (string) ($server['slug'] ?? ''),
                'name' => (string) ($server['name_ru'] ?? '') !== ''
                    ? (string) $server['name_ru'] : (string) ($server['name'] ?? ''),
                'tools' => count((array) ($server['tools'] ?? [])),
                'examples' => array_slice($tools, 0, 3),
                'latency_ms' => $server['latency_ms'] ?? NULL,
                'auth' => (string) ($server['auth'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Почему серверы недоступны — с причиной, а не просто «не готов».
     *
     * Модель не должна гадать: «нужен ключ» и «адрес не проходит проверку» —
     * разные задачи с разной ценой решения.
     */
    protected static function _blocked()
    {
        $out = ['needs_key' => [], 'invalid_url' => [], 'probe_failed' => []];

        try
        {
            $rows = Service_Nj676_McpHub::query_raw(
                "SELECT slug, name, auth_mode, url_note, health_status, health_note,
                        auth_required_at, health_checked_at
                   FROM nj_676_servers
                  WHERE is_published = TRUE AND remote_url <> ''
                    AND (auth_required_at IS NOT NULL
                         OR url_valid IS FALSE
                         OR health_status = 'offline')
                  ORDER BY health_checked_at DESC NULLS LAST
                  LIMIT 40"
            )->as_array();
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::WARNING, 'nj676: срез проблемных серверов не прочитан: :e',
                [':e' => $e->getMessage()]);

            return $out;
        }

        foreach ($rows as $row)
        {
            $row = (array) $row;
            $item = [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'auth_mode' => (string) $row['auth_mode'],
            ];

            if ( ! empty($row['auth_required_at']))
            {
                $item['why'] = 'сервер ответил «нужен ключ» '.self::_ago((string) $row['auth_required_at']);

                if (count($out['needs_key']) < 12)
                {
                    $out['needs_key'][] = $item;
                }

                continue;
            }

            if (trim((string) $row['url_note']) !== '')
            {
                $item['why'] = mb_substr((string) $row['url_note'], 0, 160);

                if (count($out['invalid_url']) < 8)
                {
                    $out['invalid_url'][] = $item;
                }

                continue;
            }

            $error = trim((string) ($row['health_note'] ?? ''));
            $item['why'] = $error !== '' ? mb_substr($error, 0, 160) : 'проба не прошла';

            if (count($out['probe_failed']) < 12)
            {
                $out['probe_failed'][] = $item;
            }
        }

        return $out;
    }

    /** Серверы, на которых агенты реально спотыкаются (телеметрия вызовов). */
    protected static function _failing()
    {
        try
        {
            $rows = Service_Nj676_McpHub::query_raw(
                "SELECT server_slug AS slug,
                        count(*) AS calls,
                        count(*) FILTER (WHERE NOT ok) AS failed,
                        max(created_at) AS last_at
                   FROM nj_676_calls
                  WHERE created_at > now() - interval '7 days' AND server_slug <> ''
                  GROUP BY server_slug
                 HAVING count(*) FILTER (WHERE NOT ok) > 0
                  ORDER BY count(*) FILTER (WHERE NOT ok) DESC, count(*) DESC
                  LIMIT 12"
            )->as_array();
        }
        catch (Throwable $e)
        {
            return [];
        }

        $out = [];
        foreach ($rows as $row)
        {
            $row = (array) $row;

            $out[] = [
                'slug' => (string) $row['slug'],
                'calls' => (int) $row['calls'],
                'failed' => (int) $row['failed'],
                'last_at' => (string) ($row['last_at'] ?? ''),
            ];
        }

        return $out;
    }

    /** Спрос: что спрашивают чаще всего и что не находит ничего. */
    protected static function _demand()
    {
        $top = [];
        $empty = [];

        foreach (Service_Nj676_Demand::top(7, 12) as $row)
        {
            $row = (array) $row;

            $top[] = [
                'query' => (string) ($row['sample'] ?? $row['norm'] ?? ''),
                'searches' => (int) ($row['searches'] ?? 0),
                'empty' => (int) ($row['empty'] ?? 0),
                'best_servers' => (int) ($row['best'] ?? 0),
            ];
        }

        foreach (Service_Nj676_Demand::unresolved(30, 15) as $row)
        {
            $row = (array) $row;

            $empty[] = [
                'query' => (string) ($row['sample'] ?? $row['norm'] ?? ''),
                'searches' => (int) ($row['searches'] ?? 0),
                'last_seen' => (string) ($row['last_seen'] ?? ''),
            ];
        }

        return ['top' => $top, 'empty' => $empty];
    }

    /** «2 дня назад» — для причины в срезе (модель читает это как факт). */
    protected static function _ago($time)
    {
        $age = max(0, time() - (int) strtotime((string) $time));

        if ($age < 3600)
        {
            return round($age / 60).' мин назад';
        }

        if ($age < 86400)
        {
            return round($age / 3600).' ч назад';
        }

        return round($age / 86400).' дн назад';
    }

    // ---------------------------------------------------------------------
    // Записи анализа: постановка, снимок, отчёт
    // ---------------------------------------------------------------------

    /**
     * Поставить анализ в очередь.
     *
     * @param  string   $mode     both|live|gaps
     * @param  int|NULL $user_id
     * @return array{id:int,job_id:int,mode:string,error:string}
     */
    public static function create($mode = 'both', $user_id = NULL)
    {
        $mode = isset(self::MODES[$mode]) ? $mode : 'both';
        $out = ['id' => 0, 'job_id' => 0, 'mode' => $mode, 'error' => ''];

        // Один анализ за раз: два одинаковых прогона модели — это двойные
        // токены ради одного и того же ответа.
        $active = self::active();

        if ($active)
        {
            $out['id'] = (int) $active['id'];
            $out['error'] = 'analysis_active';

            return $out;
        }

        try
        {
            $row = Service_Nj676_McpHub::query_raw(
                'INSERT INTO nj_676_analysis (status, mode, created_by, snapshot)
                 VALUES (:status, :mode, :by, :snapshot)
                 RETURNING id',
                [
                    ':status' => 'queued',
                    ':mode' => $mode,
                    ':by' => $user_id !== NULL ? (int) $user_id : NULL,
                    ':snapshot' => json_encode(self::snapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            )->current();
        }
        catch (Throwable $e)
        {
            $out['error'] = mb_substr($e->getMessage(), 0, 300, 'UTF-8');

            Log_Plain::event(Log::WARNING, 'nj676: анализ не создан: :e', [':e' => $e->getMessage()]);

            return $out;
        }

        $out['id'] = (int) ($row['id'] ?? 0);

        if ($out['id'] <= 0)
        {
            $out['error'] = 'запись анализа не создана';

            return $out;
        }

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj676.analysis', [
                'analysis_id' => $out['id'],
                'mode' => $mode,
            ], [
                'module' => 'nj676',
                // Модель с большим снимком: 10 минут — с запасом на длинный
                // ответ, но задача не должна висеть вечно.
                'timeout_sec' => 600,
                'created_by' => $user_id !== NULL ? (int) $user_id : 0,
            ]);

            $out['job_id'] = (int) $job_id;

            Service_Nj676_McpHub::query_raw(
                'UPDATE nj_676_analysis SET job_id = :job WHERE id = :id',
                [':job' => (int) $job_id, ':id' => $out['id']]
            );
        }
        catch (Throwable $e)
        {
            $out['error'] = 'очередь недоступна: '.mb_substr($e->getMessage(), 0, 200, 'UTF-8');

            self::fail($out['id'], $out['error']);
        }

        return $out;
    }

    /**
     * Взять запись анализа (воркер) и отметить начало.
     *
     * @return array<string,mixed>|NULL
     */
    public static function start($id)
    {
        $id = (int) $id;
        $row = self::get($id);

        if ( ! $row)
        {
            return NULL;
        }

        try
        {
            Service_Nj676_McpHub::query_raw(
                "UPDATE nj_676_analysis
                    SET status = 'running', started_at = coalesce(started_at, now())
                  WHERE id = :id AND status IN ('queued', 'running')",
                [':id' => $id]
            );
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::DEBUG, 'nj676: статус анализа не обновлён: :e', [':e' => $e->getMessage()]);
        }

        return $row;
    }

    /**
     * Сохранить отчёт модели.
     *
     * @param  int   $id
     * @param  array $data report, error, ms, model, status (done|fail)
     * @return bool
     */
    public static function finish($id, array $data)
    {
        $id = (int) $id;

        if ($id <= 0)
        {
            return FALSE;
        }

        $status = (string) ($data['status'] ?? 'done');
        $status = in_array($status, ['done', 'fail'], TRUE) ? $status : 'done';

        $report = isset($data['report']) && is_array($data['report'])
            ? self::normalize_report($data['report'])
            : NULL;

        try
        {
            Service_Nj676_McpHub::query_raw(
                'UPDATE nj_676_analysis
                    SET status = :status,
                        finished_at = now(),
                        report = :report,
                        error = :error,
                        ms = :ms,
                        model = :model
                  WHERE id = :id',
                [
                    ':status' => $status,
                    ':report' => $report !== NULL
                        ? json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : NULL,
                    ':error' => mb_substr(trim((string) ($data['error'] ?? '')), 0, 600, 'UTF-8'),
                    ':ms' => max(0, (int) ($data['ms'] ?? 0)),
                    ':model' => mb_substr(trim((string) ($data['model'] ?? '')), 0, 120, 'UTF-8'),
                    ':id' => $id,
                ]
            );
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::WARNING, 'nj676: отчёт анализа не сохранён: :e', [':e' => $e->getMessage()]);

            return FALSE;
        }

        return TRUE;
    }

    /** Отметить анализ упавшим (воркер не смог, очередь недоступна). */
    public static function fail($id, $error)
    {
        return self::finish($id, [
            'status' => 'fail',
            'error' => mb_substr(trim((string) $error), 0, 600, 'UTF-8'),
        ]);
    }

    /** Одна запись анализа по номеру. */
    public static function get($id)
    {
        $id = (int) $id;

        if ($id <= 0)
        {
            return NULL;
        }

        try
        {
            $row = Service_Nj676_McpHub::query_raw(
                'SELECT * FROM nj_676_analysis WHERE id = :id',
                [':id' => $id]
            )->current();
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::WARNING, 'nj676: анализ не прочитан: :e', [':e' => $e->getMessage()]);

            return NULL;
        }

        return $row ? self::_format((array) $row) : NULL;
    }

    /** Идущий анализ (queued|running) — чтобы не ставить второй. */
    public static function active()
    {
        try
        {
            $row = Service_Nj676_McpHub::query_raw(
                "SELECT * FROM nj_676_analysis
                  WHERE status IN ('queued', 'running')
                  ORDER BY id DESC LIMIT 1"
            )->current();
        }
        catch (Throwable $e)
        {
            return NULL;
        }

        return $row ? self::_format((array) $row) : NULL;
    }

    /** Последний завершённый анализ (его и показывает админка). */
    public static function latest()
    {
        try
        {
            $row = Service_Nj676_McpHub::query_raw(
                "SELECT * FROM nj_676_analysis
                  WHERE status IN ('done', 'fail')
                  ORDER BY id DESC LIMIT 1"
            )->current();
        }
        catch (Throwable $e)
        {
            return NULL;
        }

        return $row ? self::_format((array) $row) : NULL;
    }

    /** История: последние записи, свежие первыми. */
    public static function history($limit = self::HISTORY)
    {
        try
        {
            $rows = Service_Nj676_McpHub::query_raw(
                "SELECT id, status, mode, created_at, finished_at, model, error, ms,
                        report->>'verdict' AS verdict
                   FROM nj_676_analysis
                  ORDER BY id DESC LIMIT ".max(1, min(30, (int) $limit))
            )->as_array();
        }
        catch (Throwable $e)
        {
            return [];
        }

        $out = [];

        foreach ($rows as $row)
        {
            $row = (array) $row;

            $out[] = [
                'id' => (int) $row['id'],
                'status' => (string) $row['status'],
                'mode' => (string) $row['mode'],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'finished_at' => (string) ($row['finished_at'] ?? ''),
                'model' => (string) ($row['model'] ?? ''),
                'error' => (string) ($row['error'] ?? ''),
                'ms' => (int) ($row['ms'] ?? 0),
                'verdict' => (string) ($row['verdict'] ?? ''),
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Разбор отчёта
    // ---------------------------------------------------------------------

    /**
     * Отчёт модели → структура, которую можно показать человеку.
     *
     * Почему не «как прислала модель». Отчёт рисуется в админке без markdown и
     * без доверия к модели: только известные поля, обрезанные по длине. Иначе
     * один ответ модели ломает вёрстку или тянет в страницу чужие ссылки.
     *
     * @param  array $report
     * @return array<string,mixed>
     */
    public static function normalize_report(array $report)
    {
        $out = [
            'verdict' => self::_text($report['verdict'] ?? '', 600),
            'live_notes' => self::_text($report['live_notes'] ?? $report['live'] ?? '', 1500),
            'gaps' => [],
            'problems' => [],
            'actions' => [],
            'collect' => [],
        ];

        foreach (array_slice((array) ($report['gaps'] ?? []), 0, 12) as $gap)
        {
            if (is_string($gap))
            {
                $out['gaps'][] = ['theme' => self::_text($gap, 120), 'why' => '', 'queries' => []];

                continue;
            }

            if ( ! is_array($gap))
            {
                continue;
            }

            $queries = [];
            foreach (array_slice((array) ($gap['queries'] ?? []), 0, 6) as $query)
            {
                if (is_scalar($query))
                {
                    $queries[] = self::_text($query, 120);
                }
            }

            $out['gaps'][] = [
                'theme' => self::_text($gap['theme'] ?? $gap['name'] ?? '', 120),
                'why' => self::_text($gap['why'] ?? $gap['reason'] ?? '', 400),
                'queries' => $queries,
            ];
        }

        foreach (array_slice((array) ($report['problems'] ?? []), 0, 12) as $problem)
        {
            if (is_string($problem))
            {
                $out['problems'][] = ['what' => self::_text($problem, 300), 'fix' => ''];

                continue;
            }

            if ( ! is_array($problem))
            {
                continue;
            }

            $out['problems'][] = [
                'what' => self::_text($problem['what'] ?? $problem['problem'] ?? '', 300),
                'fix' => self::_text($problem['fix'] ?? $problem['action'] ?? '', 400),
                'slug' => self::_text($problem['slug'] ?? '', 100),
            ];
        }

        foreach (array_slice((array) ($report['actions'] ?? []), 0, 10) as $action)
        {
            if (is_scalar($action))
            {
                $out['actions'][] = self::_text($action, 300);
            }
            elseif (is_array($action))
            {
                $out['actions'][] = self::_text($action['action'] ?? $action['text'] ?? '', 300);
            }
        }

        // Темы для «Найти и добавить»: с ними владелец нажимает кнопку сбора,
        // а не придумывает формулировку сам.
        foreach (array_slice((array) ($report['collect'] ?? []), 0, 8) as $theme)
        {
            if (is_scalar($theme))
            {
                $out['collect'][] = self::_text($theme, 120);
            }
            elseif (is_array($theme) AND isset($theme['theme']))
            {
                $out['collect'][] = self::_text($theme['theme'], 120);
            }
        }

        $out['gaps'] = array_values(array_filter($out['gaps'], static function ($gap) {
            return $gap['theme'] !== '';
        }));
        $out['problems'] = array_values(array_filter($out['problems'], static function ($problem) {
            return $problem['what'] !== '';
        }));
        $out['actions'] = array_values(array_filter($out['actions'], static function ($action) {
            return $action !== '';
        }));
        $out['collect'] = array_values(array_filter($out['collect'], static function ($theme) {
            return $theme !== '';
        }));

        return $out;
    }

    /** Строка из ответа модели: без управляющих символов, обрезанная по длине. */
    protected static function _text($value, $limit)
    {
        if ( ! is_scalar($value))
        {
            return '';
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return mb_substr($text, 0, max(1, (int) $limit));
    }

    /**
     * Запись БД → вид для админки: jsonb приходит строкой.
     *
     * @param  array $row
     * @return array<string,mixed>
     */
    protected static function _format(array $row)
    {
        $decode = static function ($value) {
            if (is_array($value))
            {
                return $value;
            }

            $decoded = json_decode((string) $value, TRUE);

            return is_array($decoded) ? $decoded : [];
        };

        return [
            'id' => (int) ($row['id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'mode' => (string) ($row['mode'] ?? 'both'),
            'mode_title' => self::MODES[(string) ($row['mode'] ?? 'both')] ?? self::MODES['both'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'finished_at' => (string) ($row['finished_at'] ?? ''),
            'job_id' => (int) ($row['job_id'] ?? 0),
            'model' => (string) ($row['model'] ?? ''),
            'error' => (string) ($row['error'] ?? ''),
            'ms' => (int) ($row['ms'] ?? 0),
            'report' => $decode($row['report'] ?? NULL),
            'snapshot' => $decode($row['snapshot'] ?? NULL),
            'running' => in_array((string) ($row['status'] ?? ''), ['queued', 'running'], TRUE),
        ];
    }
}
