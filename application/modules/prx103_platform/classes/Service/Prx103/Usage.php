<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx103: «Использование модулей» /prx100/usage — единая лента «кто что
 * делал и чем закончилось» из СУЩЕСТВУЮЩИХ источников (ничего не пишет):
 *
 *   prx_208_events  — доменные события (кто, когда, что; result из payload.ok);
 *   prx_120_jobs    — фоновые задачи очереди (статус/ошибка есть всегда);
 *   prx_150_api_usage — внешние API-вызовы (HTTP-статус как результат).
 *
 * Ретеншн не заводит: чистку ведут владельцы (prx208 — 90 дней и т.п.).
 */
class Service_Prx103_Usage {

    const WINDOWS = ['1h' => '1 hour', '24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'];
    const PER_SOURCE = 400;

    public static function modules(): array
    {
        $out = [];
        foreach (Manifest::all() as $slug => $m)
        {
            $out[$slug] = (string) ($m['title'] ?? $slug);
        }
        ksort($out);
        return $out;
    }

    /** slug модуля по префиксу prxNNN и по каналу воркера. */
    public static function by_prefix(string $prefix): string
    {
        foreach (Manifest::all() as $slug => $m)
        {
            if ('prx'.(int) ($m['id'] ?? 0) === $prefix) return $slug;
        }
        return $prefix;
    }

    public static function by_channel(string $channel): string
    {
        foreach (Manifest::all() as $slug => $m)
        {
            if (isset($m['worker']['channels'][$channel])) return $slug;
        }
        return '';
    }

    /**
     * Лента использования.
     * @param array $f [window, module(slug), result(''|ok|err), limit]
     * @return array ['rows' => …, 'note' => ?string]
     */
    public static function feed(array $f): array
    {
        $interval = self::WINDOWS[$f['window']] ?? '24 hours';
        $prefix = '';
        if ($f['module'] !== '')
        {
            $m = Manifest::all()[$f['module']] ?? NULL;
            $prefix = $m ? 'prx'.(int) ($m['id'] ?? 0) : '';
        }

        $jobs = self::_jobs($interval, $prefix);
        $events = self::_events($interval, $prefix);
        $api = self::_api($interval, $prefix);
        $note = NULL;
        if ($jobs === NULL AND $events === NULL AND $api === NULL)
        {
            $note = 'Ни один из источников недоступен (очередь prx120, шина prx208, журнал prx150).';
        }

        $rows = array_merge(
            self::_rows_jobs((array) $jobs),
            self::_rows_events((array) $events),
            self::_rows_api((array) $api));

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) $b['ts'], (string) $a['ts']);
        });

        if ($f['result'] !== '')
        {
            $rows = array_values(array_filter($rows, static function (array $row) use ($f): bool {
                return ($f['result'] === 'ok') === ($row['result'] === 'ok');
            }));
        }

        return ['rows' => array_slice($rows, 0, (int) $f['limit']), 'note' => $note];
    }

    // ------------------------------------------------------------- источники

    /** NULL — источник недоступен. SQL живут у владельцев (prx120/prx208/prx150). */
    protected static function _jobs(string $interval, string $prefix): ?array
    {
        try
        {
            return Service_Prx120_JobQueue::instance()->usage_jobs($interval, $prefix, self::PER_SOURCE);
        }
        catch (Throwable $e) { return NULL; }
    }

    protected static function _events(string $interval, string $prefix): ?array
    {
        try
        {
            return Service_Prx208_Journal::recent($interval, $prefix, self::PER_SOURCE);
        }
        catch (Throwable $e) { return NULL; }
    }

    protected static function _api(string $interval, string $prefix): ?array
    {
        try
        {
            return Service_Prx150_Usage::recent($interval, $prefix, self::PER_SOURCE);
        }
        catch (Throwable $e) { return NULL; }
    }

    // ------------------------------------------------------------- строки

    protected static function _rows_jobs(array $jobs): array
    {
        $rows = [];
        foreach ($jobs as $job)
        {
            $module = (string) $job['module'];
            if ($module === '') { $module = self::by_channel((string) $job['channel']); }
            $rows[] = [
                'ts' => (string) $job['created_at'],
                'source' => 'Задача',
                'module' => $module !== '' ? self::by_prefix($module) : '',
                'actor' => (int) $job['created_by'] > 0
                    ? ((string) ($job['owner_name'] ?? '') !== ''
                        ? (string) $job['owner_name']
                        : 'user #'.(int) $job['created_by'])
                    : 'воркер',
                'what' => (string) $job['channel'].' · задача #'.(int) $job['id']
                    .' · попытка '.(int) $job['attempts'],
                'result' => in_array($job['status'], ['done'], TRUE) ? 'ok'
                    : (in_array($job['status'], ['failed', 'cancelled'], TRUE) ? 'err' : 'run'),
                'detail' => in_array($job['status'], ['failed', 'cancelled'], TRUE)
                    ? mb_substr((string) $job['error'], 0, 200) : '',
                'href' => '/prx120/task/'.(int) $job['id'],
            ];
        }
        return $rows;
    }

    protected static function _rows_events(array $events): array
    {
        $rows = [];
        foreach ($events as $event)
        {
            $payload = json_decode((string) $event['payload'], TRUE);
            $ok = is_array($payload) && array_key_exists('ok', $payload)
                ? ( ! empty($payload['ok'])) : NULL;
            $detail = '';
            if (is_array($payload))
            {
                unset($payload['ok']);
                $detail = mb_substr(trim(json_encode($payload, JSON_UNESCAPED_UNICODE)), 0, 200);
            }

            $rows[] = [
                'ts' => (string) $event['created_at'],
                'source' => 'Событие',
                'module' => self::by_prefix((string) strtok((string) $event['event_name'], '.')),
                'actor' => (int) $event['initiator_id'] > 0
                    ? 'user #'.(int) $event['initiator_id']
                    : 'система',
                'what' => (string) $event['event_name'],
                'result' => $ok === TRUE ? 'ok' : ($ok === FALSE ? 'err' : 'run'),
                'detail' => $detail,
                'href' => '/prx208_events/view_event/'.(int) $event['id'],
            ];
        }
        return $rows;
    }

    protected static function _rows_api(array $calls): array
    {
        $rows = [];
        foreach ($calls as $call)
        {
            $prefix = (string) strtok((string) $call['scope'], '.');
            $rows[] = [
                'ts' => (string) $call['created_at'],
                'source' => 'API',
                'module' => $prefix !== '' ? self::by_prefix($prefix) : '',
                'actor' => 'клиент: '.(string) ($call['name'] !== NULL ? $call['name'] : '#?'),
                'what' => (string) $call['method'].' '.(string) $call['path']
                    .' · '.(int) $call['duration_ms'].' мс',
                'result' => (int) $call['status'] < 400 ? 'ok' : 'err',
                'detail' => 'HTTP '.(int) $call['status'],
                'href' => '/prx150_api_hub?tab=usage',
            ];
        }
        return $rows;
    }
}
