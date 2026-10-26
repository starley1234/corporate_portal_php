<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj609_watchdog: «Сторож контрагентов» — события по вашим клиентам/поставщикам.
 * Beta: бесплатно (cost 0 в nj601_billing). Сбор — раз в 7 дней на субъекта.
 */
class Service_Nj609_Watchdog {

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
            DB::query(NULL, 'SELECT 1 FROM "nj_609_subjects" LIMIT 1')->execute();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj609_watchdog: таблиц нет — примените миграции: :e',
                [':e' => $e->getMessage()]);
        }
    }

    public static function add_subject($user_id, $company, $inn, $note)
    {
        self::ensure_tables();

        if ((int) $user_id <= 0)
        {
            return ['error' => 'Сервис для зарегистрированных — войдите.'];
        }

        $company = trim((string) $company);

        if (mb_strlen($company) < 3)
        {
            return ['error' => 'Укажите название компании (или ИНН).'];
        }

        $db = Database::instance();
        $code = 'wdg'.bin2hex(random_bytes(4));

        $db->query(Database::INSERT,
            'INSERT INTO nj_609_subjects (code, user_id, company, inn, note)
             VALUES ('.$db->quote($code).', '.(int) $user_id.', '
            .$db->quote(mb_substr($company, 0, 300)).', '
            .$db->quote(mb_substr(preg_replace('/\D/', '', (string) $inn), 0, 20)).', '
            .$db->quote(mb_substr(trim((string) $note), 0, 300)).')', FALSE);

        // первая проверка — СРАЗУ; дальше еженедельный обход держит nj603
        self::_enqueue($code);

        return ['ok' => TRUE, 'code' => $code];
    }

    /** Задача проверки конкретного контрагента в очередь nj120. */
    protected static function _enqueue($code)
    {
        $db = Database::instance();
        $s  = $db->query(Database::SELECT,
            'SELECT id, code, company, inn FROM nj_609_subjects WHERE code = '.$db->quote((string) $code), FALSE)->current();

        if (empty($s))
        {
            return FALSE;
        }

        Service_Nj120_JobQueue::push('nj609.watchdog', [
            'subject_id' => (int) $s['id'],
            'code'       => (string) $s['code'],
            'company'    => (string) $s['company'],
            'inn'        => (string) $s['inn'],
        ], ['module' => 'nj609', 'timeout_sec' => 900]);

        $db->query(Database::UPDATE,
            'UPDATE nj_609_subjects SET last_run_at = now() WHERE id = '.(int) $s['id'], FALSE);

        return TRUE;
    }

    /** «Проверить сейчас» (владелец или управление). @return array [ok] | [error] */
    public static function run_now($code, $user_id, $is_admin = FALSE)
    {
        $db = Database::instance();
        $s  = $db->query(Database::SELECT,
            'SELECT user_id FROM nj_609_subjects WHERE code = '.$db->quote((string) $code), FALSE)->current();

        if (empty($s))
        {
            return ['error' => 'Контрагент не найден'];
        }

        if ( ! $is_admin AND (int) $s['user_id'] !== (int) $user_id)
        {
            return ['error' => 'Это не ваш контрагент'];
        }

        return self::_enqueue($code) ? ['ok' => TRUE] : ['error' => 'Не получилось поставить задачу'];
    }

    public static function subjects_of($user_id)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT s.*, (SELECT COUNT(*) FROM nj_609_events e WHERE e.subject_id = s.id AND e.status = \'new\') AS fresh
             FROM nj_609_subjects s WHERE s.user_id = '.(int) $user_id.' ORDER BY s.id DESC', FALSE)->as_array();
    }

    public static function delete_subject($code, $user_id)
    {
        $db = self::_db();
        $db->query(Database::DELETE,
            'DELETE FROM nj_609_events WHERE subject_id IN
                (SELECT id FROM nj_609_subjects WHERE code = '.$db->quote((string) $code).' AND user_id = '.(int) $user_id.')', FALSE);

        $res = $db->query(Database::DELETE,
            'DELETE FROM nj_609_subjects WHERE code = '.$db->quote((string) $code).' AND user_id = '.(int) $user_id, FALSE);

        return (is_int($res) AND $res > 0);
    }

    public static function feed($user_id, $limit = 60)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            "SELECT e.*, s.company, s.inn,
                    CASE WHEN e.severity = 'risk' THEN 3 WHEN e.severity = 'warn' THEN 2 ELSE 1 END AS pri
             FROM nj_609_events e JOIN nj_609_subjects s ON s.id = e.subject_id
             WHERE e.user_id = ".(int) $user_id."
             ORDER BY e.created_at DESC, pri DESC LIMIT ".(int) $limit, FALSE)->as_array();
    }

    public static function event_seen($id, $user_id)
    {
        $db = self::_db();
        $res = $db->query(Database::UPDATE,
            "UPDATE nj_609_events SET status = 'seen' WHERE id = ".(int) $id." AND user_id = ".(int) $user_id, FALSE);

        return (is_int($res) AND $res > 0);
    }

    // ----------------------------------------------------------------- управление

    /** Сводка для /watchdog/admin: все контрагенты, свежие события, счётчики. */
    public static function admin_overview($limit = 100)
    {
        self::ensure_tables();
        $db = self::_db();

        return [
            'subjects' => $db->query(Database::SELECT,
                'SELECT s.*, u.username AS owner_name,
                        (SELECT COUNT(*) FROM nj_609_events e WHERE e.subject_id = s.id) AS total
                 FROM nj_609_subjects s LEFT JOIN users u ON u.id = s.user_id
                 ORDER BY s.id DESC LIMIT '.(int) $limit, FALSE)->as_array(),
            'events' => $db->query(Database::SELECT,
                'SELECT e.*, s.company AS subject_company, u.username AS owner_name
                 FROM nj_609_events e
                 JOIN nj_609_subjects s ON s.id = e.subject_id
                 LEFT JOIN users u ON u.id = e.user_id
                 ORDER BY e.id DESC LIMIT '.(int) $limit, FALSE)->as_array(),
            'counts' => $db->query(Database::SELECT,
                'SELECT (SELECT COUNT(*) FROM nj_609_subjects) AS subjects,
                        (SELECT COUNT(*) FROM nj_609_events) AS events,
                        (SELECT COUNT(*) FROM nj_609_events WHERE status = \'new\') AS new,
                        (SELECT COUNT(*) FROM nj_609_events WHERE severity = \'risk\') AS risk', FALSE)->as_array()[0] ?? [],
        ];
    }

    /** Снять контрагента без ограничения по владельцу (управление). */
    public static function admin_delete_subject($code)
    {
        $db = self::_db();
        $db->query(Database::DELETE,
            'DELETE FROM nj_609_events WHERE subject_id IN
                (SELECT id FROM nj_609_subjects WHERE code = '.$db->quote((string) $code).')', FALSE);

        $res = $db->query(Database::DELETE,
            'DELETE FROM nj_609_subjects WHERE code = '.$db->quote((string) $code), FALSE);

        return (is_int($res) AND $res > 0);
    }

    // ------------------------------------------------------- API для воркера

    /** /watchdog/api/collect: субъекты, не проверявшиеся 7 дней. */
    public static function api_collect()
    {
        self::ensure_tables();
        $db = self::_db();

        $rows = $db->query(Database::SELECT,
            "SELECT id, code, company, inn FROM nj_609_subjects
             WHERE last_run_at IS NULL OR last_run_at < now() - interval '7 days'
             ORDER BY last_run_at NULLS FIRST LIMIT 15", FALSE)->as_array();

        $pushed = 0;

        foreach ($rows as $r)
        {
            try
            {
                Service_Nj120_JobQueue::push('nj609.watchdog', [
                    'subject_id' => (int) $r['id'],
                    'code'       => (string) $r['code'],
                    'company'    => (string) $r['company'],
                    'inn'        => (string) $r['inn'],
                ], ['module' => 'nj609', 'timeout_sec' => 900]);

                $db->query(Database::UPDATE,
                    'UPDATE nj_609_subjects SET last_run_at = now() WHERE id = '.(int) $r['id'], FALSE);
                $pushed++;
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj609_watchdog: push: :e', [':e' => $e->getMessage()]);
            }
        }

        return ['ok' => TRUE, 'pushed' => $pushed];
    }

    /** /watchdog/api/events: принять события. */
    public static function api_events($subject_id, array $events)
    {
        self::ensure_tables();
        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT id, user_id FROM nj_609_subjects WHERE id = '.(int) $subject_id, FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'subject not found'];
        }

        $subj = $db->query(Database::SELECT,
            'SELECT company FROM nj_609_subjects WHERE id = '.(int) $subject_id, FALSE)->current();
        $company = (string) ($subj['company'] ?? '');

        $saved = 0;
        $fresh_risky = 0; // новые risk/warn — для уведомлений владельцу (nj597)

        foreach ($events as $ev)
        {
            $title = mb_substr(trim((string) ($ev['title'] ?? '')), 0, 300);
            $url   = trim((string) ($ev['url'] ?? ''));

            if ($title === '')
            {
                continue;
            }

            $ext = sha1($url !== '' ? $url : $title);

            $severity = in_array(($ev['severity'] ?? ''), ['risk', 'warn', 'info'], TRUE)
                ? (string) $ev['severity'] : 'info';
            $summary = mb_substr(trim((string) ($ev['summary'] ?? '')), 0, 1000);

            try
            {
                // событие новое? (ON CONFLICT молчит о вставке — проверяем сами,
                // чтобы уведомить владельца ровно один раз на событие)
                $exists = $db->query(Database::SELECT,
                    'SELECT 1 FROM nj_609_events WHERE subject_id = '.(int) $row['id']
                    .' AND ext_id = '.$db->quote($ext), FALSE)->current();

                $db->query(Database::INSERT,
                    'INSERT INTO nj_609_events (subject_id, user_id, ext_id, kind, severity, title, url, summary)
                     VALUES ('.(int) $row['id'].', '.(int) $row['user_id'].', '.$db->quote($ext).', '
                    .$db->quote(mb_substr((string) ($ev['kind'] ?? ''), 0, 30)).', '
                    .$db->quote($severity).', '
                    .$db->quote($title).', '.$db->quote(mb_substr($url, 0, 500)).', '
                    .$db->quote($summary).')
                     ON CONFLICT (subject_id, ext_id) DO NOTHING', FALSE);
                $saved++;

                if ( ! $exists AND ($severity === 'risk' OR $severity === 'warn'))
                {
                    $fresh_risky++;
                }
            }
            catch (Throwable $e)
            {
                // дубликат
            }
        }

        // новые сигналы риска — в центр уведомлений (nj597): колокольчик всегда,
        // письмо — по настройкам пользователя; сводка уйдёт с ближайшим дайджестом
        if ($fresh_risky > 0 AND class_exists('Service_Nj597_Notify'))
        {
            try
            {
                $label = $company !== '' ? ' по «'.mb_substr($company, 0, 80).'»' : '';
                Service_Nj597_Notify::notify((int) $row['user_id'], 'system',
                    'Сторож: новые сигналы'.$label,
                    'Новых событий риска: '.$fresh_risky.'. Проверьте ленту — суды, банкротство, новости.',
                    '/watchdog');
            }
            catch (Throwable $e)
            {
                // уведомление не должно ломать приём событий
            }
        }

        // Дожим очереди (образец nj602): события проверки приняты сайтом — задача
        // nj120 становится «Готово». Колонки job_id нет, поэтому ищем задачу по
        // payload.subject_id (find_for_delivery); свежий failed тоже оживляем —
        // свипер мог отказать задачу за секунды до доставки результата.
        try
        {
            if (class_exists('Service_Nj120_JobQueue'))
            {
                $queue  = Service_Nj120_JobQueue::instance();
                $job_id = method_exists($queue, 'find_for_delivery')
                    ? $queue->find_for_delivery('nj609.watchdog', 'subject_id', (int) $subject_id)
                    : 0;

                if ($job_id > 0)
                {
                    $queue->mark_delivered($job_id,
                        'Результат проверки контрагента принят сайтом — задача закрыта из приёмки nj609');
                }
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj609: связать задачу с приёмкой событий не удалось: :e',
                [':e' => $e->getMessage()]);
        }

        return ['ok' => TRUE, 'saved' => $saved];
    }

    // ------------------------------------------------------------------
    // Живой статус-ряд (общий механизм partials/live_status, эталон /trends)
    // ------------------------------------------------------------------

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
     * Субъект по коду — с проверкой прав (владелец или управление).
     * Живой ряд показывают только тому, чей это контрагент.
     */
    public static function subject_by_code($code, $user_id = NULL, $is_admin = TRUE)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_609_subjects WHERE code = '.self::_db()->quote((string) $code), FALSE)->current();

        if (empty($row))
        {
            return NULL;
        }

        // $is_admin по умолчанию TRUE — служебным вызовам (статистика, воркер)
        // права не нужны; страница всегда передаёт конкретного пользователя.
        // Гость (user_id NULL) не должен видеть чужой ряд: без явного владельца
        // доступ закрыт.
        if ( ! $is_admin AND ($user_id === NULL OR (int) $row['user_id'] !== (int) $user_id))
        {
            return NULL;
        }

        return $row;
    }

    /**
     * События одного контрагента — для сводной страницы (nj635 «полный отчёт»).
     * Сначала риски, затем предупреждения, затем информационные; свежие выше.
     *
     * $user_id — владелец: чужой ряд не отдаём. Если владелец не передан,
     * метод служебный (статистика/воркер) — страницы всегда передают своего
     * пользователя, а гость до этого вызова не доходит: доступ к наблюдению
     * проверяется в subject_by_code() выше по стеку.
     */
    public static function events_of_subject($subject_id, $user_id = NULL, $limit = 10)
    {
        self::ensure_tables();

        try
        {
            $db  = self::_db();
            $own = ($user_id !== NULL) ? ' AND user_id = '.(int) $user_id : '';

            return $db->query(Database::SELECT,
                "SELECT id, kind, severity, title, url, summary, status, created_at
                   FROM nj_609_events
                  WHERE subject_id = ".(int) $subject_id.$own."
                  ORDER BY CASE severity WHEN 'risk' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END ASC,
                           created_at DESC, id DESC
                  LIMIT ".min(50, max(1, (int) $limit)), FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            return [];
        }
    }

    /**
     * Живая задача проверки контрагента (канал nj609.watchdog, привязка —
     * subject_id: он есть и в пиналке, и в кнопке «Проверить сейчас»).
     */
    public static function live_for($code, $user_id = NULL, $is_admin = FALSE)
    {
        $s = self::subject_by_code($code, $user_id, $is_admin);

        if ($s === NULL)
        {
            return NULL;
        }

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return self::_empty_live();
        }

        return Service_Nj120_JobQueue::instance()->live_status('nj609.watchdog', 'subject_id', (int) $s['id']);
    }

    /**
     * Последняя задача проверки — включая завершённую: в списке контрагентов
     * всегда виден итог («Готово · 12 мин назад · событий: 2» либо «Ошибка»),
     * а не пустое место после того, как живой ряд погас.
     */
    public static function last_for($code, $user_id = NULL, $is_admin = FALSE)
    {
        $s = self::subject_by_code($code, $user_id, $is_admin);

        if ($s === NULL OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        $st = Service_Nj120_JobQueue::instance()->last_status('nj609.watchdog', 'subject_id', (int) $s['id']);

        return ($st AND ! empty($st['found'])) ? $st : NULL;
    }

    /** Сколько событий принёс прогон, начавшийся в $since. */
    public static function found_since($subject_id, $since)
    {
        if ($since === NULL OR $since === '')
        {
            return 0;
        }

        try
        {
            $row = self::_db()->query(Database::SELECT,
                "SELECT count(*) AS n FROM nj_609_events
                  WHERE subject_id = ".(int) $subject_id."
                    AND created_at >= ".self::_db()->quote((string) $since), FALSE)->current();

            return $row ? (int) $row['n'] : 0;
        }
        catch (Throwable $e)
        {
            return 0;
        }
    }

    /** «Остановить» проверку (владелец или управление). @return array [ok] | [error] */
    public static function cancel_run($code, $user_id = NULL, $is_admin = FALSE)
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['error' => 'Очередь задач недоступна'];
        }

        $s = self::subject_by_code($code, $user_id, $is_admin);

        if ($s === NULL)
        {
            return ['error' => 'Контрагент не найден'];
        }

        $queue  = Service_Nj120_JobQueue::instance();
        $job_id = method_exists($queue, 'find_active')
            ? $queue->find_active('nj609.watchdog', 'subject_id', (int) $s['id'])
            : $queue->find_for_delivery('nj609.watchdog', 'subject_id', (int) $s['id']);

        if ($job_id <= 0)
        {
            return ['error' => 'Активной проверки нет — останавливать нечего'];
        }

        try
        {
            // force: прерываем и захваченную задачу — воркер свой complete()
            // после этого не применит (статус уже cancelled), это штатно.
            $queue->cancel($job_id, TRUE);
        }
        catch (Throwable $e)
        {
            return ['error' => 'Не удалось остановить проверку'];
        }

        return ['ok' => TRUE, 'job_id' => $job_id];
    }

    protected static function _db()
    {
        return Database::instance();
    }
}
