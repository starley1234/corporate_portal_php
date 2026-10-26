<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Пиналка» (nj603): один cron раз в 10 минут → смотрим конфиг расписаний →
 * созревшие ВЫПОЛНЯЕМ СРАЗУ (прямой вызов сервиса модуля, _fire): триггер сам
 * кладёт в очередь nj120 только реальную работу (nj602.article, nj606.leads…).
 * Состояние пинков — nj_603_kicks (по имени).
 *
 * Защита от «бесконечного выполнения»:
 *  * интервал считается от last_run_at — недозревшее расписание молчит;
 *  * каждое расписание выполняется в своем try/catch, ошибка одного не
 *    останавливает прочие;
 *  * реальные задачи ставятся самими триггерами с их max_attempts/timeout_sec.
 */
class Service_Nj603_Kicker
{
    /** Конфиг модуля. */
    public static function cfg($key = NULL, $default = NULL)
    {
        $cfg = Kohana::$config->load('nj603_kicker');

        if ($cfg === NULL)
        {
            return $default;
        }

        if ($key === NULL)
        {
            return $cfg->as_array();
        }

        return $cfg->get($key, $default);
    }

    /**
     * Расписания (имя → описание).
     *
     * Собираются из манифестов модулей: расписание объявляет владелец
     * эндпоинта, а не планировщик (пятый контракт, 28.09.2026). До этого
     * список 14 чужих модулей лежал в config/nj603_kicker.php.
     */
    public static function schedules()
    {
        return Manifest::schedules();
    }

    protected static function _db()
    {
        return Database::instance();
    }

    public static function ensure_tables()
    {
        static $done = FALSE;

        if ($done)
        {
            return;
        }

        $done = TRUE;
        if (trim((string) getenv('NJ603_SCHEMA_AUTOCREATE')) !== '1')
        {
            return;
        }

        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_603_kicks" (
    "name"            VARCHAR(60)  PRIMARY KEY,
    "last_run_at"     TIMESTAMPTZ  NULL,
    "last_status"     VARCHAR(24)  NOT NULL DEFAULT '',
    "last_error"      TEXT         NULL,
    "last_job_id"     BIGINT       NULL,
    "runs"            BIGINT       NOT NULL DEFAULT 0,
    "updated_at"      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
COMMENT ON TABLE "nj_603_kicks" IS 'NJ-603 «Пиналка»: состояние расписаний (последний пинок, статус, ссылка на задачу)';
SQL
        )->execute();

        $done = TRUE;
    }

    /** '12 hours' / '30 minutes' / '3 days' → секунды (0 — не разобрали). */
    public static function interval_sec($every)
    {
        if ( ! preg_match('~(\d+)\s*(minute|min|hour|h|day|d)~iu', (string) $every, $m))
        {
            return 0;
        }

        $n   = (int) $m[1];
        $u   = mb_strtolower($m[2]);
        $mul = 60;

        if (in_array($u, ['hour', 'h'], TRUE))
        {
            $mul = 3600;
        }
        elseif (in_array($u, ['day', 'd'], TRUE))
        {
            $mul = 86400;
        }

        return $n * $mul;
    }

    /** Есть ли незавершённые задачи канала в очереди (защита от наслоения). */
    public static function pending_or_running($channel)
    {
        $r = self::_db()->query(Database::SELECT,
            "SELECT count(*) AS c FROM nj_120_jobs
             WHERE channel = ".self::_db()->quote((string) $channel)."
               AND status IN ('pending', 'processing')", FALSE)->current();

        return $r AND (int) $r['c'] > 0;
    }

    /** @deprecated прежнее имя (был канал nj603.kick); оставлено для совместимости. */
    public static function kick_in_flight($name)
    {
        // Канала nj603.kick больше нет — по имени расписания наслаивание больше
        // не грозит, возвращаем FALSE и ничего не ломаем.
        return FALSE;
    }

    /**
     * Основной проход: пнуть всё созревшее.
     * $force — пнуть независимо от интервала (кнопка «Пнуть сейчас»).
     * Возвращает ['pushed' => N, 'log' => [строки]].
     */
    public static function run($force = FALSE)
    {
        self::ensure_tables();

        $db     = self::_db();
        $pushed = 0;
        $log    = [];

        foreach (self::schedules() as $name => $sc)
        {
            $name = (string) $name;
            $sc   = (array) $sc;

            if (empty($sc['enabled']))
            {
                $log[] = $name.': выключено — пропущено';
                continue;
            }

            $every = self::interval_sec((string) ($sc['every'] ?? ''));

            if ($every <= 0)
            {
                $log[] = $name.': не разобран интервал «'.(string) ($sc['every'] ?? '').'» — пропущено';
                continue;
            }

            $state = $db->query(Database::SELECT,
                'SELECT * FROM nj_603_kicks WHERE name = '.$db->quote($name), FALSE)->current();

            if ( ! $force AND $state AND ! empty($state['last_run_at']))
            {
                $age = time() - strtotime((string) $state['last_run_at']);

                if ($age < $every)
                {
                    $log[] = $name.': до пинка ещё '.max(1, $every - $age).' с (интервал '.(string) $sc['every'].')';
                    continue;
                }
            }

            // Выполняем расписание сразу (прямой вызов триггера), а не через
            // посредническую задачу nj603.kick: триггер сам решает, что и как
            // поставить в очередь (nj602.article / nj606.leads / …) со своими
            // max_attempts/timeout_sec. Обеспечиваем только два правила:
            //  * _fire в try/catch — ошибка одного расписания не роняет прочие;
            //  * _guard_channel(name) — не наслаиваем тяжёлый канал, пока в
            //    очереди уже висит та же работа (бывшая «защита от штабеля»).
            $result = ['ok' => FALSE, 'note' => ''];

            try
            {
                if (self::_guard_channel($name, $sc))
                {
                    $result = self::_fire($name, $sc);
                    $ok = ! empty($result['ok']);
                }
                else
                {
                    $ok     = FALSE;
                    $result = ['ok' => FALSE, 'note' => 'канал занят — пропущено (защита от штабеля)'];
                }
            }
            catch (Throwable $e)
            {
                $ok = FALSE;
                $result = ['ok' => FALSE, 'note' => 'ОШИБКА: '.$e->getMessage()
                    .' @ '.basename($e->getFile()).':'.$e->getLine()];
                Kohana::$log->add(Log::WARNING, 'nj603: fire :n: :e', [':n' => $name, ':e' => $e->getMessage()]);
            }

            self::_state_upsert($name, $result, $ok);

            if ($ok)
            {
                $pushed++;
                $log[] = $name.': выполнено ('.(isset($result['note']) ? $result['note'] : 'ok').', каждые '.(string) $sc['every'].')';
            }
            else
            {
                $log[] = $name.': '.(isset($result['note']) && $result['note'] !== '' ? $result['note'] : 'пропущено');
            }
        }

        // попутно доставляем уведомления о завершённых задачах (python-воркеры
        // в pg-режиме завершают задачи мимо PHP — см. Service_Nj597_Notify::sweep_jobs)
        if (class_exists('Service_Nj597_Notify'))
        {
            try
            {
                $notified = Service_Nj597_Notify::sweep_jobs();

                if ($notified > 0)
                {
                    $log[] = 'уведомления: доставлено из очереди событий: '.$notified;
                }

                // Письма отправляются из фонового cron-пинка, а не из запроса
                // пользователя. Advisory lock nj597 защищает от дубля (ниже
                // расписание notifications уже делает тот же sweep+digest).
                $digest = Service_Nj597_Notify::send_digests(100, 30);
                if ((int) ($digest['sent'] ?? 0) > 0 OR (int) ($digest['failed'] ?? 0) > 0)
                {
                    $log[] = 'сводки: отправлено пользователям '.(int) ($digest['sent'] ?? 0)
                        .', ошибок '.(int) ($digest['failed'] ?? 0);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj603: sweep/digest: :e', [':e' => $e->getMessage()]);
            }
        }

        return ['pushed' => $pushed, 'log' => $log];
    }

    /** Пнуть одно расписание немедленно (кнопка в админке). */
    public static function poke_now($name)
    {
        self::ensure_tables();

        $sc = (array) (self::schedules()[$name] ?? NULL);

        if ( ! $sc OR empty($sc['url']))
        {
            return ['error' => 'Расписание «'.$name.'» не найдено в конфиге'];
        }

        try
        {
            $result = self::_fire($name, $sc);
            $ok     = ! empty($result['ok']);
        }
        catch (Throwable $e)
        {
            $ok     = FALSE;
            $result = ['ok' => FALSE, 'note' => 'ОШИБКА: '.$e->getMessage()
                .' @ '.basename($e->getFile()).':'.$e->getLine()];
        }

        self::_state_upsert($name, $result, $ok);

        if ( ! $ok)
        {
            return ['error' => (string) ($result['note'] ?? 'не известно, что пошло не так')];
        }

        return ['ok' => TRUE, 'note' => (string) ($result['note'] ?? 'выполнено')];
    }

    /**
     * Защита от наслоения тяжёлой работы канала: пока в очереди висит
     * unfinished-задача того же канала (или указанной _guard-метки), новое
     * срабатывание пропускаем (легкие PHP-тики без каналов пропускаются всегда).
     */
    protected static function _guard_channel($name, array $sc)
    {
        $channel = (string) ($sc['channel'] ?? '');
        if ($channel === '')
        {
            return TRUE;
        }

        $ok = ! self::pending_or_running($channel);
        if ( ! $ok)
        {
            Kohana::$log->add(Log::INFO, 'nj603: :n: канал :c занят — пропуск',
                [':n' => $name, ':c' => $channel]);
        }

        return $ok;
    }

    /**
     * Триггер расписания: прямой вызов сервиса модуля (php), эквивалент
     * прежнего POST целевого URL. РЕАЛЬНУЮ работу в очередь ставит сам триггер.
     * Каждому расписанию соответствует вызов ниже; payload — аргументы.
     */
    protected static function _fire($name, array $sc)
    {
        $payload = (array) ($sc['payload'] ?? []);
        $url     = (string) ($sc['url'] ?? '');

        switch ($name)
        {
            // PHPMailer-рассылки: sweep_jobs + send_digests (дубль с run() дальше
            // невозможен — advisory lock в nj597). Легкий PHP-тик, сразу.
            case 'notifications':
                if ( ! class_exists('Service_Nj597_Notify'))
                    return ['ok' => FALSE, 'note' => 'nj597 не установлен'];
                // Центр уведомлений: догоняем задачи, разбираем очередь доставки
                // (почта/Telegram/MAX) и рассылаем сводки. Движок сам берёт
                // advisory-блокировку — второй пинок не продублирует отправку.
                $jobs    = Service_Nj597_Notify::sweep_jobs(100);
                $run     = Service_Nj597_Engine::run(50);
                $digest  = Service_Nj597_Engine::flush_digests(50);
                $legacy  = Service_Nj597_Notify::send_digests(50, 30);
                return ['ok' => TRUE, 'note' => 'задач '.$jobs
                    .', доставлено '.((int) ($run['sent'] ?? 0))
                    .' (отложено '.((int) ($run['deferred'] ?? 0)).')'
                    .', сводок '.((int) ($digest['sent'] ?? 0))
                    .', старых писем '.((int) ($legacy['sent'] ?? 0))];

            // Самообслуживание очереди (бывш. /nj120/api/maintain)
            case 'maintenance':
                if ( ! class_exists('Service_Nj120_JobQueue'))
                    return ['ok' => TRUE, 'note' => 'очередь недоступна — пропуск'];
                $n = Service_Nj120_JobQueue::instance()->requeue_stale();
                return ['ok' => TRUE, 'note' => 'разобрано задач '.(int) $n];

            // Автостатья: create_draft сам ставит nj602.article (одна незавершённая
            // статья в очереди — допустимый максимум).
            case 'content':
                if ( ! class_exists('Service_Nj602_Content'))
                    return ['ok' => FALSE, 'note' => 'nj602 не установлен'];
                $res = Service_Nj602_Content::create_draft(trim((string) ($payload['topic'] ?? '')), 'schedule');
                if (isset($res['error']))
                    return ['ok' => FALSE, 'note' => (string) $res['error']];
                return ['ok' => TRUE, 'note' => 'статья '.(string) ($res['code'] ?? '?').' (задача #'.(int) ($res['job'] ?? 0).')'];

            // Радар заказов
            case 'radar':
                if ( ! class_exists('Service_Nj605_Radar'))
                    return ['ok' => FALSE, 'note' => 'nj605 не установлен'];
                $res = Service_Nj605_Radar::api_collect();
                return ['ok' => TRUE, 'note' => 'находок поставлено '.(int) ($res['pushed'] ?? 0)];

            // Поток заказчиков
            case 'leads':
                if ( ! class_exists('Service_Nj606_Leads'))
                    return ['ok' => FALSE, 'note' => 'nj606 не установлен'];
                $res = Service_Nj606_Leads::api_collect();
                return ['ok' => TRUE, 'note' => 'компаний поставлено '.(int) ($res['pushed'] ?? 0)];

            // Вакансии компаний (nj642): сборы «созревших» мониторов. Модуль сам
            // решает, кто созрел (every_hours), здесь только повод запуститься.
            case 'vacancies':
                if ( ! class_exists('Service_Nj642_Vacancies'))
                    return ['ok' => FALSE, 'note' => 'nj642 не установлен'];
                $res = Service_Nj642_Vacancies::api_collect();
                return ['ok' => TRUE, 'note' => 'мониторов поставлено '.(int) ($res['queued'] ?? 0)
                    .' из '.(int) ($res['checked'] ?? 0)];

            // Сторож контрагентов
            case 'watchdog':
                if ( ! class_exists('Service_Nj609_Watchdog'))
                    return ['ok' => FALSE, 'note' => 'nj609 не установлен'];
                $res = Service_Nj609_Watchdog::api_collect();
                return ['ok' => TRUE, 'note' => 'проверок поставлено '.(int) ($res['pushed'] ?? 0)];

            // Входящие под контролем (IMAP-полл)
            case 'inbox':
                if ( ! class_exists('Service_Nj120_JobQueue'))
                    return ['ok' => FALSE, 'note' => 'очередь недоступна'];
                $job = (int) Service_Nj120_JobQueue::push('nj610.inbox', [], ['module' => 'nj610_inbox']);
                return ['ok' => $job > 0, 'note' => 'задача #'.$job];

            // Серии касаний: детект ответов + автоотправка созревших шагов
            case 'touches':
                if ( ! class_exists('Service_Nj612_Touch'))
                    return ['ok' => FALSE, 'note' => 'nj612 не установлен'];
                $res = Service_Nj612_Touch::tick();
                return ['ok' => TRUE, 'note' => 'отправлено '.((int) ($res['sent'] ?? 0)).', ответов '.((int) ($res['answered'] ?? 0))];

            // Дайджест руководителю
            case 'digest':
                if ( ! class_exists('Service_Nj613_Digest'))
                    return ['ok' => FALSE, 'note' => 'nj613 не установлен'];
                $res = Service_Nj613_Digest::tick();
                return ['ok' => TRUE, 'note' => 'дайджестов поставлено '.((int) ($res['queued'] ?? 0))];

            // Контроль качества изделий
            case 'qc':
                if ( ! class_exists('Service_Nj639_Qc'))
                    return ['ok' => FALSE, 'note' => 'nj639 не установлен'];
                $res = Service_Nj639_Qc::cron_poll();
                return ['ok' => TRUE, 'note' => 'поставлено '.(int) ($res['queued'] ?? 0)];

            // Камеры цеха
            case 'cameras':
                if ( ! class_exists('Service_Nj640_Cameras'))
                    return ['ok' => FALSE, 'note' => 'nj640 не установлен'];
                $res = Service_Nj640_Cameras::cron_poll();
                return ['ok' => TRUE, 'note' => 'поставлено '.(int) ($res['queued'] ?? 0)];

            // Обзор трендов: каждый 12 ч, НО если за 12 ч уже создан обзор —
            // не дублируем (profiles сама ставит nj593.trends в create()).
            case 'trends':
                if ( ! class_exists('Service_Nj593_Trends'))
                    return ['ok' => FALSE, 'note' => 'nj593 не установлен'];
                $recent = Service_Nj593_Trends::has_recent(12 * 3600);
                if ($recent)
                    return ['ok' => TRUE, 'note' => 'за 12 ч обзор уже есть — пропуск'];
                $period = (int) ($payload['period'] ?? 1);
                $count  = (int) ($payload['count'] ?? 8);
                $first  = Service_Nj593_Trends::first_admin_id();
                if ($first <= 0)
                    return ['ok' => FALSE, 'note' => 'нет администратора, кому владеть обзором'];
                $res = Service_Nj593_Trends::create($first, '', $period, $count, '', 50, '');
                if (isset($res['error']))
                    return ['ok' => FALSE, 'note' => (string) $res['error']];
                return ['ok' => TRUE, 'note' => 'обзор '.(string) ($res['code'] ?? '?')];

            // неизвестное расписание — не падаем, просто помечаем
            default:
                return ['ok' => TRUE, 'note' => 'нет триггера для «'.$name.'» (url '.$url.')'];
        }
    }

    protected static function _state_upsert($name, array $result, $ok)
    {
        $db     = self::_db();
        $status = $ok ? 'ok' : 'error';
        $error  = $ok ? '' : (string) ($result['note'] ?? 'неизвестная ошибка');

        $row = $db->query(Database::SELECT,
            'SELECT name FROM nj_603_kicks WHERE name = '.$db->quote($name), FALSE)->current();

        $sql = $row
            ? 'UPDATE nj_603_kicks SET last_run_at = now(), last_status = '.$db->quote($status).',
                 last_error = '.($error === '' ? 'NULL' : $db->quote($error)).',
                 runs = runs + 1, updated_at = now()
               WHERE name = '.$db->quote($name)
            : 'INSERT INTO nj_603_kicks (name, last_run_at, last_status, last_error, runs)
               VALUES ('.$db->quote($name).', now(), '.$db->quote($status).', '
                 .($error === '' ? 'NULL' : $db->quote($error)).', 1)';

        $db->query(Database::INSERT, $sql, FALSE);
    }

    /** Состояние всех расписаний (для админки: конфиг + факт). */
    public static function state_map()
    {
        self::ensure_tables();

        $out = [];

        foreach (self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_603_kicks ORDER BY name', FALSE)->as_array() as $r)
        {
            $out[(string) $r['name']] = (array) $r;
        }

        return $out;
    }

    /** MCP: расписания пиналки и их состояние (для агентов). */
    public static function mcp_schedules($args = [])
    {
        $state = self::state_map();
        $out   = [];

        foreach (self::schedules() as $name => $sc)
        {
            $st = (array) ($state[$name] ?? []);

            $out[] = [
                'name'       => (string) $name,
                'label'      => (string) ($sc['label'] ?? $name),
                'every'      => (string) ($sc['every'] ?? ''),
                'enabled'    => ! empty($sc['enabled']),
                'url'        => (string) ($sc['url'] ?? ''),
                'last_run_at'=> (string) ($st['last_run_at'] ?? ''),
                'runs'       => (int) ($st['runs'] ?? 0),
            ];
        }

        return ['schedules' => $out];
    }

    /** Живой статус последней задачи пинка (или ''). */
    public static function job_status($job_id)
    {
        if ( ! $job_id OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return '';
        }

        try
        {
            $j = Service_Nj120_JobQueue::instance()->get((int) $job_id);

            return $j ? (string) $j['status'] : '';
        }
        catch (Throwable $e)
        {
            return '';
        }
    }
}
