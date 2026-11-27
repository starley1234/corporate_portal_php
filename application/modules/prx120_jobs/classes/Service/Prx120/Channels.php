<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx120: «Каналы очереди» (/prx120/channels) — сводка по каналам и их
 * лимиты. Одна строка на канал: сколько ждёт и исполняется, сколько упало
 * за сутки, возраст самой старой ожидающей задачи, когда канал последний
 * раз брали в работу и его лимиты (prx_120_channel_limits).
 *
 * Лимиты читает захват задачи (Service_Prx120_JobQueue::claim и python
 * PgQueue.claim): max_concurrent — не больше N задач канала одновременно,
 * min_interval_sec — не чаще одного захвата за S секунд. 0 — без лимита.
 */
class Service_Prx120_Channels {

    /** Верхние границы — защита от опечатки «100000» в форме. */
    const MAX_CONCURRENT   = 1000;
    const MAX_INTERVAL_SEC = 86400;

    /** Массовый повтор: окно и сколько задач за один раз. */
    const RETRY_DAYS = 7;
    const RETRY_CAP  = 100;

    /**
     * Сводка по каналам: известные из манифестов + встречавшиеся в очереди
     * за 30 дней + с заданными лимитами.
     *
     * @return array[] channel, label, module_known, pending, processing,
     *                 failed_24h, done_24h, oldest_pending_sec, last_claimed_at,
     *                 retryable, max_concurrent, min_interval_sec
     */
    public static function overview(): array
    {
        $ui    = Manifest::channels_ui();
        $rows  = [];

        $stats = Prx_Db::select("SELECT channel,
                count(*) FILTER (WHERE status = 'pending') AS pending,
                count(*) FILTER (WHERE status = 'processing') AS processing,
                count(*) FILTER (WHERE status = 'failed' AND coalesce(finished_at, created_at) > now() - interval '24 hours') AS failed_24h,
                count(*) FILTER (WHERE status = 'done' AND coalesce(finished_at, created_at) > now() - interval '24 hours') AS done_24h,
                extract(epoch FROM now() - min(available_at) FILTER (WHERE status = 'pending' AND available_at <= now()))::bigint AS oldest_pending_sec,
                max(claimed_at) AS last_claimed_at
            FROM prx_120_jobs
            WHERE created_at > now() - interval '30 days' OR status IN ('pending', 'processing')
            GROUP BY channel");

        foreach ($stats as $r)
        {
            $rows[(string) $r['channel']] = $r;
        }

        $retryable = self::_retryable_counts();
        $limits    = self::limits();
        $channels  = array_unique(array_merge(array_keys($ui), array_keys($rows), array_keys($limits)));
        sort($channels);

        $out = [];

        foreach ($channels as $ch)
        {
            $r = $rows[$ch] ?? [];
            $l = $limits[$ch] ?? ['max_concurrent' => 0, 'min_interval_sec' => 0];

            $out[] = [
                'channel'            => $ch,
                'label'              => (string) ($ui[$ch]['label'] ?? $ch),
                'url'                => (string) ($ui[$ch]['url'] ?? ''),
                'module_known'       => isset($ui[$ch]),
                'pending'            => (int) ($r['pending'] ?? 0),
                'processing'         => (int) ($r['processing'] ?? 0),
                'failed_24h'         => (int) ($r['failed_24h'] ?? 0),
                'done_24h'           => (int) ($r['done_24h'] ?? 0),
                'oldest_pending_sec' => isset($r['oldest_pending_sec']) ? (int) $r['oldest_pending_sec'] : NULL,
                'last_claimed_at'    => (string) ($r['last_claimed_at'] ?? ''),
                'retryable'          => (int) ($retryable[$ch] ?? 0),
                'max_concurrent'     => (int) $l['max_concurrent'],
                'min_interval_sec'   => (int) $l['min_interval_sec'],
            ];
        }

        return $out;
    }

    /** @return array channel => ['max_concurrent' => int, 'min_interval_sec' => int] */
    public static function limits(): array
    {
        $out = [];

        foreach (Prx_Db::select('SELECT channel, max_concurrent, min_interval_sec FROM prx_120_channel_limits') as $r)
        {
            $out[(string) $r['channel']] = [
                'max_concurrent'   => (int) $r['max_concurrent'],
                'min_interval_sec' => (int) $r['min_interval_sec'],
            ];
        }

        return $out;
    }

    /**
     * Задать лимиты канала. Оба нуля — лимит снимается (строка удаляется).
     *
     * @throws InvalidArgumentException неверный канал или значения вне границ
     */
    public static function set_limit(string $channel, int $max_concurrent, int $min_interval_sec, int $actor_id): void
    {
        if ( ! preg_match('/^[a-z0-9_.:-]{1,128}$/i', $channel))
        {
            throw new InvalidArgumentException('Неверное имя канала');
        }

        if ($max_concurrent < 0 OR $max_concurrent > self::MAX_CONCURRENT)
        {
            throw new InvalidArgumentException('«Одновременно» — от 0 до '.self::MAX_CONCURRENT);
        }

        if ($min_interval_sec < 0 OR $min_interval_sec > self::MAX_INTERVAL_SEC)
        {
            throw new InvalidArgumentException('«Интервал» — от 0 до '.self::MAX_INTERVAL_SEC.' с (сутки)');
        }

        if ($max_concurrent === 0 AND $min_interval_sec === 0)
        {
            Prx_Db::execute('DELETE FROM prx_120_channel_limits WHERE channel = :c', [':c' => $channel]);

            return;
        }

        Prx_Db::execute('INSERT INTO prx_120_channel_limits (channel, max_concurrent, min_interval_sec, updated_by, updated_at)
                VALUES (:c, :m, :i, :u, now())
                ON CONFLICT (channel) DO UPDATE SET max_concurrent = EXCLUDED.max_concurrent,
                    min_interval_sec = EXCLUDED.min_interval_sec,
                    updated_by = EXCLUDED.updated_by, updated_at = now()', [
            ':c' => $channel,
            ':m' => $max_concurrent,
            ':i' => $min_interval_sec,
            ':u' => $actor_id > 0 ? $actor_id : NULL,
        ]);
    }

    /**
     * Повторить упавшие задачи канала за RETRY_DAYS дней — каждая новой
     * задачей (JobQueue::retry), уже повторённые второй раз не ставятся.
     *
     * @return int[] id новых задач
     */
    public static function retry_failed(string $channel): array
    {
        $queue = Service_Prx120_JobQueue::instance();
        $ids   = [];

        foreach (self::_retryable_ids($channel) as $job_id)
        {
            $new_id = (int) $queue->retry($job_id);

            if ($new_id > 0)
            {
                $ids[] = $new_id;
            }
        }

        return $ids;
    }

    /** Упавшие за окно и ещё не повторённые: канал => число. */
    protected static function _retryable_counts(): array
    {
        $out = [];

        foreach (Prx_Db::select("SELECT j.channel, count(*) AS n FROM prx_120_jobs j
                WHERE ".self::_retryable_where()."
                GROUP BY j.channel") as $r)
        {
            $out[(string) $r['channel']] = (int) $r['n'];
        }

        return $out;
    }

    /** @return int[] */
    protected static function _retryable_ids(string $channel): array
    {
        return array_map('intval', array_column(Prx_Db::select("SELECT j.id FROM prx_120_jobs j
                WHERE j.channel = :c AND ".self::_retryable_where()."
                ORDER BY j.id ASC LIMIT ".self::RETRY_CAP, [':c' => $channel]), 'id'));
    }

    protected static function _retryable_where(): string
    {
        return "j.status = 'failed'
                  AND coalesce(j.finished_at, j.created_at) > now() - interval '".self::RETRY_DAYS." days'
                  AND NOT EXISTS (SELECT 1 FROM prx_120_job_events e
                                   WHERE e.job_id = j.id AND e.event = 'retried')";
    }
}
