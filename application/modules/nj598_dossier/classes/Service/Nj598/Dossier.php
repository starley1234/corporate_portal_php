<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «AI Досье на работодателя» (nj598_dossier) — Company Intelligence.
 *
 * Соискатель указывает компанию (или ссылку на вакансию) → задача очереди
 * nj598.dossier → воркер через DuckDuckGo собирает: отзывы сотрудников
 * (Хабр Карьера, Dream Job, форумы), техстатьи инженеров (реальный стек и
 * «боли»), новости (суды, сокращения, инвестиции) → LLM синтезирует строго
 * по находкам: Red Flags & Pros, шпаргалка к собеседованию, вероятные
 * вопросы скрининга. Результат — /dossier/<code>.
 *
 * Аноны — кука ubid (Visitor::uid()); владелец удаляет свой артефакт.
 */
class Service_Nj598_Dossier
{
    protected static $_tables_ensured = FALSE;

    public static function ensure_tables()
    {
        if (self::$_tables_ensured)
        {
            return;
        }

        self::$_tables_ensured = TRUE;

        try
        {
            DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_598_dossiers" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NULL,
    "visitor_id"  VARCHAR(64)  NOT NULL DEFAULT '',
    "query"       VARCHAR(500) NOT NULL DEFAULT '',
    "company"     VARCHAR(300) NOT NULL DEFAULT '',
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"       TEXT         NULL,
    "progress"    VARCHAR(300) NOT NULL DEFAULT '',
    "result"      JSONB        NULL,
    "job_id"      BIGINT       NULL,
    "model_used"  VARCHAR(120) NOT NULL DEFAULT '',
    "usage"       JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_598_dossiers_owner_idx" ON "nj_598_dossiers" ("user_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_598_dossiers_visitor_idx" ON "nj_598_dossiers" ("visitor_id", "id" DESC);
ALTER TABLE "nj_598_dossiers" ADD COLUMN IF NOT EXISTS "org_id" BIGINT NULL;
CREATE INDEX IF NOT EXISTS "nj_598_dossiers_org_idx" ON "nj_598_dossiers" ("org_id", "id" DESC);
COMMENT ON COLUMN "nj_598_dossiers"."org_id" IS 'id организации в справочнике nj594 (онтология организаций)';
COMMENT ON TABLE  "nj_598_dossiers" IS 'NJ-598 AI-досье на работодателя: new|running|done|error, result JSONB';
COMMENT ON COLUMN "nj_598_dossiers"."visitor_id" IS 'Visitor::uid(): u:<id> или g:<ubid> — владелец артефакта';
SQL
            )->execute();
        }
        catch (Throwable $e)
        {
            self::$_tables_ensured = FALSE;
            Kohana::$log->add(Log::WARNING, 'nj598: ensure_tables: :e', [':e' => $e->getMessage()]);
        }
    }

    protected static function _db()
    {
        return Database::instance();
    }

    protected static function _cfg($key, $default)
    {
        return Kohana::$config->load('nj598_dossier.'.$key) ?: $default;
    }

    /** Разобрать ввод: ссылка на вакансию → company из host; иначе строка. */
    public static function parse_query($raw)
    {
        $raw = trim((string) $raw);

        if ($raw === '')
        {
            return ['error' => 'Укажите компанию или ссылку на вакансию'];
        }

        $company = '';

        if (preg_match('~https?://\S+~iu', $raw, $m))
        {
            $host = parse_url($m[0], PHP_URL_HOST);
            $host = is_string($host) ? preg_replace('~^www\.~', '', mb_strtolower($host)) : '';

            // hh.ru / superjob и т.п. — сам джоб-борд не компания: берём slug вакансии
            $boards = ['hh.ru', 'superjob.ru', 'rabota.ru', 'zarplata.ru', 'career.habr.com', 'habr.com', 'linkedin.com', 'dreamjob.ru'];

            if ($host !== '' AND ! in_array($host, $boards, TRUE))
            {
                $company = $host; // корпоративная страница вакансии — обычно домен работодателя
            }
            else
            {
                $path = (string) parse_url($m[0], PHP_URL_PATH);
                $slug = trim(str_replace('-', ' ', preg_replace('~[^a-zа-я0-9\-]+~iu', ' ', $path)));
                $company = mb_substr(trim(preg_replace('~\s+~u', ' ', $slug)), 0, 120);
            }

            if ($company === '')
            {
                return ['error' => 'Не удалось вычленить компанию из ссылки — попробуйте указать название компании словами'];
            }
        }
        else
        {
            $company = mb_substr($raw, 0, 200);
        }

        return ['company' => $company, 'query' => mb_substr($raw, 0, 500)];
    }

    /** Нормализованное имя компании (для сопоставления «та же организация»). */
    public static function norm_company($name)
    {
        $s = mb_strtolower((string) $name);
        $s = preg_replace('~[^a-zа-я0-9 ]+~iu', ' ', $s);
        $s = trim((string) preg_replace('~\s+~u', ' ', $s));
        $s = preg_replace('~^(ооо|оао|ао|пао|зао|ип|банк)\s+~u', '', (string) $s);

        return mb_substr((string) $s, 0, 120);
    }

    /** Возраст досье в днях (от created_at). */
    public static function age_days(array $r)
    {
        $ts = strtotime((string) ($r['created_at'] ?? ''));

        return $ts ? max(0, (int) floor((time() - $ts) / 86400)) : 0;
    }

    /** Последнее досье по этой организации (нормализованное вхождение) или NULL. */
    public static function find_latest_by_company($company)
    {
        self::ensure_tables();
        $n  = self::norm_company($company);
        $db = self::_db();

        if ($n === '')
        {
            return NULL;
        }

        $needle = $db->quote($n);
        $row = $db->query(Database::SELECT,
            "SELECT * FROM nj_598_dossiers
             WHERE position($needle in regexp_replace(regexp_replace(lower(company), '[^a-zа-я0-9 ]', ' ', 'g'), '\\s+', ' ', 'g')) > 0
             ORDER BY id DESC LIMIT 1", FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Последнее досье, привязанное к организации справочника (org_id), или NULL. */
    public static function find_latest_by_org($org_id)
    {
        self::ensure_tables();
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_598_dossiers WHERE org_id = '.(int) $org_id.' ORDER BY id DESC LIMIT 1', FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Создать досье и поставить задачу в очередь ($force — минуя дедуп). */
    public static function create($user_id, $visitor_uid, $raw_query, $force = FALSE)
    {
        self::ensure_tables();
        $p = self::parse_query($raw_query);

        if (isset($p['error']))
        {
            return $p;
        }

        // nj601_billing: списание кредитов за сбор досье (после валидации ввода)
        $charge = Service_Nj601_Billing::charge('nj598_dossier',
            Service_Nj601_Billing::subject_of($user_id, $visitor_uid));

        if (isset($charge['error']))
        {
            return $charge;
        }

        // онтология организаций: сначала ищем организацию в справочнике nj594
        $org594 = NULL;

        if (class_exists('Service_Nj594_Orgs'))
        {
            try
            {
                $found594 = Service_Nj594_Orgs::search($p['company'], 2);

                if (count($found594) === 1)
                {
                    $org594 = (array) $found594[0];
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj598: nj594 search: :e', [':e' => $e->getMessage()]);
            }
        }

        // повторное досье по той же организации не плодим: ведём к последнему
        // (сначала по связке со справочником, затем по нормализованному имени)
        if ( ! $force)
        {
            $prev = ($org594 !== NULL) ? self::find_latest_by_org((int) $org594['id']) : NULL;

            if ($prev === NULL)
            {
                $prev = self::find_latest_by_company($p['company']);
            }

            if ($prev !== NULL)
            {
                return [
                    'exists'     => (string) $prev['code'],
                    'company'    => (string) $prev['company'],
                    'age_days'   => self::age_days((array) $prev),
                    'created_at' => (string) $prev['created_at'],
                ];
            }
        }

        $db   = self::_db();
        $code = (string) self::_cfg('code_prefix', 'dos').bin2hex(random_bytes((int) self::_cfg('code_bytes', 4)));

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_598_dossiers (code, user_id, visitor_id, query, company)
             VALUES ('.$db->quote($code).', '.($user_id ? (int) $user_id : 'NULL').',
                     '.$db->quote((string) $visitor_uid).', '.$db->quote($p['query']).',
                     '.$db->quote($p['company']).')
             RETURNING id, code', FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'Не удалось создать досье — попробуйте ещё раз.'];
        }

        // привязка к организации справочника (онтология организаций)
        if ($org594 !== NULL)
        {
            try
            {
                $db->query(Database::SELECT,
                    'UPDATE nj_598_dossiers SET org_id = '.(int) $org594['id'].'
                     WHERE id = '.(int) $row['id'], FALSE);
                Service_Nj594_Orgs::link('nj598', $code, (int) $org594['id'],
                    (string) $p['company'], '/dossier/'.$code, 'AI-досье');
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj598: nj594 link: :e', [':e' => $e->getMessage()]);
            }
        }

        $job = self::_dispatch((int) $row['id'], $code, $p['company'], $p['query'], $user_id, (string) $visitor_uid);

        $db->query(Database::SELECT,
            'UPDATE nj_598_dossiers SET status = '.($job ? "'running'" : "'new'").', job_id = '.($job ?: 'NULL').'
             WHERE id = '.(int) $row['id'], FALSE);

        return ['ok' => TRUE, 'code' => $code];
    }

    protected static function _dispatch($id, $code, $company, $query, $user_id = NULL, $visitor_uid = '')
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        try
        {
            // владелец обязателен: без created_by/visitor_id nj597 сочтёт задачу
            // анонимной и не пришлёт колокольчик/письмо (найдено 21.09)
            $job = Service_Nj120_JobQueue::instance()->push('nj598.dossier', [
                'dossier_id' => $id,
                'code'       => $code,
                'company'    => $company,
                'query'      => $query,
            ], [
                'created_by' => $user_id,
                'visitor_id' => $visitor_uid,
            ]);

            return $job ? (int) $job : NULL;
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj598: dispatch: :e', [':e' => $e->getMessage()]);

            return NULL;
        }
    }

    /** Досье по коду или NULL. */
    public static function by_code($code)
    {
        self::ensure_tables();
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_598_dossiers WHERE code = '.self::_db()->quote((string) $code).' LIMIT 1', FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /**
     * Восстановление: (а) running, но результат уже в БД (порченый статус
     * до фикса progress) → done; (б) зависшее running (воркер молчит > 25 мин) → error.
     */
    public static function reconcile(array $r)
    {
        if ($r['status'] === 'running'
            AND (string) $r['result'] !== '' AND $r['result'] !== NULL)
        {
            self::_db()->query(Database::SELECT,
                'UPDATE nj_598_dossiers SET status = \'done\', progress = \'Досье готово\',
                 finished_at = coalesce(finished_at, now()), updated_at = now()
                 WHERE id = '.(int) $r['id'], FALSE);

            $r['status'] = 'done';
            $r['progress'] = 'Досье готово';

            return $r;
        }

        // Задача закрылась (авто-стоп молчащего воркера, свипер, «Остановить»),
        // а досье осталось «Разведка идёт». Раньше это ждало 25 минут тишины —
        // владелец всё это время видел спиннер без объяснений.
        if (in_array((string) $r['status'], ['running', 'new'], TRUE)
            AND ! empty($r['job_id'])
            AND class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                $job = Service_Nj120_JobQueue::instance()->get((int) $r['job_id']);

                if ($job AND in_array((string) $job['status'], ['failed', 'cancelled'], TRUE))
                {
                    $err = ((string) $job['status'] === 'cancelled')
                        ? 'Разведка остановлена — запустите заново'
                        : (trim((string) $job['error']) !== ''
                            ? (string) $job['error']
                            : 'Воркер не смог собрать досье — попробуйте ещё раз');

                    self::_db()->query(Database::SELECT,
                        'UPDATE nj_598_dossiers SET status = \'error\', error = '.self::_db()->quote($err).',
                         finished_at = now(), updated_at = now() WHERE id = '.(int) $r['id'], FALSE);

                    $r['status'] = 'error';
                    $r['error']  = $err;

                    return $r;
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj598 reconcile job: :e', [':e' => $e->getMessage()]);
            }
        }

        if ($r['status'] === 'running'
            AND strtotime((string) $r['updated_at']) < time() - 1500)
        {
            $this_db_q = self::_db()->quote('воркер не отчитался более 25 минут — задача прервана');
            self::_db()->query(Database::SELECT,
                'UPDATE nj_598_dossiers SET status = \'error\',
                 error = '.$this_db_q.', finished_at = now() WHERE id = '.(int) $r['id'], FALSE);

            $r['status'] = 'error';
            $r['error'] = 'воркер не отчитался более 25 минут — задача прервана';
        }

        return $r;
    }

    /**
     * Список «моих» досье: по user_id ИЛИ по visitor-куке — включая случаи
     * «создал гостем, потом залогинился в том же браузере» (связка g:<ubid>).
     */
    public static function list_for($user_id, $visitor_uid, $limit = 20, $ubid = NULL)
    {
        self::ensure_tables();
        $db = self::_db();
        $guest = ($ubid !== NULL AND $ubid !== '') ? 'g:'.$ubid : '';

        return $db->query(Database::SELECT,
            'SELECT * FROM nj_598_dossiers
             WHERE '.($user_id ? 'user_id = '.(int) $user_id : 'FALSE').'
                OR visitor_id = '.$db->quote((string) $visitor_uid).'
                '.($guest !== '' ? 'OR visitor_id = '.$db->quote($guest) : '').'
             ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /**
     * Прогресс от воркера. ВАЖНО: только пока досье new/running — финальный
     * progress «Досье готово» приходит ПОСЛЕ save_result и не должен
     * перетирать статус done обратно в running (урок 21.09).
     */
    public static function progress($id, $phase)
    {
        self::ensure_tables();
        self::_db()->query(Database::SELECT,
            'UPDATE nj_598_dossiers SET status = \'running\', progress = '.self::_db()->quote(mb_substr((string) $phase, 0, 300)).',
             updated_at = now() WHERE id = '.(int) $id.' AND status IN (\'new\', \'running\')', FALSE);
    }

    /**
     * Остановка разведки вручную (кнопка «Остановить» в статус-ряде): досье
     * обязано выйти из «Разведка идёт» сразу, а не ждать 25 минут тишины.
     */
    public static function stop($id)
    {
        self::ensure_tables();
        self::_db()->query(Database::SELECT,
            'UPDATE nj_598_dossiers SET status = \'error\', progress = \'\',
             error = \'Разведка остановлена вручную — запустите заново\',
             finished_at = now(), updated_at = now()
             WHERE id = '.(int) $id.' AND status IN (\'new\', \'running\')', FALSE);
    }

    /**
     * Онтология (nj607): компания из досье попадает в общий словарь сервисов и
     * связывается с досье. Вызывается после сохранения результата; ошибки
     * онтологии досье не ломают.
     */
    protected static function _ontology($dossier_id, $company)
    {
        if ( ! class_exists('Service_Nj607_Ontology') OR mb_strlen(trim((string) $company)) < 3)
        {
            return;
        }

        try
        {
            $row = self::_db()->query(Database::SELECT,
                'SELECT code FROM nj_598_dossiers WHERE id = '.(int) $dossier_id, FALSE)->current();

            if ( ! $row)
            {
                return;
            }

            Service_Nj607_Ontology::remember((string) $company, [
                'module' => 'dossier',
                'code'   => (string) $row['code'],
                'path'   => '/dossier/'.rawurlencode((string) $row['code']),
                'label'  => 'AI-досье',
                'score'  => 85,
                'source' => 'nj598',
            ]);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj598: онтология досье: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Результат от воркера (строгий JSON: red_flags, pros, cheatsheet). */
    public static function save_result($id, array $result, $company, $model_used, array $usage)
    {
        self::ensure_tables();
        $db = self::_db();
        $db->query(Database::SELECT,
            'UPDATE nj_598_dossiers SET status = \'done\',
             company = '.$db->quote(mb_substr((string) $company, 0, 300)).',
             result = '.$db->quote(json_encode($result, JSON_UNESCAPED_UNICODE)).'::jsonb,
             model_used = '.$db->quote(mb_substr((string) $model_used, 0, 120)).',
             usage = '.$db->quote(json_encode($usage, JSON_UNESCAPED_UNICODE)).'::jsonb,
             progress = \'Досье готово\', finished_at = now(), updated_at = now()
             WHERE id = '.(int) $id, FALSE);

        // Обратная связь в очередь (образец nj602): досье ФАКТИЧЕСКИ принято
        // сайтом — задача nj120 обязана стать «Готово», даже если воркер не
        // успел complete() или свипер отказал её за секунды до доставки.
        // Идемпотентно; без привязки job_id ничего не делает.
        try
        {
            $jrow = $db->query(Database::SELECT,
                'SELECT job_id FROM nj_598_dossiers WHERE id = '.(int) $id, FALSE)->current();

            if (class_exists('Service_Nj120_JobQueue') AND $jrow AND (int) ($jrow['job_id'] ?? 0) > 0)
            {
                Service_Nj120_JobQueue::instance()->mark_delivered(
                    (int) $jrow['job_id'],
                    'Досье принято сайтом — задача закрыта из приёмки nj598',
                );
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj598: связать задачу с готовым досье не удалось: :e',
                [':e' => $e->getMessage()]);
        }

        self::_bind_org($id, $company, $result);
        self::_ontology($id, $company);
    }

    /**
     * Онтология организаций: привязать досье к справочнику nj594 по реквизитам
     * из результата разведки (владелец 21.09: «если нет в справочнике — LLM
     * определяет ОГРН/ОГРНИП/ИНН, справочник попутно заполняем»).
     */
    protected static function _bind_org($id, $company, array $result)
    {
        if ( ! class_exists('Service_Nj594_Orgs'))
        {
            return;
        }

        try
        {
            $db  = self::_db();
            $row = $db->query(Database::SELECT,
                'SELECT code, org_id FROM nj_598_dossiers WHERE id = '.(int) $id, FALSE)->current();

            if ( ! $row)
            {
                return;
            }

            // 1) реквизиты: явные из воркера → регэксп по тексту результата
            $ids  = (array) ($result['identifiers'] ?? []);
            $inn  = preg_replace('/\D/', '', (string) ($ids['inn'] ?? ''));
            $ogrn = preg_replace('/\D/', '', (string) ($ids['ogrn'] ?? ($ids['ogrnip'] ?? '')));

            if ($ogrn === '' OR $inn === '')
            {
                $text = (string) json_encode($result, JSON_UNESCAPED_UNICODE);

                if ($ogrn === '' AND preg_match_all('~\d{13,15}~u', $text, $m))
                {
                    foreach ($m[0] as $cand)
                    {
                        if (Service_Nj594_Orgs::ogrn_valid($cand))
                        {
                            $ogrn = $cand;
                            break;
                        }
                    }
                }

                if ($inn === '' AND preg_match_all('~\d{10,12}~u', $text, $m))
                {
                    foreach ($m[0] as $cand)
                    {
                        if (Service_Nj594_Orgs::inn_valid($cand))
                        {
                            $inn = $cand;
                            break;
                        }
                    }
                }
            }

            // 2) организация: уже в справочнике → иначе официальная карточка ФНС
            $org = ($ogrn !== '') ? Service_Nj594_Orgs::by_ogrn($ogrn) : NULL;

            if ($org === NULL AND $inn !== '')
            {
                $by_inn = Service_Nj594_Orgs::search($inn, 1);

                if ( ! empty($by_inn))
                {
                    $org = (array) $by_inn[0];
                }
            }

            if ($org === NULL AND ($ogrn !== '' OR $inn !== ''))
            {
                $ref = Service_Nj594_Orgs::refresh(($ogrn !== '') ? $ogrn : $inn);

                if ( ! empty($ref['ok']) AND ! empty($ref['card']))
                {
                    $org = (array) $ref['card'];
                }
            }

            if ($org === NULL AND $ogrn === '' AND $inn === '')
            {
                // реквизитов не нашли вовсе — единственное точное совпадение по имени
                $by_name = Service_Nj594_Orgs::search($company, 2);

                if (count($by_name) === 1)
                {
                    $org = (array) $by_name[0];
                }
            }

            if ($org === NULL OR (int) ($org['id'] ?? 0) <= 0)
            {
                return; // организации достоверно не определили — остаётся ссылка /orgs?q=
            }

            $org_id = (int) $org['id'];

            if ((int) $row['org_id'] !== $org_id)
            {
                $db->query(Database::SELECT,
                    'UPDATE nj_598_dossiers SET org_id = '.$org_id.' WHERE id = '.(int) $id, FALSE);
            }

            Service_Nj594_Orgs::link('nj598', (string) $row['code'], $org_id,
                (string) $company, '/dossier/'.(string) $row['code'], 'AI-досье');
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj598: bind_org: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Ошибка воркера (не затирает уже сохранённый результат). */
    public static function save_error($id, $error)
    {
        self::ensure_tables();
        self::_db()->query(Database::SELECT,
            'UPDATE nj_598_dossiers SET status = \'error\', error = '.self::_db()->quote(mb_substr((string) $error, 0, 2000)).',
             finished_at = now(), updated_at = now()
             WHERE id = '.(int) $id.' AND result IS NULL', FALSE);
    }

    /** Удалить СВОЁ досье (user_id / visitor-uid / связка гостя g:<ubid>). */
    public static function delete_own($code, $user_id, $visitor_uid, $ubid = NULL)
    {
        self::ensure_tables();
        $r = self::by_code($code);

        if ($r === NULL)
        {
            return ['error' => 'Досье не найдено'];
        }

        $guest = ($ubid !== NULL AND $ubid !== '') ? 'g:'.$ubid : '';
        $mine = (($user_id AND (int) $r['user_id'] === (int) $user_id)
            OR ($visitor_uid !== '' AND (string) $r['visitor_id'] === (string) $visitor_uid)
            OR ($guest !== '' AND (string) $r['visitor_id'] === $guest));

        if ( ! $mine)
        {
            return ['error' => 'Это досье создано другим посетителем'];
        }

        self::_db()->query(Database::SELECT,
            'DELETE FROM nj_598_dossiers WHERE id = '.(int) $r['id'], FALSE);

        return ['ok' => TRUE];
    }

    // ------------------------------------------------------------- админка

    /** Управление: сводные счётчики по статусам. */
    public static function admin_stats()
    {
        self::ensure_tables();
        $out = ['new' => 0, 'running' => 0, 'done' => 0, 'error' => 0, 'total' => 0];

        foreach (self::_db()->query(Database::SELECT,
            'SELECT status, count(*) AS c FROM nj_598_dossiers GROUP BY status', FALSE)->as_array() as $r)
        {
            $out[(string) $r['status']] = (int) $r['c'];
            $out['total'] += (int) $r['c'];
        }

        return $out;
    }

    /** Управление: последние досье всех пользователей. */
    public static function admin_list($limit = 50)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT d.*, coalesce(us.username, d.visitor_id) AS owner
             FROM nj_598_dossiers d
             LEFT JOIN users us ON us.id = d.user_id
             ORDER BY d.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Управление: повторить разведку (сброс результата и новый пинок воркеру). */
    public static function retry($code)
    {
        $r = self::by_code($code);

        if ($r === NULL)
        {
            return ['error' => 'Досье не найдено'];
        }

        if (in_array($r['status'], ['running', 'new'], TRUE))
        {
            return ['error' => 'Разведка уже идёт — дождитесь завершения'];
        }

        $db = self::_db();
        $job = self::_dispatch((int) $r['id'], (string) $r['code'], (string) $r['company'], (string) $r['query'],
            $r['user_id'] !== NULL ? (int) $r['user_id'] : NULL, (string) $r['visitor_id']);

        $db->query(Database::SELECT,
            'UPDATE nj_598_dossiers SET status = '.($job ? "'running'" : "'new'").',
                 error = NULL, progress = '.$db->quote($job ? 'Повторная разведка запущена' : 'Жду воркер…').',
                 job_id = '.($job ?: 'NULL').', finished_at = NULL, updated_at = now()
             WHERE id = '.(int) $r['id'], FALSE);

        return ['ok' => TRUE, 'job' => $job];
    }

    /** Короткий слаг результата для UI. */
    public static function result_of(array $r)
    {
        $j = json_decode((string) $r['result'], TRUE);

        return is_array($j) ? $j : [];
    }

    /** MCP: статус досье. */
    public static function mcp_status(array $args)
    {
        $r = self::by_code((string) ($args['code'] ?? ''));

        if ($r === NULL)
        {
            return ['error' => 'Досье не найдено'];
        }

        $res = self::result_of($r);

        return [
            'code'     => (string) $r['code'],
            'company'  => (string) $r['company'],
            'status'   => (string) $r['status'],
            'url'      => '/dossier/'.$r['code'],
            'red_flags' => count($res['red_flags'] ?? []),
            'pros'     => count($res['pros'] ?? []),
        ];
    }
}
