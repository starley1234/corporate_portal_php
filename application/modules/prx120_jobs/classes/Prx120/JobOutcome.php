<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Итог задачи prx120 глазами модуля — чистая классификация записи очереди
 * (массив из Service_Prx120_JobQueue::get), без БД.
 *
 * Тексты ошибок принадлежат prx120 (JobQueue/Maintenance и
 * workers/prx_workers/queue.py в pg-режиме), поэтому разбирает их только
 * этот класс: модулю нужен код, а не чужая строка. Модули с внешним API
 * (prx395–398, prx400) берут отсюда и публичный статус, и код отказа.
 */
final class Prx120_JobOutcome
{
    /** Воркер замолчал (упал, перезапущен на другом узле, завис) — повтор осмыслен. */
    const WORKER_LOST = 'worker_lost';

    /** Воркер падал именно на этой задаче, попытки исчерпаны — повтор вреден. */
    const CRASH_LOOP = 'worker_crash_loop';

    private const STATUS_FAILED = 'failed';
    private const STATUS_CANCELLED = 'cancelled';

    /**
     * Причина остановки задачи обслуживанием очереди.
     *
     * @return string|NULL WORKER_LOST | CRASH_LOOP | NULL (иное)
     */
    public static function stop_reason(?array $job): ?string
    {
        if ($job === NULL OR ! in_array((string) ($job['status'] ?? ''), [self::STATUS_CANCELLED, self::STATUS_FAILED], TRUE))
        {
            return NULL;
        }
        $error = (string) ($job['error'] ?? '');

        if (str_starts_with($error, 'Воркер перезапускался на этой задаче'))
        {
            return self::CRASH_LOOP;
        }
        foreach ([
            'Воркер не отвечал больше ',            // авто-стоп по пульсу (PHP и python)
            'Воркер не выходил на связь больше ',   // пульс есть, прогресса нет
            'Воркер молчал ',                       // правило 1: молчание дольше timeout
            'Воркер перестал подавать признаки жизни', // правило 2а
        ] as $prefix)
        {
            if (str_starts_with($error, $prefix))
            {
                return self::WORKER_LOST;
            }
        }

        return NULL;
    }

    /**
     * Как остановленная задача выглядит для внешнего клиента модуля:
     * 'failed' — сбой (в т.ч. обслуживание остановило задачу умершего
     * воркера: «отменено» тут было бы ложью), 'canceled' — отменил человек,
     * NULL — задача не остановлена.
     */
    public static function stopped_as(?array $job): ?string
    {
        $status = (string) ($job['status'] ?? '');
        if ($status === self::STATUS_FAILED) return 'failed';
        if ($status === self::STATUS_CANCELLED) return self::stop_reason($job) !== NULL ? 'failed' : 'canceled';

        return NULL;
    }

    /**
     * Машинный код отказа задачи для API модуля. Наружу — только коды:
     * код модуля из его белого списка («DocumentJobError|PermanentJobError:
     * <code>» от воркера), причина остановки (worker_lost, worker_crash_loop),
     * job_timeout, job_failed; задачи нет — job_unavailable.
     *
     * @param string[] $module_codes белый список кодов воркера модуля
     */
    public static function failure_code(?array $job, array $module_codes): string
    {
        if ($job === NULL) return 'job_unavailable';
        $stop = self::stop_reason($job);
        if ($stop !== NULL) return $stop;
        $error = (string) ($job['error'] ?? '');
        if (preg_match('/\A(?:DocumentJobError|PermanentJobError): ([a-z_]+)\z/', $error, $m)
            AND in_array($m[1], $module_codes, TRUE)) return $m[1];
        if (str_starts_with($error, 'Превышен таймаут задачи')) return 'job_timeout';

        return 'job_failed';
    }
}
