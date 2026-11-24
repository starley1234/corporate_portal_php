<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Монитор очереди prx120: здоровье контура и объяснение «почему висит».
 *
 * Трейт читает те же таблицы, что и Service_Prx120_JobQueue, и опирается на
 * пороги из Service_Prx120_JobQueue_Maintenance (_pulse_sql, silent_stop_sec,
 * stalled_progress_sec, max_age_sec). Побочных эффектов нет — только SELECT.
 */
trait Service_Prx120_JobQueue_Monitor
{
    /** Серверная сводка для административных экранов, не пользовательский список. Только SELECT. */
    public function platform_metrics(): array
    {
        return $this->_report_row("SELECT
            count(*) FILTER (WHERE status = 'pending') AS pending,
            count(*) FILTER (WHERE status = 'processing') AS processing,
            count(*) FILTER (WHERE status = 'failed') AS failed,
            count(*) FILTER (WHERE status = 'failed' AND coalesce(finished_at, created_at) > now() - interval '24 hours') AS failed24,
            count(*) FILTER (WHERE status = 'done' AND coalesce(finished_at, created_at) > now() - interval '24 hours') AS done24,
            count(*) FILTER (WHERE status = 'processing' AND coalesce(heartbeat_at, claimed_at, created_at) < now() - interval '15 minutes') AS stuck,
            count(*) FILTER (WHERE status = 'processing' AND heartbeat_at < now() - interval '15 minutes') AS heartbeat_stuck
            FROM prx_120_jobs");
    }

    /** Наблюдавшиеся имена воркеров, не доказательство запущенных процессов. */
    public function worker_activity(): array
    {
        return $this->_report_row("SELECT
            count(DISTINCT claimed_by) FILTER (WHERE coalesce(heartbeat_at, finished_at, created_at) > now() - interval '24 hours') AS day,
            count(DISTINCT claimed_by) FILTER (WHERE coalesce(heartbeat_at, finished_at, created_at) > now() - interval '10 minutes') AS live,
            extract(epoch FROM now() - max(coalesce(heartbeat_at, finished_at)))::int AS ago
            FROM prx_120_jobs WHERE claimed_by IS NOT NULL AND claimed_by <> ''");
    }

    public function usage_today(): array
    {
        return $this->_report_row("SELECT coalesce(sum(prompt_tokens + completion_tokens), 0) AS tokens, count(*) AS jobs
            FROM prx_120_usage WHERE created_at >= date_trunc('day', now())");
    }

    /** Ограниченная административная проекция: без payload/result/error/callback/worker-токенов. */
    public function admin_activity(): array
    {
        $kpi = $this->_report_row("SELECT
            count(*) FILTER (WHERE created_at > now() - interval '24 hours') AS d1,
            count(*) FILTER (WHERE created_at > now() - interval '24 hours' AND visitor_id LIKE 'u:%') AS d1_users,
            count(*) FILTER (WHERE created_at > now() - interval '24 hours' AND visitor_id LIKE 'g:%') AS d1_guests,
            count(DISTINCT visitor_id) FILTER (WHERE created_at > now() - interval '24 hours') AS d1_visitors
            FROM prx_120_jobs");
        $channels = $this->_db->query(Database::SELECT, "SELECT channel, count(*) AS c,
            count(*) FILTER (WHERE visitor_id LIKE 'g:%') AS guests
            FROM prx_120_jobs WHERE created_at > now() - interval '24 hours'
            GROUP BY channel ORDER BY c DESC LIMIT 12", FALSE)->as_array();
        $jobs = $this->_db->query(Database::SELECT, 'SELECT id, channel, status, visitor_id, created_at, finished_at
            FROM prx_120_jobs ORDER BY id DESC LIMIT 120', FALSE)->as_array();
        return ['kpi' => $kpi, 'channels' => $channels, 'jobs' => $jobs];
    }

    /** Счётчики одного канала, без возврата payload/result. Канал всегда квотирован. */
    public function channel_metrics(string $channel): array
    {
        return $this->_report_row("SELECT
            count(*) FILTER (WHERE status = 'pending' AND created_at > now() - interval '24 hours') AS pending,
            count(*) FILTER (WHERE status = 'processing' AND created_at > now() - interval '24 hours') AS processing,
            count(*) FILTER (WHERE status = 'done' AND created_at > now() - interval '24 hours') AS done_24h,
            count(*) FILTER (WHERE status = 'failed' AND created_at > now() - interval '24 hours') AS failed_24h,
            count(*) FILTER (WHERE status IN ('pending', 'processing') AND created_at > now() - interval '24 hours') AS queued_recent,
            count(*) FILTER (WHERE (result ->> 'skipped' IS NOT NULL OR result ->> 'mode' = 'http-bridge')
                AND created_at > now() - interval '7 days') AS bridge
            FROM prx_120_jobs WHERE channel = ".$this->_db->quote($channel));
    }

    /** Ошибка БД распространяется вызывающему экрану; нулевые значения не маскируют сбой. */
    protected function _report_row(string $sql): array
    {
        $row = $this->_db->query(Database::SELECT, $sql, FALSE)->current();
        $out = [];
        foreach ((array) $row as $key => $value) $out[$key] = $value === NULL ? NULL : (int) $value;
        return $out;
    }

    /**
     * Здоровье воркерного контура: счётчики по статусам, пульс воркера
     * (heartbeat исполняющихся задач), «застрявшие», имена воркеров за 24 ч,
     * что воркер делает СЕЙЧАС и чем завершились последние задачи.
     */
    public function monitor_health()
    {
        $db = $this->_db;

        $counts = [];
        foreach ($db->query(Database::SELECT,
            'SELECT status, count(*) AS c FROM prx_120_jobs GROUP BY status', FALSE)->as_array() as $r)
        {
            $counts[(string) $r['status']] = (int) $r['c'];
        }

        // «Застряли» — ровно те строки, которые уборка (requeue_stale) закроет
        // в этом же проходе: пульс молчит дольше порога авто-стопа ИЛИ прогресс
        // не обновлялся дольше порога «пульс есть, работы нет». Раньше счётчик
        // смотрел только на heartbeat_at (и считал «пустым пульсом» живую
        // строку) — число расходилось с правилами уборки: монитор показывал
        // «завис» там, где закрывать нечего, и молчал там, где надо чистить.
        $silent  = $this->silent_stop_sec();
        $stalled = $this->stalled_progress_sec();

        $beat = $db->query(Database::SELECT,
            "SELECT max(".$this->_pulse_sql().") AS beat,
                    count(*) FILTER (WHERE ".$this->_pulse_sql()." < now() - ".(int) $silent." * interval '1 second') AS silent_now,
                    count(*) FILTER (WHERE COALESCE(
                              (SELECT max(e.created_at) FROM prx_120_job_events e WHERE e.job_id = j.id),
                              j.claimed_at, j.created_at) < now() - ".(int) $stalled." * interval '1 second') AS stalled_now,
                    count(*) FILTER (WHERE ".$this->_pulse_sql()." < now() - ".(int) $silent." * interval '1 second'
                                        OR COALESCE(
                                              (SELECT max(e.created_at) FROM prx_120_job_events e WHERE e.job_id = j.id),
                                              j.claimed_at, j.created_at) < now() - ".(int) $stalled." * interval '1 second') AS stuck
             FROM prx_120_jobs j WHERE j.status = 'processing'", FALSE)->current();

        $workers = [];
        foreach ($db->query(Database::SELECT,
            "SELECT DISTINCT claimed_by FROM prx_120_jobs
             WHERE claimed_by IS NOT NULL AND claimed_by <> ''
               AND coalesce(heartbeat_at, finished_at, created_at) > now() - interval '24 hours'
             LIMIT 10", FALSE)->as_array() as $r)
        {
            $workers[] = (string) $r['claimed_by'];
        }

        // Чем занят воркер СЕЙЧАС: исполняющиеся задачи (по свежести пульса).
        $now_doing = [];
        foreach ($db->query(Database::SELECT,
            'SELECT j.channel, j.payload, j.heartbeat_at, j.started_at, j.claimed_by,
                    j.claimed_at, j.created_at, ev.message AS stage, ev.created_at AS stage_at
               FROM prx_120_jobs j
               LEFT JOIN LATERAL (
                   SELECT e.message, e.created_at FROM prx_120_job_events e
                    WHERE e.job_id = j.id AND e.event = \'progress\'
                      AND e.message IS NOT NULL AND e.message <> \'\'
                    ORDER BY e.id DESC LIMIT 1
               ) ev ON TRUE
              WHERE j.status = \'processing\'
              ORDER BY coalesce(j.heartbeat_at, j.claimed_at, j.started_at, j.created_at) DESC NULLS LAST
              LIMIT 5', FALSE)->as_array() as $r)
        {
            $now_doing[] = [
                'channel' => (string) $r['channel'],
                'label'   => self::channel_label((string) $r['channel']),
                'title'   => self::job_title_of($r['payload']),
                'stage'   => (string) ($r['stage'] ?? ''),
                'worker'  => (string) ($r['claimed_by'] ?? ''),
                'beat_at' => (string) ($r['heartbeat_at'] ?? $r['started_at'] ?? ''),
                'claimed_at' => (string) ($r['claimed_at'] ?? ''),
                // Возраст последнего рапорта: по нему видно «пульс есть, работы
                // нет» — раньше монитор показывал только пульс, и застывший
                // этап выглядел как живая работа.
                'progress_ago' => ! empty($r['stage_at'])
                    ? max(0, time() - strtotime((string) $r['stage_at']))
                    : NULL,
            ];
        }

        // Чем воркер занимался ПОСЛЕДНИМ: свежие завершённые задачи.
        $last_finished = [];
        foreach ($db->query(Database::SELECT,
            'SELECT channel, status, finished_at, claimed_by
               FROM prx_120_jobs
              WHERE status IN (\'done\', \'failed\') AND finished_at IS NOT NULL
              ORDER BY finished_at DESC LIMIT 3', FALSE)->as_array() as $r)
        {
            $last_finished[] = [
                'channel'  => (string) $r['channel'],
                'label'    => self::channel_label((string) $r['channel']),
                'status'   => (string) $r['status'],
                'worker'   => (string) ($r['claimed_by'] ?? ''),
                'finish_at'=> (string) $r['finished_at'],
            ];
        }

        $avg_ms = NULL;

        try
        {
            $r = $db->query(Database::SELECT,
                "SELECT avg(elapsed_ms)::int AS a FROM prx_120_usage
                 WHERE created_at > now() - interval '24 hours'", FALSE)->current();

            if ($r AND $r['a'] !== NULL)
            {
                $avg_ms = (int) $r['a'];
            }
        }
        catch (Throwable $e) { /* таблицы ещё нет — не страшно */ }

        $processing = (int) ($counts['processing'] ?? 0);
        $beat_ts    = ($beat AND ! empty($beat['beat'])) ? strtotime((string) $beat['beat']) : NULL;
        $stuck      = $beat ? (int) $beat['stuck'] : 0;

        // Вердикт — по тому же счёту, что и «застряли»: он отвечает на вопрос
        // «есть ли что чистить», а не «был ли пульс последние 2 минуты».
        $verdict = 'idle';

        if ($processing > 0)
        {
            $verdict = $stuck > 0 ? 'stuck' : 'ok';
        }

        return [
            'counts'         => $counts,
            'processing'     => $processing,
            'stuck'          => $stuck,
            'silent_now'     => $beat ? (int) $beat['silent_now'] : 0,
            'stalled_now'    => $beat ? (int) $beat['stalled_now'] : 0,
            'silent_sec'     => $silent,
            'stalled_sec'    => $stalled,
            'max_age_sec'    => $this->max_age_sec(),
            'last_beat'      => $beat_ts,
            'beat_ago'       => $beat_ts ? (time() - $beat_ts) : NULL,
            'workers'        => $workers,
            'now_doing'      => $now_doing,
            'last_finished'  => $last_finished,
            'done_24h'       => (int) ($db->query(Database::SELECT,
                "SELECT count(*) AS c FROM prx_120_jobs WHERE status = 'done'
                 AND finished_at > now() - interval '24 hours'", FALSE)->current()['c'] ?? 0),
            'failed_24h'     => (int) ($db->query(Database::SELECT,
                "SELECT count(*) AS c FROM prx_120_jobs WHERE status = 'failed'
                 AND finished_at > now() - interval '24 hours'", FALSE)->current()['c'] ?? 0),
            'avg_ms_24h'     => $avg_ms,
            'verdict'        => $verdict, // ok | stuck | idle
        ];
    }

    /**
     * Диагноз задачи: «почему висит и что с этим будет».
     *
     * Владелец (28.09): «Почему монитор воркеров всегда показывает зависший
     * процесс?» — по карточке задачи (#969, semantic.index, попытки 3/3,
     * «Выполняется») было непонятно, ждать ли, чинить ли воркер и когда это
     * закончится. Здесь это ОДНО место: возраст пульса, возраст последнего
     * рапорта о ходе, применимое правило уборки и через сколько секунд оно
     * сработает.
     *
     * @param  int $job_id
     * @return array|NULL NULL — задачи нет
     */
    public function diagnose($job_id)
    {
        $row = $this->get((int) $job_id);

        if ($row === NULL)
        {
            return NULL;
        }

        $status  = (string) $row['status'];
        $silent  = $this->silent_stop_sec();
        $stalled = $this->stalled_progress_sec();
        $max_age = $this->max_age_sec();
        $timeout = (int) ($row['timeout_sec'] ?? 0);
        $now     = time();

        // Пульс: heartbeat_at → claimed_at → started_at → created_at
        // (то же выражение, что в SQL уборки — см. _pulse_sql()).
        $pulse_ts = NULL;

        foreach (['heartbeat_at', 'claimed_at', 'started_at', 'created_at'] as $field)
        {
            if ( ! empty($row[$field]))
            {
                $pulse_ts = strtotime((string) $row[$field]);
                break;
            }
        }

        // Прогресс: последнее событие журнала, иначе захват/постановка.
        $progress_ts = NULL;

        try
        {
            $ev = $this->_db->query(Database::SELECT,
                "SELECT max(created_at) AS at FROM prx_120_job_events WHERE job_id = ".(int) $job_id, FALSE)->current();

            if ($ev AND ! empty($ev['at']))
            {
                $progress_ts = strtotime((string) $ev['at']);
            }
        }
        catch (Throwable $e) { /* журнала нет — считаем по захвату */ }

        foreach (['claimed_at', 'created_at'] as $field)
        {
            if ($progress_ts === NULL AND ! empty($row[$field]))
            {
                $progress_ts = strtotime((string) $row[$field]);
            }
        }

        $pulse_ago    = $pulse_ts !== NULL ? max(0, $now - $pulse_ts) : NULL;
        $progress_ago = $progress_ts !== NULL ? max(0, $now - $progress_ts) : NULL;

        $result = [
            'status'       => $status,
            'attempts'     => (int) ($row['attempts'] ?? 0),
            'max_attempts' => (int) ($row['max_attempts'] ?? 0),
            'pulse_ago'    => $pulse_ago,
            'progress_ago' => $progress_ago,
            'silent_sec'   => $silent,
            'stalled_sec'  => $stalled,
            'max_age_sec'  => $max_age,
            'close_in_sec' => NULL,
            'action'       => 'terminal',
            'text'         => 'Задача завершена — смотрите журнал ниже.',
        ];

        if ($status !== self::STATUS_PROCESSING)
        {
            if ($status === self::STATUS_PENDING)
            {
                $result['text'] = 'Задача ждёт свободного воркера: если воркер не заберёт её дольше '
                    .self::_ago_text(43200).', её закроют, чтобы не висела в «Ожидает» вечно.';
            }

            return $result;
        }

        // Возраст задачи считаем от НАЧАЛА работы, а не от пульса: пульс
        // обновляется, поэтому «возраст по пульсу» никогда не растёт. Ровно так
        // же считает правило в requeue_stale().
        $age_ts     = NULL;

        foreach (['started_at', 'claimed_at', 'created_at'] as $field)
        {
            if ( ! empty($row[$field]))
            {
                $age_ts = strtotime((string) $row[$field]);
                break;
            }
        }

        // Правила уборки: сначала те, чей порог УЖЕ пройден (их и закроет
        // ближайшая уборка), иначе — гарантированный предельный возраст.
        $triggered = [];

        if ($pulse_ago !== NULL AND $pulse_ago >= $silent)
        {
            $triggered[] = ['will_close_silent', $silent - $pulse_ago, $pulse_ago,
                'Воркер не подаёт пульс уже '.self::_ago_text($pulse_ago).' (порог '.self::_ago_text($silent).').'];
        }

        if ($progress_ago !== NULL AND $progress_ago >= $stalled)
        {
            $triggered[] = ['will_close_stalled', $stalled - $progress_ago, $progress_ago,
                'Пульс идёт, но рапортов о ходе нет уже '.self::_ago_text($progress_ago).' (порог '.self::_ago_text($stalled).').'];
        }

        $age_limit = $max_age > 0 ? max($timeout * 3, $max_age) : NULL;

        if ($age_limit !== NULL AND $age_ts !== NULL AND ($now - $age_ts) >= $age_limit)
        {
            $triggered[] = ['will_close_age', $age_limit - ($now - $age_ts), $now - $age_ts,
                'Задача идёт '.self::_ago_text($now - $age_ts).' при предельных '.self::_ago_text($age_limit).'.'];
        }

        if ($triggered)
        {
            // Самое «просроченное» правило и есть причина, по которой строку
            // закроют: у него порог пройден раньше всех.
            usort($triggered, function ($a, $b) { return $a[1] <=> $b[1]; });

            list($action, $left, $ago, $text) = $triggered[0];

            $result['action']       = $action;
            $result['close_in_sec'] = 0;
            $result['text']         = $text.' Порог пройден — задача закроется при первой уборке очереди '
                .'(она идёт при каждом заходе на сайт и по крону), авто-стоп даст «Ошибку» или «Отменено». '
                .'Если ждать не нужно — «Прервать» в мониторе.';

            return $result;
        }

        // Ничего не просрочено. Пока пульс идёт, закрывать нечего: показываем
        // гарантированный срок (предельный возраст) и напоминаем, что быстрее
        // задача закроется только если воркер замолчит или прогресс встанет.
        if ($age_limit !== NULL AND $age_ts !== NULL)
        {
            $left = $age_limit - ($now - $age_ts);

            $result['action']       = 'will_close_age';
            $result['close_in_sec'] = (int) max(0, $left);
            $result['text']         = 'Задача работает: пульс '
                .($pulse_ago !== NULL ? self::_ago_text($pulse_ago) : '—')
                .' назад, рапорт '.($progress_ago !== NULL ? self::_ago_text($progress_ago) : '—').' назад. '
                .'Дольше '.self::_ago_text($age_limit).' она не проживёт — осталось '.self::_ago_text($left)
                .'. Если пульс замолчит дольше '.self::_ago_text($silent).' или прогресс встанет дольше '
                .self::_ago_text($stalled).', закроется раньше.';

            return $result;
        }

        $result['text'] = 'Задача работает: пульс '
            .($pulse_ago !== NULL ? self::_ago_text($pulse_ago) : '—')
            .' назад, рапорт '.($progress_ago !== NULL ? self::_ago_text($progress_ago) : '—').' назад. '
            .'Если пульс замолчит дольше '.self::_ago_text($silent).' или прогресс встанет дольше '
            .self::_ago_text($stalled).', задача закроется сама.';

        return $result;
    }

    /** «N мин» / «N ч» / «N с» — для текстов диагноза. */
    protected static function _ago_text($sec)
    {
        $sec = max(0, (int) $sec);

        if ($sec >= 3600)
        {
            return round($sec / 3600, 1).' ч';
        }

        if ($sec >= 60)
        {
            return (int) round($sec / 60).' мин';
        }

        return $sec.' с';
    }

    /** Последние задачи для монитора (опционально по статусу). */
}
