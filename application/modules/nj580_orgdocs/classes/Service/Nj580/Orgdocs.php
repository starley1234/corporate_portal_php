<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сервис «Документы организации» (NJ-580, /docs).
 *
 * Поток: пакет (сведения об организации + типы документов) → задача воркера
 * nj120 (канал nj580.generate) → воркер пишет документы (LLM + DuckDuckGo),
 * сам создаёт/обновляет строки документов через /docs/api/doc и выгружает
 * файлы DOCX/PDF/XLSX/ZIP через /docs/api/upload (оформление по ГОСТ
 * Р 7.0.97-2016 — сборка в workers/n2j_workers/docgen).
 *
 * Затраты LLM — построчно в nj_580_usage (pg-режим воркера пишет сам,
 * http-режим возвращает в job.result — дозаписываем здесь).
 */
class Service_Nj580_Orgdocs {

    const CHANNEL_GENERATE = 'nj580.generate';

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
            $cfg = (array) Kohana::$config->load('nj580_orgdocs');
        }

        return Arr::path($cfg, $key, $default);
    }

    /** Каталог типов документов: ключ → русское название. */
    public static function doc_catalog()
    {
        $out = [];

        foreach ((array) self::_cfg('doc_groups', []) as $group => $types)
        {
            foreach ((array) $types as $key => $title)
            {
                $out[(string) $key] = (string) $title;
            }
        }

        return $out;
    }

    public static function doc_groups()
    {
        return (array) self::_cfg('doc_groups', []);
    }

    /**
     * Страховка: миграции nj111 применяются на проде вручную, поэтому модуль
     * сам создаёт свои таблицы (идемпотентный CREATE TABLE IF NOT EXISTS,
     * тот же DDL, что в pgMigration_Nj_580_001). Максимум один вызов на
     * запрос; ошибка не валит страницу (в лог).
     */
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
CREATE TABLE IF NOT EXISTS "nj_580_packages" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NOT NULL,
    "title"       VARCHAR(200) NOT NULL DEFAULT '',
    "org_name"    VARCHAR(300) NOT NULL DEFAULT '',
    "org_info"    JSONB        NULL,
    "doc_types"   JSONB        NULL,
    "use_search"  BOOLEAN      NOT NULL DEFAULT FALSE,
    "raw_text"    TEXT         NULL,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"       TEXT         NULL,
    "summary"     TEXT         NOT NULL DEFAULT '',
    "progress"    VARCHAR(300) NOT NULL DEFAULT '',
    "job_id"      BIGINT       NULL,
    "deleted_at"  TIMESTAMPTZ  NULL,
    "ogrn"        VARCHAR(15)  NULL,
    "org_id"      BIGINT       NULL,
    "usage"       JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_580_packages_user_idx" ON "nj_580_packages" ("user_id", "id" DESC);
ALTER TABLE "nj_580_packages" ADD COLUMN IF NOT EXISTS "progress" VARCHAR(300) NOT NULL DEFAULT '';
ALTER TABLE "nj_580_packages" ADD COLUMN IF NOT EXISTS "raw_text" TEXT NULL;
ALTER TABLE "nj_580_packages" ADD COLUMN IF NOT EXISTS "deleted_at" TIMESTAMPTZ NULL;
ALTER TABLE "nj_580_packages" ADD COLUMN IF NOT EXISTS "ogrn" VARCHAR(15) NULL;
ALTER TABLE "nj_580_packages" ADD COLUMN IF NOT EXISTS "org_id" BIGINT NULL;
CREATE TABLE IF NOT EXISTS "nj_580_organizations" (
    "id"         BIGSERIAL PRIMARY KEY,
    "ogrn"       VARCHAR(15)  NOT NULL UNIQUE,
    "inn"        VARCHAR(12)  NOT NULL DEFAULT '',
    "full_name"  VARCHAR(500) NOT NULL DEFAULT '',
    "address"    VARCHAR(500) NOT NULL DEFAULT '',
    "okved"      VARCHAR(300) NOT NULL DEFAULT '',
    "director"   VARCHAR(300) NOT NULL DEFAULT '',
    "profile"    JSONB        NULL,
    "source"     VARCHAR(40)  NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS "nj_580_feedback" (
    "id"         BIGSERIAL PRIMARY KEY,
    "package_id" BIGINT      NOT NULL REFERENCES "nj_580_packages"("id") ON DELETE CASCADE,
    "user_id"    BIGINT      NULL,
    "text"       TEXT        NOT NULL,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    "used_at"    TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS "nj_580_feedback_pkg_idx" ON "nj_580_feedback" ("package_id", "id");
CREATE TABLE IF NOT EXISTS "nj_580_documents" (
    "id"          BIGSERIAL PRIMARY KEY,
    "package_id"  BIGINT      NOT NULL REFERENCES "nj_580_packages"("id") ON DELETE CASCADE,
    "doc_key"     VARCHAR(64) NOT NULL DEFAULT '',
    "ord"         INT         NOT NULL DEFAULT 0,
    "title"       VARCHAR(300) NOT NULL DEFAULT '',
    "content"     JSONB       NULL,
    "status"      VARCHAR(12) NOT NULL DEFAULT 'new',
    "error"       TEXT        NULL,
    "created_at"  TIMESTAMPTZ NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS "nj_580_documents_pkg_idx" ON "nj_580_documents" ("package_id", "ord");
CREATE TABLE IF NOT EXISTS "nj_580_files" (
    "id"          BIGSERIAL PRIMARY KEY,
    "package_id"  BIGINT      NOT NULL REFERENCES "nj_580_packages"("id") ON DELETE CASCADE,
    "document_id" BIGINT      NULL REFERENCES "nj_580_documents"("id") ON DELETE CASCADE,
    "kind"        VARCHAR(8)  NOT NULL,
    "path"        VARCHAR(255) NOT NULL,
    "size"        BIGINT      NOT NULL DEFAULT 0,
    "created_at"  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_580_files_pkg_idx" ON "nj_580_files" ("package_id", "kind");
CREATE TABLE IF NOT EXISTS "nj_580_usage" (
    "id"                BIGSERIAL PRIMARY KEY,
    "package_id"        BIGINT      NOT NULL,
    "document_id"       BIGINT      NULL,
    "job_id"            BIGINT      NULL,
    "phase"             VARCHAR(40) NOT NULL DEFAULT '',
    "model"             VARCHAR(120) NOT NULL DEFAULT '',
    "prompt_tokens"     INT         NOT NULL DEFAULT 0,
    "completion_tokens" INT         NOT NULL DEFAULT 0,
    "duration_ms"       INT         NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_580_usage_pkg_idx" ON "nj_580_usage" ("package_id", "id");
SQL
            )->execute();
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj580: ensure_tables: :e', [':e' => $e->getMessage()]);
        }
    }

    /** public_html/storage/nj580 (bin/setup.sh создаёт storage на деплое). */
    public static function storage_dir()
    {
        $dir = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj580';

        if ( ! is_dir($dir))
        {
            @mkdir($dir, 0775, TRUE);
        }

        return $dir;
    }

    // ------------------------------------------------------------------
    // Создание и жизненный цикл пакета
    // ------------------------------------------------------------------

    /**
     * Создать пакет документов и поставить задачу воркеру.
     *
     * @param  int    $user_id
     * @param  string $org_name
     * @param  array  $org_info   отрасль, размер, структура, требования…
     * @param  array  $doc_types  ключи типов или ['auto']
     * @param  bool   $use_search референсы DuckDuckGo
     * @param  string $raw_text   сырой текст о компании (необязательно) —
     *                            воркер сам выведет из него организацию/сферу/структуру
     * @return array  ['code'=>…] | ['error'=>…]
     */
    public static function create($user_id, $org_name, array $org_info, array $doc_types, $use_search, $raw_text = '', $ogrn = '')
    {
        self::ensure_tables();
        $org_name = trim((string) $org_name);
        $raw_text = trim((string) $raw_text);
        $ogrn     = preg_replace('/\D/', '', (string) $ogrn);

        // точка входа — только ОГРН/ОГРНИП/ИНН: он обязателен, официальные
        // реквизиты подтягиваются из реестра сервиса (кэш ЕГРЮЛ)
        if ($ogrn === '')
        {
            return ['error' => 'Укажите ОГРН / ОГРНИП / ИНН — это обязательно: по номеру мы подтягиваем официальные реквизиты.'];
        }

        if ( ! self::ogrn_valid($ogrn))
        {
            return ['error' => 'ОГРН/ОГРНИП/ИНН указан с ошибкой (не сходится контрольная цифра).'];
        }

        // карточка уже в реестре? название и реквизиты — только официальные
        $org = self::org_by_ogrn($ogrn);

        if ($org)
        {
            if (trim((string) $org['full_name']) !== '')
            {
                $org_name = (string) $org['full_name'];
            }

            foreach (['inn', 'address', 'okved', 'director'] as $k)
            {
                if (trim((string) ($org[$k] ?? '')) !== '' AND trim((string) ($org_info[$k] ?? '')) === '')
                {
                    $org_info[$k] = (string) $org[$k];
                }
            }
        }

        if ($org_name === '')
        {
            $org_name = 'Разбираем: реквизиты по ОГРН'; // воркер уточнит после org_lookup
        }

        $catalog    = self::doc_catalog();
        $doc_types  = array_values(array_unique(array_map('strval', $doc_types)));
        $valid      = [];

        foreach ($doc_types as $t)
        {
            if ($t === 'auto')
            {
                $valid = ['auto'];
                break;
            }

            if (isset($catalog[$t]))
            {
                $valid[] = $t;
            }
        }

        if ( ! $valid)
        {
            return ['error' => 'Выберите хотя бы один тип документов или «подбор ИИ».'];
        }

        $db   = self::_db();
        $code = (string) self::_cfg('code_prefix', 'org').bin2hex(random_bytes((int) self::_cfg('code_bytes', 4)));

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_580_packages (code, user_id, title, org_name, org_info, doc_types, use_search, raw_text, ogrn, org_id)
             VALUES ('.$db->quote($code).', '.(int) $user_id.', '.$db->quote(mb_substr($org_name, 0, 200)).',
                     '.$db->quote(mb_substr($org_name, 0, 300)).',
                     '.$db->quote(json_encode($org_info, JSON_UNESCAPED_UNICODE)).'::jsonb,
                     '.$db->quote(json_encode($valid, JSON_UNESCAPED_UNICODE)).'::jsonb,
                     '.($use_search ? 'TRUE' : 'FALSE').',
                     '.($raw_text === '' ? 'NULL' : $db->quote(mb_substr($raw_text, 0, 100000))).',
                     '.$db->quote($ogrn).',
                     '.($org ? (int) $org['id'] : 'NULL').')
             RETURNING id, code', FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'Не удалось создать пакет — попробуйте ещё раз.'];
        }

        // общий справочник организаций (nj594): карточка + связь «модуль → пакет»,
        // чтобы карточка организации показывала все материалы платформы
        if (class_exists('Service_Nj594_Orgs'))
        {
            try
            {
                $org_id_594 = Service_Nj594_Orgs::upsert($ogrn, [
                    'inn'       => (string) ($org['inn'] ?? ''),
                    'full_name' => (string) ($org['full_name'] ?? $org_name),
                    'address'   => (string) ($org['address'] ?? ''),
                    'okved'     => (string) ($org['okved'] ?? ''),
                    'director'  => (string) ($org['director'] ?? ''),
                ], 'nj580');

                if ($org_id_594)
                {
                    Service_Nj594_Orgs::link('nj580', $code, $org_id_594, mb_substr($org_name, 0, 300), '/docs/'.$code, 'docs');
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj580: nj594 link: :e', [':e' => $e->getMessage()]);
            }
        }

        $job = self::_dispatch(self::CHANNEL_GENERATE, (int) $row['id'], [
            'package_id' => (int) $row['id'],
            'code'       => $code,
            'org_name'   => mb_substr($org_name, 0, 300),
            'org_info'   => $org_info,
            'doc_types'  => $valid,
            'use_search' => (bool) $use_search,
            'raw_text'   => mb_substr($raw_text, 0, 100000),
            'ogrn'       => $ogrn,
        ]);

        if ($job === 0)
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_580_packages SET status = 'error',
                    error = 'Очередь задач недоступна'
                 WHERE id = ".(int) $row['id']);

            return ['error' => 'Очередь задач недоступна — попробуйте позже.'];
        }

        return ['code' => $code];
    }

    /** POST /docs/<code>/regen — пересобрать пакет заново (владелец). */
    public static function regen($user_id, $code)
    {
        $pkg = self::by_code($code);

        if ($pkg === NULL OR ((int) $pkg['user_id'] !== (int) $user_id AND ! Auth::instance()->logged_in('admin')))
        {
            return ['error' => 'Пакет не найден или нет доступа'];
        }

        if (in_array($pkg['status'], ['new', 'running'], TRUE))
        {
            return ['error' => 'Пакет уже собирается — дождитесь завершения'];
        }

        $db = self::_db();

        // старые файлы на диске — по возможности удалить, строки уйдут каскадом
        foreach ($db->query(Database::SELECT,
            'SELECT path FROM nj_580_files WHERE package_id = '.(int) $pkg['id'], FALSE)->as_array() as $f)
        {
            @unlink(self::file_path((string) $f['path']));
        }

        $db->query(Database::DELETE, 'DELETE FROM nj_580_documents WHERE package_id = '.(int) $pkg['id']);
        // пакетные файлы (реестр, ZIP) не каскадятся через документы —
        // чистим их строки явно, иначе останутся ссылки на несуществующие файлы
        $db->query(Database::DELETE, 'DELETE FROM nj_580_files WHERE package_id = '.(int) $pkg['id']);
        $db->query(Database::UPDATE,
            "UPDATE nj_580_packages SET status = 'new', error = NULL, summary = '', usage = NULL,
                job_id = NULL, updated_at = now(), finished_at = NULL WHERE id = ".(int) $pkg['id']);

        $types  = json_decode((string) $pkg['doc_types'], TRUE);
        $info   = json_decode((string) $pkg['org_info'], TRUE);
        $job    = self::_dispatch(self::CHANNEL_GENERATE, (int) $pkg['id'], [
            'package_id'  => (int) $pkg['id'],
            'code'        => (string) $pkg['code'],
            'org_name'    => (string) $pkg['org_name'],
            'org_info'    => is_array($info) ? $info : [],
            'doc_types'   => is_array($types) ? $types : ['auto'],
            'use_search'  => (bool) $pkg['use_search'],
            'raw_text'    => (string) $pkg['raw_text'],
            'ogrn'        => (string) $pkg['ogrn'],
            'corrections' => self::feedback_for_worker((int) $pkg['id']),
        ]);

        self::feedback_mark_used((int) $pkg['id']);

        if ($job === 0)
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_580_packages SET status = 'error', error = 'Очередь задач недоступна'
                 WHERE id = ".(int) $pkg['id']);

            return ['error' => 'Очередь задач недоступна'];
        }

        return ['ok' => TRUE];
    }

    /** Поставить задачу в очередь (created_by = владелец → ему придёт подписка erp217). */
    protected static function _dispatch($channel, $package_id, array $payload)
    {
        $user = self::_db()->query(Database::SELECT,
            'SELECT user_id FROM nj_580_packages WHERE id = '.(int) $package_id, FALSE)->current();

        try
        {
            $job = Service_Nj120_JobQueue::push($channel, $payload, [
                'max_attempts' => 2,
                'module'       => 'nj580',
                'created_by'   => $user ? (int) $user['user_id'] : NULL,
                // 25 мин: при смерти воркера nj120 вернёт задачу в очередь сам
                // (requeue_stale по heartbeat); живой воркер продлевает heartbeat
                'timeout_sec'  => 1500,
            ]);

            self::_db()->query(Database::UPDATE,
                "UPDATE nj_580_packages SET job_id = ".(int) $job.", status = 'running', updated_at = now()
                 WHERE id = ".(int) $package_id);

            return (int) $job;
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj580: не удалось поставить задачу: :e', [':e' => $e->getMessage()]);

            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Reconcile (лениво при чтении статуса)
    // ------------------------------------------------------------------

    public static function reconcile(array $pkg)
    {
        if ( ! in_array($pkg['status'], ['new', 'running'], TRUE) OR empty($pkg['job_id']))
        {
            return $pkg;
        }

        $job = Service_Nj120_JobQueue::instance()->get((int) $pkg['job_id']);

        if ($job === NULL)
        {
            return $pkg;
        }

        $db = self::_db();

        if ($job['status'] === Service_Nj120_JobQueue::STATUS_DONE)
        {
            $res = $job['result'];
            is_string($res) AND $res = json_decode((string) $res, TRUE);
            $res = is_array($res) ? $res : [];

            self::finish((int) $pkg['id'], $res, (int) $pkg['job_id']);
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_FAILED)
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_580_packages SET status = 'error', error = ".$db->quote(mb_substr((string) $job['error'], 0, 900)).",
                 updated_at = now(), finished_at = now() WHERE id = ".(int) $pkg['id']);
        }
        elseif (in_array($job['status'], [Service_Nj120_JobQueue::STATUS_PENDING, Service_Nj120_JobQueue::STATUS_PROCESSING], TRUE))
        {
            // Зависшая задача (воркер убит рестартом и т.п.): timeout_sec + 5 минут
            $started = $job['claimed_at'] ?: $job['created_at'];
            $limit   = (int) ($job['timeout_sec'] ?: 3600) + 300;

            if ($started AND (time() - strtotime((string) $started)) > $limit)
            {
                $db->query(Database::UPDATE,
                    "UPDATE nj_580_packages SET status = 'error',
                        error = 'Задача зависла (воркер недоступен или перезапущен) — нажмите «Пересобрать пакет»',
                        updated_at = now(), finished_at = now() WHERE id = ".(int) $pkg['id']);
            }
        }

        return self::by_id((int) $pkg['id']);
    }

    /**
     * Отменить сборку пакета (управление): ожидающая задача снимается сразу,
     * уже захваченная воркером — на ближайшем контрольном пункте
     * (воркер видит флаг cancel в ответе /docs/api/progress).
     */
    public static function cancel($package_id, $reason = '')
    {
        self::ensure_tables();
        $pkg = self::by_id((int) $package_id);

        if ($pkg === NULL)
        {
            return ['error' => 'Пакет не найден'];
        }

        if ( ! in_array((string) $pkg['status'], ['new', 'running'], TRUE))
        {
            return ['error' => 'Пакет уже завершён (статус: '.$pkg['status'].')'];
        }

        $db = self::_db();
        $db->query(Database::UPDATE,
            "UPDATE nj_580_packages SET status = 'canceled',
                error = ".$db->quote(mb_substr((string) ($reason !== '' ? $reason : 'Сборка отменена администратором'), 0, 900)).",
                progress = '', updated_at = now(), finished_at = now()
             WHERE id = ".(int) $package_id);

        if ( ! empty($pkg['job_id']))
        {
            Service_Nj120_JobQueue::instance()->cancel((int) $pkg['job_id']);
        }

        return ['ok' => TRUE];
    }

    /**
     * Удаление пакета администратором: мягкое (скрыть — deleted_at, файлы и
     * строки на месте, можно вернуть) или полное (строки из БД + файлы с диска).
     */
    public static function admin_delete($package_id, $hard = FALSE)
    {
        self::ensure_tables();
        $pkg = self::by_id((int) $package_id);

        if ($pkg === NULL)
        {
            return ['error' => 'Пакет не найден'];
        }

        $db = self::_db();

        // активную сборку сначала снимаем (ожидающая — сразу, захваченная — на контрольной точке)
        if (in_array((string) $pkg['status'], ['new', 'running'], TRUE) AND ! empty($pkg['job_id']))
        {
            Service_Nj120_JobQueue::instance()->cancel((int) $pkg['job_id']);
        }

        if ($hard)
        {
            foreach ($db->query(Database::SELECT,
                'SELECT path FROM nj_580_files WHERE package_id = '.(int) $package_id, FALSE)->as_array() as $f)
            {
                @unlink(self::file_path((string) $f['path']));
            }

            $db->query(Database::DELETE,
                'DELETE FROM nj_580_packages WHERE id = '.(int) $package_id);

            return ['ok' => 'Пакет удалён полностью (БД и файлы)'];
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_580_packages SET deleted_at = now(), updated_at = now()
             WHERE id = '.(int) $package_id);

        return ['ok' => 'Пакет скрыт из списков (можно вернуть)'];
    }

    public static function admin_restore($package_id)
    {
        self::ensure_tables();
        self::_db()->query(Database::UPDATE,
            'UPDATE nj_580_packages SET deleted_at = NULL, updated_at = now()
             WHERE id = '.(int) $package_id);

        return ['ok' => 'Пакет возвращён в списки'];
    }

    /** Финализация пакета по job.result воркера {ok, summary, usage}. */
    public static function finish($package_id, array $res, $job_id)
    {
        $db  = self::_db();
        $ok  = ! empty($res['ok']);
        $err = (string) Arr::get($res, 'error', '');
        $cur = self::by_id((int) $package_id);
        $canceled = ($cur && (string) $cur['status'] === 'canceled');

        if ($canceled)
        {
            // отменён администратором — поздний результат воркера статус не меняет
            $db->query(Database::UPDATE,
                "UPDATE nj_580_documents SET status = 'canceled',
                    error = COALESCE(NULLIF(error, ''), 'Отменено'),
                    finished_at = now()
                 WHERE package_id = ".(int) $package_id." AND status IN ('new', 'writing')");

            self::_usage_rows($db, (int) $package_id, (int) $job_id, Arr::get($res, 'usage', []));

            return;
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_580_packages SET
                status = '.($ok ? "'done'" : "'error'").',
                error = '.($err !== '' ? $db->quote(mb_substr($err, 0, 900)) : 'NULL').',
                summary = '.$db->quote(mb_substr((string) Arr::get($res, 'summary', ''), 0, 2000)).',
                usage = '.(isset($res['usage']) ? $db->quote(json_encode($res['usage'], JSON_UNESCAPED_UNICODE)).'::jsonb' : 'usage').',
                updated_at = now(), finished_at = now()
             WHERE id = '.(int) $package_id);

        // Документы, оставшиеся «в работе» (воркер упал или не доложил статус),
        // не должны висеть вечно. Если файлы собраны — документ фактически готов
        // (статус-вызов просто не дошёл, напр. из-за рестарта сайта при деплое);
        // без файлов — честная ошибка с внятной причиной.
        $db->query(Database::UPDATE,
            "UPDATE nj_580_documents d SET
                status = CASE WHEN EXISTS (SELECT 1 FROM nj_580_files f WHERE f.document_id = d.id)
                              THEN 'done' ELSE 'error' END,
                error = CASE WHEN EXISTS (SELECT 1 FROM nj_580_files f WHERE f.document_id = d.id)
                              THEN NULL
                              ELSE COALESCE(NULLIF(d.error, ''),
                                   'Воркер завершил пакет, не сообщив статус этого документа — пересоберите пакет') END,
                finished_at = now()
             WHERE d.package_id = ".(int) $package_id." AND d.status IN ('new', 'writing')");

        self::_usage_rows($db, (int) $package_id, (int) $job_id, Arr::get($res, 'usage', []));
    }

    /** Затраты LLM построчно (http-режим: строки приходят в job.result). */
    protected static function _usage_rows($db, $package_id, $job_id, $usage)
    {
        foreach ((array) $usage as $u)
        {
            if ( ! is_array($u))
            {
                continue;
            }

            $db->query(Database::SELECT,
                'INSERT INTO nj_580_usage (package_id, document_id, job_id, phase, model, prompt_tokens, completion_tokens, duration_ms)
                 VALUES ('.(int) $package_id.', '.(int) Arr::get($u, 'document_id', 0).', '.(int) $job_id.',
                         '.$db->quote((string) Arr::get($u, 'phase', '')).',
                         '.$db->quote(mb_substr((string) Arr::get($u, 'model', ''), 0, 120)).',
                         '.(int) Arr::get($u, 'prompt_tokens', 0).', '.(int) Arr::get($u, 'completion_tokens', 0).',
                         '.(int) Arr::get($u, 'duration_ms', 0).') RETURNING id', FALSE)->current();
        }
    }

    // ------------------------------------------------------------------
    // Чтение
    // ------------------------------------------------------------------

    public static function by_code($code)
    {
        self::ensure_tables();
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_580_packages WHERE code = '.self::_db()->quote((string) $code), FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function by_id($id)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_580_packages WHERE id = '.(int) $id, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Документы пакета (+файлы каждого). */
    public static function documents($package_id, $reconcile = FALSE)
    {
        if ($reconcile)
        {
            self::reconcile(self::by_id((int) $package_id));
        }

        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, doc_key, ord, title, status, error, content, created_at, finished_at
             FROM nj_580_documents WHERE package_id = '.(int) $package_id.'
             ORDER BY ord, id', FALSE)->as_array();

        $out = [];

        foreach ($rows as $r)
        {
            $r = (array) $r;
            $r['content'] = $r['content'] ? json_decode((string) $r['content'], TRUE) : NULL;
            $r['files']   = self::_db()->query(Database::SELECT,
                'SELECT id, kind, size FROM nj_580_files WHERE document_id = '.(int) $r['id'].' ORDER BY id',
                FALSE)->as_array();
            $out[] = $r;
        }

        return $out;
    }

    /** Файлы уровня пакета (zip-архив и пр.). */
    public static function package_files($package_id)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT id, kind, size FROM nj_580_files WHERE package_id = '.(int) $package_id.'
             AND document_id IS NULL ORDER BY id', FALSE)->as_array();
    }

    public static function file($id)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT f.*, f.package_id FROM nj_580_files f WHERE f.id = '.(int) $id, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function file_path($rel)
    {
        $rel = str_replace(['..', "\0"], '', (string) $rel);

        return self::storage_dir().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    /**
     * Проверка ОГРН (13 зн.), ОГРНИП (15 зн.) или ИНН (10/12 зн.) по
     * контрольной цифре. Пустая строка — «не указано» (TRUE).
     */
    public static function ogrn_valid($v)
    {
        $v = preg_replace('/\D/', '', (string) $v);
        $n = strlen($v);

        if ($n === 13)
        {
            return ((int) substr($v, 0, 12) % 11) % 10 === (int) $v[12];
        }

        if ($n === 15)
        {
            return ((int) substr($v, 0, 14) % 13) % 10 === (int) $v[14];
        }

        if ($n === 10)
        {
            $k = [2, 4, 10, 3, 5, 9, 4, 6, 8];
            $sum = 0;

            for ($i = 0; $i < 9; $i++)
            {
                $sum += (int) $v[$i] * $k[$i];
            }

            return ($sum % 11) % 10 === (int) $v[9];
        }

        if ($n === 12)
        {
            $k1 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
            $k2 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
            $s1 = 0;
            $s2 = 0;

            for ($i = 0; $i < 10; $i++)
            {
                $s1 += (int) $v[$i] * $k1[$i];
            }

            for ($i = 0; $i < 11; $i++)
            {
                $s2 += (int) $v[$i] * $k2[$i];
            }

            return (($s1 % 11) % 10 === (int) $v[10]) AND (($s2 % 11) % 10 === (int) $v[11]);
        }

        return FALSE;
    }

    /** Организация реестра по ОГРН (или NULL). */
    public static function org_by_ogrn($ogrn)
    {
        self::ensure_tables();
        $ogrn = preg_replace('/\D/', '', (string) $ogrn);

        if ($ogrn === '')
        {
            return NULL;
        }

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_580_organizations WHERE ogrn = '.self::_db()->quote($ogrn).'
             LIMIT 1', FALSE)->current();

        if ($row)
        {
            return (array) $row;
        }

        // общего справочника организаций (nj594): карточки, полученные другими
        // модулями/посевом, обогащают локальный кэш (форма полей совпадает)
        if (class_exists('Service_Nj594_Orgs'))
        {
            try
            {
                $r = Service_Nj594_Orgs::by_ogrn($ogrn);

                if ($r AND trim((string) $r['full_name']) !== '')
                {
                    self::upsert_org($ogrn, [
                        'inn'       => (string) $r['inn'],
                        'full_name' => (string) $r['full_name'],
                        'address'   => (string) $r['address'],
                        'okved'     => (string) $r['okved'],
                        'director'  => (string) $r['director'],
                    ], 'nj594');

                    $row = self::_db()->query(Database::SELECT,
                        'SELECT * FROM nj_580_organizations WHERE ogrn = '.self::_db()->quote($ogrn).'
                         LIMIT 1', FALSE)->current();

                    if ($row)
                    {
                        return (array) $row;
                    }
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj580: nj594 fallback: :e', [':e' => $e->getMessage()]);
            }
        }

        return NULL;
    }

    /** Создать/обновить карточку организации (кэш официальных данных). */
    public static function upsert_org($ogrn, array $f, $source = '')
    {
        self::ensure_tables();
        $ogrn = preg_replace('/\D/', '', (string) $ogrn);
        $db   = self::_db();

        if ( ! self::ogrn_valid($ogrn))
        {
            return 0;
        }

        $g = function ($k) use ($f, $db) {
            return $db->quote(mb_substr(trim((string) ($f[$k] ?? '')), 0, 500));
        };

        $row = $db->query(Database::SELECT,
            <<<SQL
INSERT INTO nj_580_organizations (ogrn, inn, full_name, address, okved, director, source)
VALUES ({$db->quote($ogrn)}, {$g('inn')}, {$g('full_name')},
        {$g('address')}, {$g('okved')}, {$g('director')}, {$db->quote(mb_substr((string) $source, 0, 40))})
ON CONFLICT (ogrn) DO UPDATE SET
    inn = COALESCE(NULLIF(EXCLUDED.inn, ''), nj_580_organizations.inn),
    full_name = COALESCE(NULLIF(EXCLUDED.full_name, ''), nj_580_organizations.full_name),
    address = COALESCE(NULLIF(EXCLUDED.address, ''), nj_580_organizations.address),
    okved = COALESCE(NULLIF(EXCLUDED.okved, ''), nj_580_organizations.okved),
    director = COALESCE(NULLIF(EXCLUDED.director, ''), nj_580_organizations.director),
    updated_at = now()
RETURNING id
SQL
            , FALSE)->current();

        return $row ? (int) $row['id'] : 0;
    }

    /** Уточнение от участника (итерация пакета). */
    public static function add_feedback($package_id, $user_id, $text)
    {
        self::ensure_tables();
        $text = trim((string) $text);

        if ($text === '')
        {
            return ['error' => 'Пустое уточнение'];
        }

        self::_db()->query(Database::SELECT,
            'INSERT INTO nj_580_feedback (package_id, user_id, text)
             VALUES ('.(int) $package_id.', '.($user_id ? (int) $user_id : 'NULL').', '
                     .self::_db()->quote(mb_substr($text, 0, 2000)).') RETURNING id', FALSE)->current();

        return ['ok' => TRUE];
    }

    /** Уточнения пакета (новые сверху, неотмеченные воркером — первыми). */
    public static function list_feedback($package_id, $limit = 50)
    {
        self::ensure_tables();
        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_580_feedback WHERE package_id = '.(int) $package_id.'
             ORDER BY (used_at IS NOT NULL), id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Тексты неотмеченных уточнений — в промпт воркера при пересборке. */
    public static function feedback_for_worker($package_id)
    {
        $out = [];

        foreach (self::list_feedback((int) $package_id, 20) as $f)
        {
            if (empty($f['used_at']))
            {
                $out[] = mb_substr((string) $f['text'], 0, 500);
            }
        }

        return $out;
    }

    /** Пометить уточнения использованными (после постановки задачи). */
    public static function feedback_mark_used($package_id)
    {
        self::ensure_tables();
        self::_db()->query(Database::UPDATE,
            'UPDATE nj_580_feedback SET used_at = now()
             WHERE package_id = '.(int) $package_id.' AND used_at IS NULL');
    }

    /** Мои пакеты. */
    public static function list_user($user_id, $limit = 30)
    {
        self::ensure_tables();
        return self::_db()->query(Database::SELECT,
            'SELECT p.*,
                (SELECT COUNT(*) FROM nj_580_documents d WHERE d.package_id = p.id) AS docs,
                (SELECT COUNT(*) FROM nj_580_files f WHERE f.package_id = p.id) AS files
             FROM nj_580_packages p WHERE p.user_id = '.(int) $user_id.'
                 AND p.deleted_at IS NULL
             ORDER BY p.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /**
     * Промежуточный статус сборки от воркера («Документ 2 из 6: …») —
     * виден в баннере страницы пакета без перезагрузки.
     */
    /**
     * Промежуточное обновление пакета воркером: текст этапа (+ реквизиты,
     * выведенные из сырых данных). Неизвестные поля игнорируются.
     */
    public static function apply_worker_update($package_id, array $fields, $progress_text = '')
    {
        self::ensure_tables();

        $sets = [];

        if ((string) $progress_text !== '')
        {
            $sets[] = 'progress = '.self::_db()->quote(mb_substr((string) $progress_text, 0, 300));
        }

        $org_name = trim((string) ($fields['org_name'] ?? ''));

        if ($org_name !== '')
        {
            $sets[] = 'org_name = '.self::_db()->quote(mb_substr($org_name, 0, 300));
            $sets[] = 'title = '.self::_db()->quote(mb_substr($org_name, 0, 200));
        }

        $info_fields = ['industry', 'size', 'structure', 'needs',
                        'inn', 'kpp', 'director', 'address', 'okved', 'reg_date'];
        $info_update = [];

        foreach ($info_fields as $f)
        {
            $v = trim((string) ($fields[$f] ?? ''));

            if ($v !== '')
            {
                $info_update[$f] = mb_substr($v, 0, 2000);
            }
        }

        if ($info_update)
        {
            $pkg = self::by_id((int) $package_id);
            $info = $pkg ? (json_decode((string) $pkg['org_info'], TRUE) ?: []) : [];
            $info = array_merge($info, $info_update);
            $sets[] = 'org_info = '.self::_db()->quote(json_encode($info, JSON_UNESCAPED_UNICODE)).'::jsonb';
        }

        // ОГРН от воркера → карточка в реестре организаций + ссылка на пакет
        $ogrn = preg_replace('/\D/', '', (string) ($fields['ogrn'] ?? ''));

        if ($ogrn !== '' AND self::ogrn_valid($ogrn))
        {
            self::upsert_org($ogrn, array_merge($info_update, [
                'full_name' => $org_name,
            ]), 'worker');
            $org = self::org_by_ogrn($ogrn);

            if ($org)
            {
                $sets[] = 'ogrn = '.self::_db()->quote($ogrn);
                $sets[] = 'org_id = '.(int) $org['id'];
            }
        }

        if ( ! $sets)
        {
            return;
        }

        self::_db()->query(Database::UPDATE,
            'UPDATE nj_580_packages SET '.implode(', ', $sets).', updated_at = now()
             WHERE id = '.(int) $package_id);
    }

    public static function set_progress($package_id, $text)
    {
        self::ensure_tables();
        $db = self::_db();
        $db->query(Database::UPDATE,
            'UPDATE nj_580_packages SET progress = '.$db->quote(mb_substr((string) $text, 0, 300)).',
                updated_at = now()
             WHERE id = '.(int) $package_id);
    }

    // ------------------------------------------------------------------
    // Запись со стороны воркера (вызывается через API с токеном)
    // ------------------------------------------------------------------

    /** Создать/обновить документ пакета (по package_id + doc_key). */
    public static function upsert_document($package_id, $doc_key, $ord, $title, $status, $error, $content_json)
    {
        self::ensure_tables();
        $db      = self::_db();
        $pkg     = self::by_id((int) $package_id);
        $doc_key = trim((string) $doc_key);
        $status  = in_array((string) $status, ['new', 'writing', 'done', 'error'], TRUE) ? (string) $status : 'new';

        if ($pkg === NULL)
        {
            return ['error' => 'Пакет не найден'];
        }

        $existing = $db->query(Database::SELECT,
            'SELECT id FROM nj_580_documents WHERE package_id = '.(int) $package_id.'
             AND doc_key = '.$db->quote($doc_key).' ORDER BY id LIMIT 1', FALSE)->current();

        if ($existing)
        {
            // Скобки обязательны: OR слабее тернарника, поэтому без них в SQL
            // уезжало «finished_at = 1» (bool) — UPDATE падал с ошибкой типа, и
            // сохранение существующего документа не работало вовсе.
            $finished_at = ($status === 'done' OR $status === 'error') ? 'now()' : 'finished_at';

            $db->query(Database::UPDATE,
                'UPDATE nj_580_documents SET
                    ord = '.(int) $ord.', title = '.$db->quote(mb_substr((string) $title, 0, 300)).',
                    status = '.$db->quote($status).',
                    error = '.($error !== '' ? $db->quote(mb_substr((string) $error, 0, 900)) : 'NULL').',
                    content = '.($content_json !== '' ? $db->quote($content_json).'::jsonb' : 'content').',
                    finished_at = '.$finished_at.';
                 WHERE id = '.(int) $existing['id']);

            return ['id' => (int) $existing['id']];
        }

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_580_documents (package_id, doc_key, ord, title, status, error, content)
             VALUES ('.(int) $package_id.', '.$db->quote(mb_substr($doc_key, 0, 64)).', '.(int) $ord.',
                     '.$db->quote(mb_substr((string) $title, 0, 300)).', '.$db->quote($status).',
                     '.($error !== '' ? $db->quote(mb_substr((string) $error, 0, 900)) : 'NULL').',
                     '.($content_json !== '' ? $db->quote($content_json).'::jsonb' : 'NULL').')
             RETURNING id', FALSE)->current();

        return $row ? ['id' => (int) $row['id']] : ['error' => 'Не удалось создать документ'];
    }

    public static function store_file($package_id, $document_id, $kind, $bytes)
    {
        self::ensure_tables();
        $max = (int) self::_cfg('max_file_bytes', 62914560);

        if ($bytes === '' OR strlen($bytes) > $max)
        {
            return ['error' => 'Пустой или слишком большой файл'];
        }

        $kind = strtolower((string) $kind);

        if ( ! in_array($kind, ['docx', 'pdf', 'xlsx', 'zip'], TRUE))
        {
            return ['error' => 'Недопустимый тип файла'];
        }

        if (self::by_id((int) $package_id) === NULL)
        {
            return ['error' => 'Пакет не найден'];
        }

        $dir = self::storage_dir().DIRECTORY_SEPARATOR.date('Ym');

        if ( ! is_dir($dir))
        {
            @mkdir($dir, 0775, TRUE);
        }

        $name = bin2hex(random_bytes(10)).'.'.$kind;
        file_put_contents($dir.DIRECTORY_SEPARATOR.$name, $bytes);
        @chmod($dir.DIRECTORY_SEPARATOR.$name, 0664);

        $rel = date('Ym').'/'.$name;
        $db  = self::_db();

        // пакетный файл (реестр/ZIP) — один актуальный: предыдущий того же
        // формата уходит (строка + файл), чтобы не копить дубли и битые ссылки
        if ($document_id <= 0)
        {
            foreach ($db->query(Database::SELECT,
                'SELECT path FROM nj_580_files WHERE package_id = '.(int) $package_id.'
                 AND document_id IS NULL AND kind = '.$db->quote($kind), FALSE)->as_array() as $old)
            {
                @unlink(self::file_path((string) $old['path']));
            }

            $db->query(Database::DELETE,
                'DELETE FROM nj_580_files WHERE package_id = '.(int) $package_id.'
                 AND document_id IS NULL AND kind = '.$db->quote($kind));
        }

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_580_files (package_id, document_id, kind, path, size)
             VALUES ('.(int) $package_id.', '.($document_id > 0 ? (int) $document_id : 'NULL').', '.$db->quote($kind).',
                     '.$db->quote($rel).', '.strlen($bytes).')
             RETURNING id', FALSE)->current();

        return $row ? ['id' => (int) $row['id']] : ['error' => 'Не удалось сохранить файл'];
    }

    // ------------------------------------------------------------------
    // Управление / MCP / затраты
    // ------------------------------------------------------------------

    public static function admin_list($limit = 50)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT p.*, (SELECT COUNT(*) FROM nj_580_documents d WHERE d.package_id = p.id) AS docs
             FROM nj_580_packages p ORDER BY p.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    public static function usage_summary()
    {
        $r = self::_db()->query(Database::SELECT,
            'SELECT COUNT(*) AS calls,
                    COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,
                    COALESCE(SUM(completion_tokens), 0) AS completion_tokens
             FROM nj_580_usage', FALSE)->current();

        return $r ? (array) $r : [];
    }

    public static function usage_recent($limit = 30)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT phase, model, prompt_tokens, completion_tokens, duration_ms, created_at
             FROM nj_580_usage ORDER BY id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** MCP: сводка по пакету (без содержимого документов). */
    public static function mcp_pkg(array $args)
    {
        $pkg = self::by_code((string) Arr::get($args, 'code', ''));

        if ($pkg === NULL)
        {
            return ['error' => 'not_found'];
        }

        $docs = self::documents((int) $pkg['id']);

        return [
            'code'     => $pkg['code'],
            'org_name' => $pkg['org_name'],
            'status'   => $pkg['status'],
            'docs'     => array_map(function ($d) {
                return [
                    'key'    => $d['doc_key'],
                    'title'  => $d['title'],
                    'status' => $d['status'],
                    'files'  => array_map(function ($f) { return $f['kind']; }, (array) $d['files']),
                ];
            }, $docs),
        ];
    }
}
