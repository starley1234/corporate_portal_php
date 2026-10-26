<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сервис «Профиль компании» (NJ-591, /company).
 *
 * Поток: профиль (домен или название) → задача воркера nj120 (канал
 * nj591.profile) → воркер через общий конвейер webread обходит Главную /
 * О нас / Контакты, малая LLM собирает строгий JSON (сфера, размер,
 * контакты, стек, ЛПР) и выгружает его через /company/api/result.
 * Результат доступен и как JSON: /company/<code>/json.
 */
class Service_Nj591_Company {

    const CHANNEL_PROFILE = 'nj591.profile';

    /** @var Database */
    protected static $_db;

    protected static function _db()
    {
        return self::$_db !== NULL ? self::$_db : self::$_db = Database::instance();
    }

    protected static function _cfg($key, $default = NULL)
    {
        static $cfg = NULL;

        if ($cfg === NULL)
        {
            $cfg = (array) Kohana::$config->load('nj591_company_profile');
        }

        return Arr::path($cfg, $key, $default);
    }

    /**
     * Страховка: миграции nj111 применяются на проде вручную — модуль сам
     * создаёт свои таблицы (идемпотентно, тот же DDL, что в миграции 001).
     * Каждое утверждение — отдельным запросом, ошибка каждой — в лог.
     */
    protected static $_tables_ensured = FALSE;

    public static function ensure_tables()
    {
        if (self::$_tables_ensured)
        {
            return;
        }

        self::$_tables_ensured = TRUE;

        $stmts = [];
        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_591_profiles" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NOT NULL,
    "query"       VARCHAR(300) NOT NULL DEFAULT '',
    "kind"        VARCHAR(10)  NOT NULL DEFAULT 'domain',
    "domain"      VARCHAR(200) NOT NULL DEFAULT '',
    "company"     VARCHAR(300) NOT NULL DEFAULT '',
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"       TEXT         NULL,
    "progress"    VARCHAR(300) NOT NULL DEFAULT '',
    "result"      JSONB        NULL,
    "sources"     JSONB        NULL,
    "job_id"      BIGINT       NULL,
    "usage"       JSONB        NULL,
    "deleted_at"  TIMESTAMPTZ  NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ  NULL
)
SQL;
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_591_profiles_user_idx" ON "nj_591_profiles" ("user_id", "id" DESC)';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_591_profiles_status_idx" ON "nj_591_profiles" ("status", "id" DESC)';

        foreach ($stmts as $sql)
        {
            try
            {
                DB::query(NULL, $sql)->execute();
            }
            catch (Exception $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj591: ensure_tables: :e', [':e' => $e->getMessage()]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Создание профиля
    // ------------------------------------------------------------------

    public static function create($user_id, $query)
    {
        self::ensure_tables();

        $query = trim((string) $query);

        if ($query === '')
        {
            return ['error' => 'Укажите домен или название компании.'];
        }

        if (mb_strlen($query) > (int) self::_cfg('max_query_len', 300))
        {
            return ['error' => 'Слишком длинный ввод.'];
        }

        // домен или название (как webread.parse_query в воркере)
        $maybe = preg_replace('~^https?://~i', '', $query);
        $maybe = explode('/', (string) $maybe)[0];
        $maybe = explode('?', (string) $maybe)[0];
        $kind  = 'name';
        $domain = '';

        if ($maybe !== '' AND strpos($maybe, ' ') === FALSE AND preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~i', $maybe))
        {
            $kind   = 'domain';
            $domain = strtolower($maybe);
        }

        // nj601_billing: списание кредита за сбор профиля (после валидации ввода)
        $charge = Service_Nj601_Billing::charge('nj591_company_profile',
            Service_Nj601_Billing::subject_of($user_id));

        if (isset($charge['error']))
        {
            return $charge;
        }

        $code = (string) self::_cfg('code_prefix', 'cmp').bin2hex(random_bytes((int) self::_cfg('code_bytes', 4)));

        try
        {
            $res = DB::query(Database::INSERT,
                'INSERT INTO nj_591_profiles (code, user_id, query, kind, domain, status)
                 VALUES (:code, :user_id, :query, :kind, :domain, :status)
                 RETURNING id', FALSE)
                ->parameters([
                    ':code'   => $code,
                    ':user_id' => (int) $user_id,
                    ':query'  => $query,
                    ':kind'   => $kind,
                    ':domain' => $domain,
                    ':status' => 'new',
                ])
                ->execute();
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj591: create: :e', [':e' => $e->getMessage()]);

            return ['error' => 'Не удалось создать профиль: '.mb_substr((string) $e->getMessage(), 0, 300)];
        }

        $profile_id = (int) (is_array($res) ? reset($res) : $res);
        $job_id = self::_dispatch(self::CHANNEL_PROFILE, $profile_id, [
            'profile_id' => $profile_id,
            'code'       => $code,
            'kind'       => $kind,
            'domain'     => $domain,
            'name'       => $kind === 'name' ? $query : '',
        ]);

        if ($job_id <= 0)
        {
            return ['error' => 'Не удалось поставить задачу воркеру — попробуйте ещё раз.'];
        }

        return ['code' => $code, 'id' => $profile_id];
    }

    /** Поставить задачу в очередь (created_by = владелец → ему придёт подписка erp217). */
    protected static function _dispatch($channel, $profile_id, array $payload)
    {
        $user = self::_db()->query(Database::SELECT,
            'SELECT user_id FROM nj_591_profiles WHERE id = '.(int) $profile_id, FALSE)->current();

        try
        {
            $job = Service_Nj120_JobQueue::push($channel, $payload, [
                'max_attempts' => 2,
                'module'       => 'nj591',
                'created_by'   => $user ? (int) $user['user_id'] : NULL,
                // 20 мин: до 5 страниц + 1 LLM-вызов малой модели
                'timeout_sec'  => 1200,
            ]);

            self::_db()->query(Database::UPDATE,
                "UPDATE nj_591_profiles SET job_id = ".(int) $job.", status = 'running', updated_at = now()
                 WHERE id = ".(int) $profile_id);

            return (int) $job;
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj591: не удалось поставить задачу: :e', [':e' => $e->getMessage()]);

            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Чтение (ensure_tables — при любом входе, см. nj590)
    // ------------------------------------------------------------------

    public static function by_id($id)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_591_profiles WHERE id = '.(int) $id." AND deleted_at IS NULL", FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function by_code($code)
    {
        self::ensure_tables();

        $db = self::_db();

        $row = $db->query(Database::SELECT,
            'SELECT * FROM nj_591_profiles WHERE code = '.$db->quote((string) $code)
            ." AND deleted_at IS NULL", FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function list_user($user_id, $limit = NULL)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_591_profiles WHERE user_id = '.(int) $user_id." AND deleted_at IS NULL
             ORDER BY id DESC LIMIT ".(int) ($limit ?: self::_cfg('list_limit', 50)), FALSE)->as_array();
    }

    public static function list_all($limit = NULL)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_591_profiles WHERE deleted_at IS NULL
             ORDER BY id DESC LIMIT '.(int) ($limit ?: self::_cfg('admin_limit', 100)), FALSE)->as_array();
    }

    /** Результат как массив (JSONB из воркера). */
    public static function result_of(array $profile)
    {
        $res = $profile['result'];
        is_string($res) AND $res = json_decode((string) $res, TRUE);

        return is_array($res) ? $res : [];
    }

    public static function sources_of(array $profile)
    {
        $res = $profile['sources'];
        is_string($res) AND $res = json_decode((string) $res, TRUE);

        return is_array($res) ? $res : [];
    }

    // ------------------------------------------------------------------
    // Воркер: результат, прогресс, ошибка
    // ------------------------------------------------------------------

    /** Строгий JSON профиля от воркера (/company/api/result). */
    public static function set_result($profile_id, $company, array $result, array $sources)
    {
        $db = self::_db();

        try
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_591_profiles SET company = '.$db->quote(mb_substr((string) $company, 0, 300))
                .', result = '.$db->quote(json_encode($result, JSON_UNESCAPED_UNICODE)).'::jsonb'
                .', sources = '.$db->quote(json_encode($sources, JSON_UNESCAPED_UNICODE)).'::jsonb'
                .", status = 'done', error = NULL, progress = '', finished_at = now(), updated_at = now()
                 WHERE id = ".(int) $profile_id." AND deleted_at IS NULL");
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj591: set_result: :e', [':e' => $e->getMessage()]);

            return FALSE;
        }

        // Дожим очереди (образец nj602): профиль принят САЙТОМ — задача nj120
        // должна стать «Готово», даже если воркер не успел complete() или
        // свипер отказал её за секунды до доставки. Идемпотентно.
        try
        {
            $jrow = $db->query(Database::SELECT,
                'SELECT job_id FROM nj_591_profiles WHERE id = '.(int) $profile_id, FALSE)->current();

            if (class_exists('Service_Nj120_JobQueue') AND $jrow AND (int) ($jrow['job_id'] ?? 0) > 0)
            {
                Service_Nj120_JobQueue::instance()->mark_delivered(
                    (int) $jrow['job_id'],
                    'Профиль компании принят сайтом — задача закрыта из приёмки nj591',
                );
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj591: связать задачу с готовым профилем не удалось: :e',
                [':e' => $e->getMessage()]);
        }

        // Онтология (nj607): компания попадает в ОБЩИЙ словарь сервисов и
        // связывается с этим профилем. Из онтологии видно, где ещё встречается
        // та же компания (досье, поток заказчиков, документы, отчёты).
        if (class_exists('Service_Nj607_Ontology'))
        {
            try
            {
                $prow = $db->query(Database::SELECT,
                    'SELECT code, domain FROM nj_591_profiles WHERE id = '.(int) $profile_id, FALSE)->current();

                if ($prow)
                {
                    $domain = (string) $prow['domain'];

                    Service_Nj607_Ontology::remember((string) $company, [
                        'url'    => $domain !== '' ? 'https://'.$domain : '',
                        'domain' => $domain,
                        'module' => 'profile',
                        'code'   => (string) $prow['code'],
                        'path'   => '/company/'.rawurlencode((string) $prow['code']),
                        'label'  => 'Профиль компании',
                        'score'  => 95,
                        'source' => 'nj591',
                    ]);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj591: онтология профиля: :e', [':e' => $e->getMessage()]);
            }
        }

        return TRUE;
    }

    public static function set_progress($profile_id, $phase)
    {
        try
        {
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_591_profiles SET progress = '.self::_db()->quote(mb_substr((string) $phase, 0, 300))
                .", status = CASE WHEN status IN ('new','running') THEN 'running' ELSE status END, updated_at = now()
                 WHERE id = ".(int) $profile_id);
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj591: set_progress: :e', [':e' => $e->getMessage()]);
        }
    }

    public static function fail($profile_id, $error)
    {
        try
        {
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_591_profiles SET status = \'error\', error = '.self::_db()->quote(mb_substr((string) $error, 0, 900))
                .", finished_at = now(), updated_at = now() WHERE id = ".(int) $profile_id." AND status IN ('new','running')");
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj591: fail: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Удалить профиль ПОЛНОСТЬЮ (БД; файлов у профилей нет). */
    public static function hard_delete($profile_id)
    {
        $profile = self::by_id((int) $profile_id);

        if ($profile === NULL)
        {
            return FALSE;
        }

        if ( ! empty($profile['job_id']))
        {
            try
            {
                Service_Nj120_JobQueue::instance()->cancel((int) $profile['job_id']);
            }
            catch (Exception $e)
            {
                // отмена не критична
            }
        }

        self::_db()->query(Database::DELETE,
            'DELETE FROM nj_591_profiles WHERE id = '.(int) $profile_id);

        return TRUE;
    }

    // ------------------------------------------------------------------
    // Reconcile (лениво при чтении статуса)
    // ------------------------------------------------------------------

    public static function reconcile(array $profile)
    {
        if ( ! in_array($profile['status'], ['new', 'running'], TRUE) OR empty($profile['job_id']))
        {
            return $profile;
        }

        $job = Service_Nj120_JobQueue::instance()->get((int) $profile['job_id']);

        $db = self::_db();

        if ($job === NULL)
        {
            // Задача исчезла (почищена/удалена/остановлена), а профиль остался
            // «Выполняется». Пропавшая задача НЕ вернётся, значит ждать нечего:
            // владелец (26.09) видел по такому профилю «нет пульса 5 мин» и
            // живой спиннер — сбор надо было закрывать сразу, а не через час.
            // Минута отсрочки — только чтобы не решить так в момент постановки
            // задачи (профиль создан секунду назад, строка задачи ещё не видна
            // из-за границ транзакции).
            $age = time() - strtotime((string) ($profile['created_at'] ?: $profile['updated_at']));

            if ($age > 60)
            {
                // Результат успел дойти до потери задачи — профиль готов, это
                // не ошибка (воркер выгрузил его через /company/api/result).
                if ($profile['result'] !== NULL)
                {
                    $db->query(Database::UPDATE,
                        "UPDATE nj_591_profiles SET status = 'done', error = NULL, progress = '', finished_at = now(), updated_at = now()
                         WHERE id = ".(int) $profile['id']);
                }
                else
                {
                    self::fail((int) $profile['id'],
                        'Сбор прервался: задача потеряна в очереди (воркер не ответил) — запустите сбор заново');
                }
            }

            return self::by_id((int) $profile['id']);
        }

        if ($job['status'] === Service_Nj120_JobQueue::STATUS_DONE)
        {
            $res = $job['result'];
            is_string($res) AND $res = json_decode((string) $res, TRUE);
            $res = is_array($res) ? $res : [];

            if ($profile['result'] !== NULL)
            {
                // результат уже выгружен воркером через /company/api/result
                $db->query(Database::UPDATE,
                    "UPDATE nj_591_profiles SET status = 'done', error = NULL, progress = '', finished_at = now(), updated_at = now()"
                    .(isset($res['usage']) ? ', usage = '.$db->quote(json_encode($res['usage'], JSON_UNESCAPED_UNICODE)).'::jsonb' : '')
                    .' WHERE id = '.(int) $profile['id']);
            }
            else
            {
                self::fail((int) $profile['id'], 'Воркер закончил, но профиль не сохранился — попробуйте ещё раз');
            }
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_FAILED)
        {
            self::fail((int) $profile['id'], (string) $job['error']);
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_CANCELLED)
        {
            // Сбор остановили (кнопка «Остановить» или администратор) — профиль
            // обязан выйти из «Выполняется», иначе страница держит спиннер вечно.
            self::fail((int) $profile['id'], 'Сбор остановлен — запустите заново');
        }
        elseif (in_array($job['status'], [Service_Nj120_JobQueue::STATUS_PENDING, Service_Nj120_JobQueue::STATUS_PROCESSING], TRUE))
        {
            // Сначала самое частое на практике: воркер ЗАМОЛЧАЛ. Пульта нет
            // дольше общего порога (JobQueue::silent_stop_sec, ~4 мин) — гасим
            // задачу и честно говорим об этом. Раньше профиль ждал полный
            // таймаут задачи (20 мин + 5) и всё это время показывал «нет пульса
            // N мин» со спиннером: владелец (26.09, /company/cmp247d1f61)
            // справедливо назвал это «подвисает».
            $queue  = Service_Nj120_JobQueue::instance();
            // ВАЖНО: и авто-стоп, и проверка застоя работают с ЗАДАЧЕЙ
            // ($profile['job_id']), а не с записью профиля: с id профиля они
            // гасили бы чужую задачу с тем же номером.
            $job_id = (int) $profile['job_id'];
            $beat   = $job['heartbeat_at'] ?: ($job['claimed_at'] ?: $job['created_at']);
            $quiet  = $beat ? (time() - strtotime((string) $beat)) : 0;

            if ($quiet > $queue->silent_stop_sec())
            {
                $queue->auto_stop_silent($job_id);
                $fresh = self::by_id((int) $profile['id']);

                if ($fresh AND $fresh['result'] !== NULL)
                {
                    // воркер успел выгрузить профиль до остановки — не теряем его
                    $db->query(Database::UPDATE,
                        "UPDATE nj_591_profiles SET status = 'done', error = NULL, progress = '', finished_at = now(), updated_at = now()
                         WHERE id = ".(int) $profile['id']);

                    return self::by_id((int) $profile['id']);
                }

                self::fail((int) $profile['id'],
                    'Воркер не отвечал больше '.max(1, (int) round($queue->silent_stop_sec() / 60))
                    .' мин — сбор остановлен автоматически. Запустите заново; если у компании нет сайта, укажите домен вручную');

                return self::by_id((int) $profile['id']);
            }

            // Затем «пульс есть, а прогресса нет» — долгий шаг без рапортов.
            if ($queue->auto_stop_stalled($job_id, $queue->stalled_progress_sec()) > 0)
            {
                self::fail((int) $profile['id'],
                    'Воркер перестал рапортовать о ходе сбора — он остановлен автоматически. Запустите заново');

                return self::by_id((int) $profile['id']);
            }

            // Последний рубеж — общий таймаут задачи.
            $started = $job['claimed_at'] ?: $job['created_at'];
            $limit   = (int) ($job['timeout_sec'] ?: 1200) + 300;

            if ($started AND (time() - strtotime((string) $started)) > $limit)
            {
                self::fail((int) $profile['id'], 'Задача зависла (воркер недоступен или перезапущен) — попробуйте создать профиль заново');
            }
        }

        return self::by_id((int) $profile['id']);
    }

    // ------------------------------------------------------------------
    // Управление
    // ------------------------------------------------------------------

    public static function remove($profile_id)
    {
        $profile = self::by_id((int) $profile_id);

        if ($profile === NULL)
        {
            return FALSE;
        }

        if ( ! empty($profile['job_id']))
        {
            try
            {
                Service_Nj120_JobQueue::instance()->cancel((int) $profile['job_id']);
            }
            catch (Exception $e)
            {
                // отмена не критична
            }
        }

        self::_db()->query(Database::UPDATE,
            'UPDATE nj_591_profiles SET deleted_at = now(), updated_at = now() WHERE id = '.(int) $profile_id);

        return TRUE;
    }

    // ------------------------------------------------------------------
    // MCP
    // ------------------------------------------------------------------

    public static function mcp_profile(array $args)
    {
        $code = (string) ($args['code'] ?? '');
        $p    = $code !== '' ? self::by_code($code) : NULL;

        if ($p === NULL)
        {
            return ['error' => 'not_found'];
        }

        $status_ru = ['new' => 'в очереди', 'running' => 'работает', 'done' => 'готово', 'error' => 'ошибка'];

        return [
            'code'       => (string) $p['code'],
            'query'      => (string) $p['query'],
            'kind'       => (string) $p['kind'],
            'domain'     => (string) $p['domain'],
            'company'    => (string) $p['company'],
            'status'     => (string) $p['status'],
            'status_ru'  => (string) ($status_ru[$p['status']] ?? $p['status']),
            'error'      => (string) $p['error'],
            'progress'   => (string) $p['progress'],
            'result'     => self::result_of($p),
            'sources'    => self::sources_of($p),
            'created_at' => (string) $p['created_at'],
        ];
    }
}
