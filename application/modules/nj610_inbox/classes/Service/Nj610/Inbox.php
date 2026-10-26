<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj610_inbox: приём писем и черновиков от воркера, лента для владельца.
 * Ящик настраивается env: NJ610_INBOX_HOST/USER/PASS/FOLDER (см. .env.example).
 */
class Service_Nj610_Inbox {

    public static function configured()
    {
        return trim((string) getenv('NJ610_INBOX_HOST')) !== ''
            AND trim((string) getenv('NJ610_INBOX_USER')) !== '';
    }

    public static function ensure_tables()
    {
        static $done = FALSE;

        if ($done)
        {
            return;
        }

        $done = TRUE;

        try
        {
            DB::query(NULL, 'SELECT 1 FROM "nj_610_messages" LIMIT 1')->execute();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj610_inbox: таблиц нет — примените миграции: :e',
                [':e' => $e->getMessage()]);
        }
    }

    /** Лента для владельца: новые и черновики сверху. */
    public static function feed($limit = 50)
    {
        self::ensure_tables();

        $order = "CASE status WHEN 'new' THEN 0 WHEN 'drafted' THEN 1 ELSE 2 END";

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_610_messages ORDER BY '.$order.', id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    public static function stats()
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            "SELECT COUNT(*) FILTER (WHERE status = 'new') AS new,
                    COUNT(*) FILTER (WHERE status = 'drafted') AS drafted,
                    COUNT(*) FILTER (WHERE status = 'done') AS done
             FROM nj_610_messages", FALSE)->current();

        return $row ? (array) $row : ['new' => 0, 'drafted' => 0, 'done' => 0];
    }

    // ------------------------------------------------------------------
    // Живой статус-ряд почтового прогона (общий механизм partials/live_status)
    // ------------------------------------------------------------------

    const CHANNEL = 'nj610.inbox';

    /** Контракт «задачи нет» — тот же набор полей, что у JobQueue. */
    public static function _empty_live()
    {
        return [
            'found'       => FALSE,
            'job_id'      => 0,
            'status'      => 'none',
            'stage'       => '',
            'stages'      => [],
            'elapsed_sec' => 0,
            'started_at'  => NULL,
            'finished_at' => NULL,
            'silent_sec'  => NULL,
            'error'       => '',
            'note'        => '',
        ];
    }

    /**
     * Текущий (или последний свежий) прогон чтения почты. Канал без значимого
     * payload: у задачи нет «своего» объекта, поэтому берём задачу канала.
     */
    public static function live_now()
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return self::_empty_live();
        }

        return Service_Nj120_JobQueue::instance()->live_status(self::CHANNEL, '', '');
    }

    /** Последний прогон, включая завершённый — постоянная плашка с итогом. */
    public static function last_run()
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        $st = Service_Nj120_JobQueue::instance()->last_status(self::CHANNEL, '', '');

        return ($st AND ! empty($st['found'])) ? $st : NULL;
    }

    /** Сколько писем принёс прогон, начавшийся в $since. */
    public static function found_since($since)
    {
        if ($since === NULL OR $since === '')
        {
            return 0;
        }

        try
        {
            $row = self::_db()->query(Database::SELECT,
                'SELECT count(*) AS n FROM nj_610_messages WHERE created_at >= '
                .self::_db()->quote((string) $since), FALSE)->current();

            return $row ? (int) $row['n'] : 0;
        }
        catch (Throwable $e)
        {
            return 0;
        }
    }

    /**
     * «Проверить почту сейчас» — тот же пинг, что ставит пиналка nj603.
     * Повторный клик не плодит задачи: активный прогон уже читает ящик.
     */
    public static function run_now()
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['error' => 'Очередь задач недоступна'];
        }

        $queue = Service_Nj120_JobQueue::instance();

        if (method_exists($queue, 'find_active') AND $queue->find_active(self::CHANNEL, '', '') > 0)
        {
            return ['ok' => TRUE, 'already' => TRUE, 'flash' => 'Проверка уже идёт — смотрите ряд слева'];
        }

        try
        {
            $job_id = Service_Nj120_JobQueue::push(self::CHANNEL, [], ['module' => 'nj610_inbox']);
        }
        catch (Throwable $e)
        {
            return ['error' => 'Не удалось поставить задачу: '.$e->getMessage()];
        }

        return ['ok' => TRUE, 'job_id' => $job_id];
    }

    /** Остановить идущий прогон чтения почты. @return array [ok] | [error] */
    public static function cancel_run()
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['error' => 'Очередь задач недоступна'];
        }

        $queue  = Service_Nj120_JobQueue::instance();
        $job_id = method_exists($queue, 'find_active')
            ? $queue->find_active(self::CHANNEL, '', '')
            : 0;

        if ($job_id <= 0)
        {
            return ['error' => 'Активного прогона нет — останавливать нечего'];
        }

        try
        {
            $queue->cancel($job_id, TRUE);
        }
        catch (Throwable $e)
        {
            return ['error' => 'Не удалось остановить прогон'];
        }

        return ['ok' => TRUE, 'job_id' => $job_id];
    }

    public static function set_status($id, $status)
    {
        if ( ! in_array($status, ['new', 'drafted', 'done', 'ignored'], TRUE))
        {
            return FALSE;
        }

        $db = self::_db();
        $res = $db->query(Database::UPDATE,
            'UPDATE nj_610_messages SET status = '.$db->quote($status).' WHERE id = '.(int) $id, FALSE);

        return (is_int($res) AND $res > 0);
    }

    // ------------------------------------------------------- API для воркера

    /** /inbox/api/mail: принять список писем (message_id дедупится). */
    public static function api_mail(array $messages)
    {
        self::ensure_tables();
        $db = self::_db();
        $saved = 0;

        foreach ($messages as $m)
        {
            $mid = mb_substr(trim((string) ($m['message_id'] ?? '')), 0, 250);
            $from = mb_substr(trim((string) ($m['from'] ?? '')), 0, 300);

            if ($mid === '' OR $from === '')
            {
                continue;
            }

            try
            {
                $ts = (int) ($m['received_at'] ?? 0);

                $db->query(Database::INSERT,
                    'INSERT INTO nj_610_messages (message_id, from_addr, subject, body, received_at)
                     VALUES ('.$db->quote($mid).', '.$db->quote($from).', '
                    .$db->quote(mb_substr(trim((string) ($m['subject'] ?? '')), 0, 500)).', '
                    .$db->quote(mb_substr(trim((string) ($m['body'] ?? '')), 0, 20000)).', '
                    .($ts > 0 ? 'to_timestamp('.(int) $ts.')' : 'NULL').')
                     ON CONFLICT (message_id) DO NOTHING', FALSE);
                $saved++;
            }
            catch (Throwable $e)
            {
                // дубликат/битое письмо
            }
        }

        return ['ok' => TRUE, 'saved' => $saved];
    }

    /**
     * /inbox/api/drafts: применить разбор LLM — категория, приоритет,
     * черновик ответа (по Message-ID).
     */
    public static function api_drafts(array $drafts)
    {
        self::ensure_tables();
        $db = self::_db();
        $applied = 0;

        foreach ($drafts as $d)
        {
            $mid = mb_substr(trim((string) ($d['message_id'] ?? '')), 0, 250);

            if ($mid === '')
            {
                continue;
            }

            $prio = in_array(($d['priority'] ?? ''), ['high', 'normal', 'low'], TRUE) ? $d['priority'] : 'normal';

            $db->query(Database::UPDATE,
                'UPDATE nj_610_messages SET category = '.$db->quote(mb_substr((string) ($d['category'] ?? ''), 0, 40))
                .', priority = '.$db->quote($prio)
                .', draft = '.$db->quote(mb_substr(trim((string) ($d['draft'] ?? '')), 0, 5000))
                .", status = CASE WHEN status = 'new' THEN 'drafted' ELSE status END
                 WHERE message_id = ".$db->quote($mid), FALSE);
            $applied++;
        }

        return ['ok' => TRUE, 'applied' => $applied];
    }

    protected static function _db()
    {
        return Database::instance();
    }
}
