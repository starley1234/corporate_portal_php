<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сервис «Холодное письмо» (NJ-592, /coldmail).
 *
 * Поток: письмо (компания + что предлагаем [+ профиль nj591] + тон) → задача
 * nj120 (канал nj592.email) → воркер собирает факты (готовый профиль nj591
 * из payload или свежий обход webread + DDG-новости) → LLM пишет N вариантов
 * ультра-персонализированного первого абзаца → выгрузка /coldmail/api/result.
 */
class Service_Nj592_Coldmail {

    const CHANNEL_EMAIL = 'nj592.email';

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
            $cfg = (array) Kohana::$config->load('nj592_coldmail');
        }

        return Arr::path($cfg, $key, $default);
    }

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
CREATE TABLE IF NOT EXISTS "nj_592_letters" (
    "id"             BIGSERIAL PRIMARY KEY,
    "code"           VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"        BIGINT       NOT NULL,
    "company_query"  VARCHAR(300) NOT NULL DEFAULT '',
    "profile_code"   VARCHAR(12)  NULL,
    "domain"         VARCHAR(200) NOT NULL DEFAULT '',
    "product"        TEXT         NULL,
    "audience"       VARCHAR(300) NOT NULL DEFAULT '',
    "tone"           VARCHAR(30)  NOT NULL DEFAULT '',
    "variants_count" SMALLINT     NOT NULL DEFAULT 3,
    "status"         VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"          TEXT         NULL,
    "progress"       VARCHAR(300) NOT NULL DEFAULT '',
    "result"         JSONB        NULL,
    "sources"        JSONB        NULL,
    "job_id"         BIGINT       NULL,
    "usage"          JSONB        NULL,
    "deleted_at"     TIMESTAMPTZ  NULL,
    "created_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at"    TIMESTAMPTZ  NULL
)
SQL;
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_592_letters_user_idx" ON "nj_592_letters" ("user_id", "id" DESC)';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_592_letters_status_idx" ON "nj_592_letters" ("status", "id" DESC)';

        foreach ($stmts as $sql)
        {
            try
            {
                DB::query(NULL, $sql)->execute();
            }
            catch (Exception $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj592: ensure_tables: :e', [':e' => $e->getMessage()]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Создание
    // ------------------------------------------------------------------

    public static function create($user_id, $company_query, $profile_code, $product, $audience, $tone, $variants)
    {
        self::ensure_tables();

        $company_query = trim((string) $company_query);
        $profile_code  = trim((string) $profile_code);
        $product       = trim((string) $product);
        $audience      = mb_substr(trim((string) $audience), 0, (int) self::_cfg('max_audience_len', 300));
        $tone          = in_array((string) $tone, ['business', 'friendly', 'bold'], TRUE) ? (string) $tone : 'business';
        $max_n         = (int) self::_cfg('max_variants', 5);
        $n             = (int) $variants ?: (int) self::_cfg('default_variants', 3);
        $n             = max(1, min($max_n, $n));

        if (mb_strlen($product) < 3)
        {
            return ['error' => 'Опишите, что вы предлагаете — без этого письмо не написать.'];
        }

        $product = mb_substr($product, 0, (int) self::_cfg('max_product_len', 2000));

        if ($company_query === '' AND $profile_code === '')
        {
            return ['error' => 'Укажите компанию: код профиля (cmp…) или домен/название.'];
        }

        // nj601_billing: списание кредита за генерацию письма (после валидации ввода)
        $charge = Service_Nj601_Billing::charge('nj592_coldmail',
            Service_Nj601_Billing::subject_of($user_id));

        if (isset($charge['error']))
        {
            return $charge;
        }

        // профиль nj591: факты о компании уезжают в payload воркера напрямую
        $profile_payload = NULL;
        $domain = '';
        $query_for_worker = $company_query;

        if ($profile_code !== '')
        {
            $profile = Service_Nj591_Company::by_code($profile_code);

            if ($profile === NULL)
            {
                return ['error' => 'Профиль '.$profile_code.' не найден.'];
            }

            if ((string) $profile['status'] !== 'done' OR $profile['result'] === NULL)
            {
                return ['error' => 'Профиль '.$profile_code.' ещё не готов — дождитесь статуса «готово» или укажите домен/название.'];
            }

            $profile_payload = [
                'company' => (string) $profile['company'],
                'domain'  => (string) $profile['domain'],
                'result'  => Service_Nj591_Company::result_of($profile),
                'sources' => Service_Nj591_Company::sources_of($profile),
            ];

            $domain = (string) $profile['domain'];
            $query_for_worker = $company_query !== '' ? $company_query : (string) $profile['company'];
        }

        // без профиля — домен или название (как в nj591)
        $kind = 'name';

        if ($company_query !== '')
        {
            $maybe = preg_replace('~^https?://~i', '', $company_query);
            $maybe = explode('/', (string) $maybe)[0];
            $maybe = explode('?', (string) $maybe)[0];

            if ($maybe !== '' AND strpos($maybe, ' ') === FALSE AND preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~i', $maybe))
            {
                $kind   = 'domain';
                $domain = strtolower($maybe);
            }
        }

        $code = (string) self::_cfg('code_prefix', 'cld').bin2hex(random_bytes((int) self::_cfg('code_bytes', 4)));

        try
        {
            $res = DB::query(Database::INSERT,
                'INSERT INTO nj_592_letters (code, user_id, company_query, profile_code, domain, product, audience, tone, variants_count, status)
                 VALUES (:code, :user_id, :cq, :pc, :domain, :product, :audience, :tone, :n, :status)
                 RETURNING id', FALSE)
                ->parameters([
                    ':code'    => $code,
                    ':user_id' => (int) $user_id,
                    ':cq'      => $query_for_worker,
                    ':pc'      => $profile_code !== '' ? $profile_code : NULL,
                    ':domain'  => $domain,
                    ':product' => $product,
                    ':audience' => $audience,
                    ':tone'    => $tone,
                    ':n'       => $n,
                    ':status'  => 'new',
                ])
                ->execute();
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj592: create: :e', [':e' => $e->getMessage()]);

            return ['error' => 'Не удалось создать прогон: '.mb_substr((string) $e->getMessage(), 0, 300)];
        }

        $letter_id = (int) (is_array($res) ? reset($res) : $res);
        $job_id = self::_dispatch(self::CHANNEL_EMAIL, $letter_id, [
            'letter_id' => $letter_id,
            'code'      => $code,
            'kind'      => $kind,
            'domain'    => $domain,
            'name'      => $kind === 'name' ? $query_for_worker : '',
            'profile'   => $profile_payload,
            'product'   => $product,
            'audience'  => $audience,
            'tone'      => $tone,
            'n'         => $n,
        ]);

        if ($job_id <= 0)
        {
            return ['error' => 'Не удалось поставить задачу воркеру — попробуйте ещё раз.'];
        }

        return ['code' => $code, 'id' => $letter_id];
    }

    protected static function _dispatch($channel, $letter_id, array $payload)
    {
        $user = self::_db()->query(Database::SELECT,
            'SELECT user_id FROM nj_592_letters WHERE id = '.(int) $letter_id, FALSE)->current();

        try
        {
            $job = Service_Nj120_JobQueue::push($channel, $payload, [
                'max_attempts' => 2,
                'module'       => 'nj592',
                'created_by'   => $user ? (int) $user['user_id'] : NULL,
                // 20 мин: возможен свежий обход сайта + DDG + 1 LLM-вызов
                'timeout_sec'  => 1200,
            ]);

            self::_db()->query(Database::UPDATE,
                "UPDATE nj_592_letters SET job_id = ".(int) $job.", status = 'running', updated_at = now()
                 WHERE id = ".(int) $letter_id);

            return (int) $job;
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj592: не удалось поставить задачу: :e', [':e' => $e->getMessage()]);

            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Чтение
    // ------------------------------------------------------------------

    public static function by_id($id)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_592_letters WHERE id = '.(int) $id." AND deleted_at IS NULL", FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function by_code($code)
    {
        self::ensure_tables();

        $db = self::_db();

        $row = $db->query(Database::SELECT,
            'SELECT * FROM nj_592_letters WHERE code = '.$db->quote((string) $code)
            ." AND deleted_at IS NULL", FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function list_user($user_id, $limit = NULL)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_592_letters WHERE user_id = '.(int) $user_id." AND deleted_at IS NULL
             ORDER BY id DESC LIMIT ".(int) ($limit ?: self::_cfg('list_limit', 50)), FALSE)->as_array();
    }

    public static function list_all($limit = NULL)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_592_letters WHERE deleted_at IS NULL
             ORDER BY id DESC LIMIT '.(int) ($limit ?: self::_cfg('admin_limit', 100)), FALSE)->as_array();
    }

    public static function result_of(array $letter)
    {
        $res = $letter['result'];
        is_string($res) AND $res = json_decode((string) $res, TRUE);

        return is_array($res) ? $res : [];
    }

    public static function sources_of(array $letter)
    {
        $res = $letter['sources'];
        is_string($res) AND $res = json_decode((string) $res, TRUE);

        return is_array($res) ? $res : [];
    }

    // ------------------------------------------------------------------
    // Воркер
    // ------------------------------------------------------------------

    public static function set_result($letter_id, array $result, array $sources)
    {
        $db = self::_db();

        try
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_592_letters SET result = '.$db->quote(json_encode($result, JSON_UNESCAPED_UNICODE)).'::jsonb'
                .', sources = '.$db->quote(json_encode($sources, JSON_UNESCAPED_UNICODE)).'::jsonb'
                .", status = 'done', error = NULL, progress = '', finished_at = now(), updated_at = now()
                 WHERE id = ".(int) $letter_id." AND deleted_at IS NULL");
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj592: set_result: :e', [':e' => $e->getMessage()]);

            return FALSE;
        }

        // Онтология (nj607): компания из письма попадает в общий словарь сервисов.
        if (class_exists('Service_Nj607_Ontology'))
        {
            try
            {
                $lrow = self::by_id((int) $letter_id);

                // в таблице писем название компании — company_query
                $lcompany = $lrow
                    ? trim((string) ($lrow['company_query'] ?? ($lrow['company'] ?? '')))
                    : '';

                if ($lrow AND mb_strlen($lcompany) >= 3)
                {
                    Service_Nj607_Ontology::remember($lcompany, [
                        'module' => 'coldmail',
                        'code'   => (string) $lrow['code'],
                        'path'   => '/coldmail/'.rawurlencode((string) $lrow['code']),
                        'label'  => 'Холодное письмо',
                        'score'  => 70,
                        'source' => 'nj592',
                    ]);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj592: онтология письма: :e', [':e' => $e->getMessage()]);
            }
        }

        return TRUE;
    }

    public static function set_progress($letter_id, $phase)
    {
        try
        {
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_592_letters SET progress = '.self::_db()->quote(mb_substr((string) $phase, 0, 300))
                .", status = CASE WHEN status IN ('new','running') THEN 'running' ELSE status END, updated_at = now()
                 WHERE id = ".(int) $letter_id);
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj592: set_progress: :e', [':e' => $e->getMessage()]);
        }
    }

    public static function fail($letter_id, $error)
    {
        try
        {
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_592_letters SET status = \'error\', error = '.self::_db()->quote(mb_substr((string) $error, 0, 900))
                .", finished_at = now(), updated_at = now() WHERE id = ".(int) $letter_id." AND status IN ('new','running')");
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj592: fail: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Удалить прогон ПОЛНОСТЬЮ (БД; файлов у писем нет). */
    public static function hard_delete($letter_id)
    {
        $letter = self::by_id((int) $letter_id);

        if ($letter === NULL)
        {
            return FALSE;
        }

        if ( ! empty($letter['job_id']))
        {
            try
            {
                Service_Nj120_JobQueue::instance()->cancel((int) $letter['job_id']);
            }
            catch (Exception $e)
            {
                // отмена не критична
            }
        }

        self::_db()->query(Database::DELETE,
            'DELETE FROM nj_592_letters WHERE id = '.(int) $letter_id);

        return TRUE;
    }

    // ------------------------------------------------------------------
    // Reconcile
    // ------------------------------------------------------------------

    public static function reconcile(array $letter)
    {
        if ( ! in_array($letter['status'], ['new', 'running'], TRUE) OR empty($letter['job_id']))
        {
            return $letter;
        }

        $job = Service_Nj120_JobQueue::instance()->get((int) $letter['job_id']);

        if ($job === NULL)
        {
            return $letter;
        }

        $db = self::_db();

        if ($job['status'] === Service_Nj120_JobQueue::STATUS_DONE)
        {
            $res = $job['result'];
            is_string($res) AND $res = json_decode((string) $res, TRUE);
            $res = is_array($res) ? $res : [];

            if ($letter['result'] !== NULL)
            {
                $db->query(Database::UPDATE,
                    "UPDATE nj_592_letters SET status = 'done', error = NULL, progress = '', finished_at = now(), updated_at = now()"
                    .(isset($res['usage']) ? ', usage = '.$db->quote(json_encode($res['usage'], JSON_UNESCAPED_UNICODE)).'::jsonb' : '')
                    .' WHERE id = '.(int) $letter['id']);
            }
            else
            {
                self::fail((int) $letter['id'], 'Воркер закончил, но варианты не сохранились — попробуйте ещё раз');
            }
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_FAILED)
        {
            self::fail((int) $letter['id'], (string) $job['error']);
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_CANCELLED)
        {
            // Задачу сняли (кнопка «Остановить», авто-стоп молчащего воркера,
            // свипер) — письмо обязано выйти из «работает», иначе страница
            // держит спиннер вечно (та же правка, что в nj591/nj598).
            self::fail((int) $letter['id'], 'Сборка остановлена — запустите заново');
        }
        elseif (in_array($job['status'], [Service_Nj120_JobQueue::STATUS_PENDING, Service_Nj120_JobQueue::STATUS_PROCESSING], TRUE))
        {
            $started = $job['claimed_at'] ?: $job['created_at'];
            $limit   = (int) ($job['timeout_sec'] ?: 1200) + 300;

            if ($started AND (time() - strtotime((string) $started)) > $limit)
            {
                self::fail((int) $letter['id'], 'Задача зависла (воркер недоступен или перезапущен) — попробуйте создать прогон заново');
            }
        }

        return self::by_id((int) $letter['id']);
    }

    // ------------------------------------------------------------------
    // Управление
    // ------------------------------------------------------------------

    public static function remove($letter_id)
    {
        $letter = self::by_id((int) $letter_id);

        if ($letter === NULL)
        {
            return FALSE;
        }

        if ( ! empty($letter['job_id']))
        {
            try
            {
                Service_Nj120_JobQueue::instance()->cancel((int) $letter['job_id']);
            }
            catch (Exception $e)
            {
                // отмена не критична
            }
        }

        self::_db()->query(Database::UPDATE,
            'UPDATE nj_592_letters SET deleted_at = now(), updated_at = now() WHERE id = '.(int) $letter_id);

        return TRUE;
    }

    // ------------------------------------------------------------------
    // MCP
    // ------------------------------------------------------------------

    public static function mcp_letter(array $args)
    {
        $code = (string) ($args['code'] ?? '');
        $l    = $code !== '' ? self::by_code($code) : NULL;

        if ($l === NULL)
        {
            return ['error' => 'not_found'];
        }

        $status_ru = ['new' => 'в очереди', 'running' => 'работает', 'done' => 'готово', 'error' => 'ошибка'];

        return [
            'code'       => (string) $l['code'],
            'company'    => (string) $l['company_query'],
            'profile_code' => (string) $l['profile_code'],
            'domain'     => (string) $l['domain'],
            'product'    => (string) $l['product'],
            'audience'   => (string) $l['audience'],
            'tone'       => (string) $l['tone'],
            'status'     => (string) $l['status'],
            'status_ru'  => (string) ($status_ru[$l['status']] ?? $l['status']),
            'error'      => (string) $l['error'],
            'progress'   => (string) $l['progress'],
            'result'     => self::result_of($l),
            'sources'    => self::sources_of($l),
            'created_at' => (string) $l['created_at'],
        ];
    }
}
