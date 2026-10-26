<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj606_leads: «Поток заказчиков» — поиск компаний под ваш оффер.
 *
 * create_flow (списание кредита nj601_billing) → задача nj120 → воркер
 * ищет производителей по открытым источникам → лиды в /leads. Из лида
 * в один клик: профиль nj591 (Service_Nj591_Company::create) и письмо nj592.
 */
class Service_Nj606_Leads {

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
            DB::query(NULL, 'SELECT 1 FROM "nj_606_flows" LIMIT 1')->execute();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj606_leads: таблиц нет — примените миграции: :e',
                [':e' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ CRUD

    /** Создать поток (платно: cost nj606_leads). */
    public static function create_flow($user_id, $title, $industry, $region)
    {
        self::ensure_tables();

        if ((int) $user_id <= 0)
        {
            return ['error' => 'Сервис для зарегистрированных — войдите.'];
        }

        $industry = trim((string) $industry);

        if (mb_strlen($industry) < 5)
        {
            return ['error' => 'Опишите, какие компании вам нужны (от 5 знаков): что производят или чем занимаются.'];
        }

        // Дубли не плодим: тот же «что производит» + регион у того же
        // пользователя — это ТОТ ЖЕ поток. Без этой проверки повторная
        // отправка формы (F5, «назад») создавала копию потока И второе
        // списание кредита; теперь включаем существующий и запускаем сбор.
        $db     = Database::instance();
        $region = trim((string) $region);

        $dup = $db->query(Database::SELECT,
            'SELECT id, code FROM nj_606_flows
             WHERE user_id = '.(int) $user_id.'
               AND lower(btrim(industry)) = lower(btrim('.$db->quote($industry).'))
               AND lower(btrim(region)) = lower(btrim('.$db->quote($region).'))
             ORDER BY id DESC LIMIT 1', FALSE)->current();

        if ( ! empty($dup))
        {
            self::_enqueue((string) $dup['code']);

            return ['ok' => TRUE, 'code' => (string) $dup['code'], 'existing' => TRUE];
        }

        // nj601_billing: сбор потока расходует кредит
        $charge = Service_Nj601_Billing::charge('nj606_leads',
            Service_Nj601_Billing::subject_of($user_id));

        if (isset($charge['error']))
        {
            return $charge;
        }

        $code = 'fld'.bin2hex(random_bytes(4));

        $db->query(Database::INSERT,
            'INSERT INTO nj_606_flows (code, user_id, title, industry, region)
             VALUES ('.$db->quote($code).', '.(int) $user_id.', '
            .$db->quote(mb_substr(trim((string) $title) ?: $industry, 0, 120)).', '
            .$db->quote(mb_substr($industry, 0, 300)).', '
            .$db->quote(mb_substr(trim((string) $region), 0, 120)).')', FALSE);

        // первый сбор — СРАЗУ (кредит уже списан); дальше расписание держит nj603
        self::_enqueue($code);

        return ['ok' => TRUE, 'code' => $code];
    }

    /** Задача сбора конкретного потока в очередь nj120 (payload самодостаточен). */
    protected static function _enqueue($code)
    {
        $db = Database::instance();
        $f  = $db->query(Database::SELECT,
            'SELECT id, code, industry, region FROM nj_606_flows WHERE code = '.$db->quote((string) $code), FALSE)->current();

        if (empty($f))
        {
            return FALSE;
        }

        // Уже идёт? Вторую задачу не ставим: иначе кнопка «Собрать»/пиналка
        // плодит дубли, а страница показывает самую свежую — «ждём воркера» вечно.
        if (method_exists('Service_Nj120_JobQueue', 'find_active')
            AND Service_Nj120_JobQueue::instance()->find_active('nj606.leads', 'flow_id', (int) $f['id']) > 0)
        {
            return FALSE;
        }

        Service_Nj120_JobQueue::push('nj606.leads', [
            'flow_id'  => (int) $f['id'],
            'code'     => (string) $f['code'],
            'industry' => (string) $f['industry'],
            'region'   => (string) $f['region'],
        // timeout_sec 1500 с: поиск (150) + чтение (240) + модель (420) + запас.
        // Длинный таймаут больше НЕ означает «висит»: молчание пульса
        // (4 мин) и застывший прогресс (10 мин) гасятся отдельно.
        ], ['module' => 'nj606', 'timeout_sec' => 1500]);

        $db->query(Database::UPDATE,
            'UPDATE nj_606_flows SET last_run_at = now() WHERE id = '.(int) $f['id'], FALSE);

        return TRUE;
    }

    /** «Собрать сейчас» (владелец или управление). @return array [ok] | [error] */
    public static function run_now($code, $user_id, $is_admin = FALSE)
    {
        $db = Database::instance();
        $f  = $db->query(Database::SELECT,
            'SELECT user_id FROM nj_606_flows WHERE code = '.$db->quote((string) $code), FALSE)->current();

        if (empty($f))
        {
            return ['error' => 'Поток не найден'];
        }

        if ( ! $is_admin AND (int) $f['user_id'] !== (int) $user_id)
        {
            return ['error' => 'Это не ваш поток'];
        }

        return self::_enqueue($code)
            ? ['ok' => TRUE]
            : ['error' => 'Сбор этого потока уже идёт — дождитесь результата или нажмите «Остановить»'];
    }

    /** Поток по коду (для статус-ряда и проверок владельца) или NULL. */
    public static function by_code($code)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_606_flows WHERE code = '.self::_db()->quote((string) $code), FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /**
     * Живой статус сбора (эталон /trends/<code>): статус задачи, ЭТАП воркера
     * (ctx.progress → события очереди), секундомер. Доступ — владельцу или админу.
     *
     * @return array|null
     */
    public static function live_for($code, $user_id, $is_admin = FALSE)
    {
        $f = self::by_code($code);

        if ($f === NULL)
        {
            return NULL;
        }

        if ( ! $is_admin AND (int) $f['user_id'] !== (int) $user_id)
        {
            return NULL;
        }

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['found' => FALSE, 'status' => 'none', 'stage' => '', 'stages' => [], 'elapsed_sec' => 0, 'silent_sec' => NULL, 'error' => ''];
        }

        return Service_Nj120_JobQueue::instance()->live_status('nj606.leads', 'flow_id', (int) $f['id']);
    }

    /**
     * Последняя задача потока, включая ЗАВЕРШЁННУЮ — постоянная плашка
     * «Готово»/«Ошибка» в списке потоков (живой статус-ряд гаснет после
     * доставки, а итог работы пользователь видеть обязан).
     */
    public static function last_for($code, $user_id, $is_admin = FALSE)
    {
        $f = self::by_code($code);

        if ($f === NULL OR ( ! $is_admin AND (int) $f['user_id'] !== (int) $user_id))
        {
            return NULL;
        }

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        $st = Service_Nj120_JobQueue::instance()->last_status('nj606.leads', 'flow_id', (int) $f['id']);

        return ($st AND $st['found']) ? $st : NULL;
    }

    /**
     * Остановить идущий сбор потока (владелец или управление).
     *
     * Зачем: поиск + чтение источников идут минутами; если воркер недоступен или
     * молчит, пользователь должен иметь возможность прекратить сбор со страницы
     * (жалоба владельца: «при залипшем процессе колёсико не остановить»).
     *
     * @return array [ok] | [error]
     */
    public static function cancel_run($code, $user_id, $is_admin = FALSE)
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['error' => 'Очередь задач недоступна'];
        }

        $f = self::by_code((string) $code);

        if ($f === NULL OR ( ! $is_admin AND (int) $f['user_id'] !== (int) $user_id))
        {
            return ['error' => 'Поток не найден'];
        }

        $queue  = Service_Nj120_JobQueue::instance();
        // Останавливаем только РЕАЛЬНО активную задачу (pending/processing):
        // свежий failed — это уже итог, его «остановка» лишь путала бы статус.
        $job_id = method_exists($queue, 'find_active')
            ? $queue->find_active('nj606.leads', 'flow_id', (int) $f['id'])
            : $queue->find_for_delivery('nj606.leads', 'flow_id', (int) $f['id']);

        if ($job_id <= 0)
        {
            return ['error' => 'Активного сбора нет — останавливать нечего'];
        }

        try
        {
            $queue->cancel($job_id, TRUE);
        }
        catch (Throwable $e)
        {
            return ['error' => 'Не удалось остановить сбор'];
        }

        return ['ok' => TRUE, 'job_id' => $job_id];
    }

    /** Сколько компаний потока записано с момента $since — живой счётчик. */
    public static function found_since($flow_id, $since = NULL)
    {
        self::ensure_tables();

        $since = trim((string) $since);
        $where = $since !== '' ? ' AND created_at >= '.self::_db()->quote($since) : '';

        $row = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM nj_606_leads WHERE flow_id = '.(int) $flow_id.$where, FALSE)->current();

        return $row ? (int) $row['c'] : 0;
    }

    public static function flows_of($user_id)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT f.*, (SELECT COUNT(*) FROM nj_606_leads l WHERE l.flow_id = f.id AND l.status = \'new\') AS fresh
             FROM nj_606_flows f WHERE f.user_id = '.(int) $user_id.' ORDER BY f.id DESC', FALSE)->as_array();
    }

    public static function delete_flow($code, $user_id)
    {
        $db = self::_db();
        $db->query(Database::DELETE,
            'DELETE FROM nj_606_leads WHERE flow_id IN
                (SELECT id FROM nj_606_flows WHERE code = '.$db->quote((string) $code).' AND user_id = '.(int) $user_id.')', FALSE);

        $res = $db->query(Database::DELETE,
            'DELETE FROM nj_606_flows WHERE code = '.$db->quote((string) $code).' AND user_id = '.(int) $user_id, FALSE);

        return (is_int($res) AND $res > 0);
    }

    public static function feed($user_id, $limit = 80)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT l.*, f.title AS flow_title FROM nj_606_leads l
             JOIN nj_606_flows f ON f.id = l.flow_id
             WHERE l.user_id = '.(int) $user_id.'
             ORDER BY l.score DESC, l.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    // ----------------------------------------------------------------- управление

    /** Сводка для /leads/admin: все потоки, свежие лиды, счётчики. */
    public static function admin_overview($limit = 100)
    {
        self::ensure_tables();
        $db = self::_db();

        return [
            'flows' => $db->query(Database::SELECT,
                'SELECT f.*, u.username AS owner_name,
                        (SELECT COUNT(*) FROM nj_606_leads l WHERE l.flow_id = f.id) AS total
                 FROM nj_606_flows f LEFT JOIN users u ON u.id = f.user_id
                 ORDER BY f.id DESC LIMIT '.(int) $limit, FALSE)->as_array(),
            'leads' => $db->query(Database::SELECT,
                'SELECT l.*, f.title AS flow_title, u.username AS owner_name
                 FROM nj_606_leads l
                 JOIN nj_606_flows f ON f.id = l.flow_id
                 LEFT JOIN users u ON u.id = l.user_id
                 ORDER BY l.id DESC LIMIT '.(int) $limit, FALSE)->as_array(),
            'counts' => $db->query(Database::SELECT,
                'SELECT (SELECT COUNT(*) FROM nj_606_flows) AS flows,
                        (SELECT COUNT(*) FROM nj_606_leads) AS leads,
                        (SELECT COUNT(*) FROM nj_606_leads WHERE status = \'new\') AS new,
                        (SELECT COUNT(*) FROM nj_606_flows WHERE last_run_at IS NOT NULL) AS ran', FALSE)->as_array()[0] ?? [],
        ];
    }

    /** Удалить поток без ограничения по владельцу (управление). */
    public static function admin_delete_flow($code)
    {
        $db = self::_db();
        $db->query(Database::DELETE,
            'DELETE FROM nj_606_leads WHERE flow_id IN
                (SELECT id FROM nj_606_flows WHERE code = '.$db->quote((string) $code).')', FALSE);

        $res = $db->query(Database::DELETE,
            'DELETE FROM nj_606_flows WHERE code = '.$db->quote((string) $code), FALSE);

        return (is_int($res) AND $res > 0);
    }

    /** Отметка «взял/не нужен». */
    public static function lead_status($id, $user_id, $status)
    {
        if ( ! in_array($status, ['new', 'taken', 'gone'], TRUE))
        {
            return FALSE;
        }

        $db = self::_db();
        $res = $db->query(Database::UPDATE,
            'UPDATE nj_606_leads SET status = '.$db->quote($status)
            .' WHERE id = '.(int) $id.' AND user_id = '.(int) $user_id, FALSE);

        return (is_int($res) AND $res > 0);
    }

    /**
     * Из лида в один клик: профиль nj591 по домену (кросс-модуль — через Service).
     * Возвращает код профиля или ошибку (проговаривая её пользователю).
     */
    public static function lead_to_profile($lead_id, $user_id)
    {
        $lead = self::_lead($lead_id, $user_id);

        if ( ! $lead)
        {
            return ['error' => 'Лид не найден'];
        }

        $target = $lead['site'] !== '' ? $lead['site'] : $lead['company'];

        if ($target === '')
        {
            return ['error' => 'У лида нет ни сайта, ни названия — соберите вручную в «Профиле компании».'];
        }

        $res = Service_Nj591_Company::create($user_id, $target);

        if (isset($res['code']))
        {
            $db = self::_db();
            $db->query(Database::UPDATE,
                'UPDATE nj_606_leads SET profile_code = '.$db->quote((string) $res['code'])
                .' WHERE id = '.(int) $lead_id, FALSE);
        }

        return $res;
    }

    protected static function _lead($id, $user_id)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_606_leads WHERE id = '.(int) $id.' AND user_id = '.(int) $user_id, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    protected static function _db()
    {
        return Database::instance();
    }

    // ------------------------------------------------------- API для воркера

    /** /leads/api/collect: задачи сбора по потокам (не собиравшимся 12 часов). */
    public static function api_collect()
    {
        self::ensure_tables();
        $db = self::_db();

        $flows = $db->query(Database::SELECT,
            'SELECT id, code, industry, region FROM nj_606_flows
             WHERE id NOT IN (SELECT flow_id FROM nj_606_leads WHERE created_at > now() - interval \'12 hours\')
             ORDER BY id DESC LIMIT 10', FALSE)->as_array();

        $pushed = 0;

        foreach ($flows as $f)
        {
            try
            {
                // Не дублируем сбор: активная задача потока уже есть — пропускаем.
                // Иначе пиналка ставила задачи поверх идущих, и страница показывала
                // «Собираем компании» бесконечно (задача в очереди ждала воркера).
                if (method_exists('Service_Nj120_JobQueue', 'find_active')
                    AND Service_Nj120_JobQueue::instance()->find_active('nj606.leads', 'flow_id', (int) $f['id']) > 0)
                {
                    continue;
                }

                Service_Nj120_JobQueue::push('nj606.leads', [
                    'flow_id'  => (int) $f['id'],
                    'industry' => (string) $f['industry'],
                    'region'   => (string) $f['region'],
                // timeout_sec 1500 с: поиск (150) + чтение (240) + модель (420) + запас.
        // Длинный таймаут больше НЕ означает «висит»: молчание пульса
        // (4 мин) и застывший прогресс (10 мин) гасятся отдельно.
        ], ['module' => 'nj606', 'timeout_sec' => 1500]);
                $pushed++;
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj606_leads: push: :e', [':e' => $e->getMessage()]);
            }
        }

        return ['ok' => TRUE, 'pushed' => $pushed];
    }

    /** /leads/api/companies: принять компании от воркера. */
    public static function api_companies($flow_id, array $companies)
    {
        self::ensure_tables();
        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT id, user_id, code, title FROM nj_606_flows WHERE id = '.(int) $flow_id, FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'flow not found'];
        }

        $saved = 0;

        foreach ($companies as $c)
        {
            $company = mb_substr(trim((string) ($c['name'] ?? '')), 0, 300);
            $site    = mb_substr(trim((string) ($c['site'] ?? '')), 0, 300);

            if ($company === '')
            {
                continue;
            }

            $ext = sha1($site !== '' ? $site : mb_strtolower($company));

            try
            {
                $db->query(Database::INSERT,
                    'INSERT INTO nj_606_leads (flow_id, user_id, ext_id, company, site, inn, what, hook, score)
                     VALUES ('.(int) $row['id'].', '.(int) $row['user_id'].', '.$db->quote($ext).', '
                    .$db->quote($company).', '.$db->quote($site).', '
                    .$db->quote(mb_substr(preg_replace('/\D/', '', (string) ($c['inn'] ?? '')), 0, 20)).', '
                    .$db->quote(mb_substr(trim((string) ($c['what'] ?? '')), 0, 1000)).', '
                    .$db->quote(mb_substr(trim((string) ($c['hook'] ?? '')), 0, 1000)).', '
                    .min(100, max(0, (int) ($c['score'] ?? 0))).')
                     ON CONFLICT (flow_id, ext_id) DO NOTHING', FALSE);
                $saved++;

                // Онтология (nj607): найденная компания попадает в общий словарь
                // сервисов. match => FALSE: за один сбор приходит до 30 компаний,
                // дорогой поиск связей по базе для каждой строки тут не нужен.
                if (class_exists('Service_Nj607_Ontology'))
                {
                    try
                    {
                        Service_Nj607_Ontology::remember($company, [
                            'url'    => $site !== '' ? (preg_match('~^https?://~i', $site) ? $site : 'https://'.$site) : '',
                            'inn'    => (string) ($c['inn'] ?? ''),
                            'module' => 'leads',
                            'code'   => (string) ($row['code'] ?? ''),
                            'path'   => '/leads',
                            'label'  => 'Поток заказчиков: '.mb_substr((string) ($row['title'] ?? ''), 0, 60),
                            'score'  => 70,
                            'source' => 'nj606',
                            'match'  => FALSE,
                        ]);
                    }
                    catch (Throwable $e)
                    {
                        Kohana::$log->add(Log::WARNING, 'nj606: онтология лида: :e', [':e' => $e->getMessage()]);
                    }
                }
            }
            catch (Throwable $e)
            {
                // дубликат — пропускаем
            }
        }

        // Обратная связь в очередь: лиды ФАКТИЧЕСКИ сохранены сайтом — их задача
        // nj120 не должна висеть «Выполняется». Если воркер не выполнил
        // complete() (пал после /companies, оборвался ответ), закрываем активную
        // задачу потока изнутри приёмки — тот же итог, что дал бы complete()
        // (образец nj602.api_save). Идемпотентно; без задачи — ничего не трогаем.
        try
        {
            if (class_exists('Service_Nj120_JobQueue') AND $saved > 0)
            {
                $queue = Service_Nj120_JobQueue::instance();
                $job_id = $queue->find_active('nj606.leads', 'flow_id', (int) $flow_id);

                if ($job_id > 0)
                {
                    $queue->complete($job_id, 'site_nj606',
                        ['ok' => TRUE, 'flow_id' => (int) $flow_id, 'saved' => $saved]);
                }
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj606: связать задачу с принятыми лидами не удалось: :e',
                [':e' => $e->getMessage()]);
        }

        return ['ok' => TRUE, 'saved' => $saved];
    }
}
