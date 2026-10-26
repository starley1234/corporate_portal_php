<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj643_cad: «CAD-детали» — 3D-модель по описанию.
 *
 * Идея сервиса: пользователь пишет словами, какую деталь ему нужно, LLM пишет
 * ПАРАМЕТРИЧЕСКИЙ код (build123d), а внешний headless-движок
 * (POST <NJ643_CAD_API>/v1/cad/verify-and-build) проверяет код, гоняет
 * граничный стресс-тест параметров и собирает STEP/STL/GLB/ракурсы.
 * Сайт хранит модель, параметры, файлы и историю ревизий — «далее улучшаем
 * функционал», поэтому модель данных сразу рассчитана на ревизии и параметры.
 *
 * Роли таблиц:
 *   nj_643_parts      — деталь (владелец, код-ссылка, текущий код/параметры, статус);
 *   nj_643_revisions  — ревизии: «сгенерировать / поправить / пересобрать по параметрам»;
 *   nj_643_files      — файлы ревизии (step/stl/glb/ракурсы);
 *   nj_643_runs       — журнал обращений к движку (для диагностики);
 *   nj_643_usage      — расход LLM по фазам (модель, токены, время).
 *
 * Движок живёт ВНЕ сайта и требует API-ключ: он есть только у воркера
 * (NJ643_CAD_API_KEY или общий TOOLFORGE_API_KEY), поэтому все обращения к
 * движку идут через канал очереди nj643.cad — сайт сам движок не дёргает.
 * Наружу это выглядит честно: страница показывает живой статус-ряд, а при
 * незаданном ключе — понятную причину и что именно поправить в workers/.env.
 */
class Service_Nj643_Cad {

    /** Канал очереди: единственная точка входа воркера этого модуля. */
    const CHANNEL = 'nj643.cad';

    const P_QUEUED    = 'queued';
    const P_RUNNING   = 'running';
    const P_DONE      = 'done';
    const P_FAILED    = 'failed';
    const P_CANCELLED = 'cancelled';

    const ACT_GENERATE = 'generate';
    const ACT_REVISE   = 'revise';
    const ACT_REBUILD  = 'rebuild';

    /** Что умеет отдавать движок (CadExecutionRequest.exports). */
    const EXPORTS = ['step', 'stl', 'glb', 'views'];

    /**
     * Потолок архива, байт. Архив собирается в памяти (файлы + ZIP + ответ), а у
     * PHP есть memory_limit: крупную деталь честнее отдать по файлам, чем уронить
     * страницу. 80 МБ — с запасом больше любой печатной детали.
     */
    const ARCHIVE_MAX_BYTES = 80000000;

    /** Расширение → вид файла (для приёма от воркера и раздачи). */
    public static $kind_by_ext = [
        'step' => 'step', 'stp' => 'step', 'stl' => 'stl', 'glb' => 'glb',
        'gltf' => 'glb', 'png' => 'png', 'svg' => 'svg', '3mf' => '3mf',
        'off'  => 'off',  'csg' => 'csg', 'txt' => 'txt', 'md' => 'txt',
        'py'   => 'txt',  'json' => 'json',
    ];

    public static $mime_by_kind = [
        'step' => 'application/step',
        'stl'  => 'model/stl',
        'glb'  => 'model/gltf-binary',
        'png'  => 'image/png',
        'svg'  => 'image/svg+xml',
        '3mf'  => 'model/3mf',
        'txt'  => 'text/plain; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'off'  => 'text/plain; charset=utf-8',
        'csg'  => 'text/plain; charset=utf-8',
    ];

    protected static $_tables_ensured = FALSE;

    // ------------------------------------------------------------------
    // Схема
    // ------------------------------------------------------------------

    /**
     * Идемпотентное создание таблиц: работает и без наката миграции (как в
     * nj642_vacancies) — сервис обязан подняться на проде одним git pull.
     */
    public static function ensure_tables()
    {
        if (self::$_tables_ensured)
        {
            return;
        }

        self::$_tables_ensured = TRUE;

        $stmts   = [];
        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_643_parts" (
    "id"                BIGSERIAL PRIMARY KEY,
    "code"              VARCHAR(16)  NOT NULL UNIQUE,
    "user_id"           BIGINT       NOT NULL DEFAULT 0,
    "title"             VARCHAR(200) NOT NULL DEFAULT '',
    "prompt"            TEXT         NOT NULL DEFAULT '',
    "status"            VARCHAR(12)  NOT NULL DEFAULT 'queued',
    "stage"             VARCHAR(200) NOT NULL DEFAULT '',
    "code_py"           TEXT         NULL,
    "schema_definition" JSONB        NULL,
    "params"            JSONB        NULL,
    "report"            JSONB        NULL,
    "error"             TEXT         NULL,
    "job_id"            BIGINT       NOT NULL DEFAULT 0,
    "revision"          INTEGER      NOT NULL DEFAULT 1,
    "exports"           JSONB        NULL,
    "deflection"        NUMERIC(6,4) NOT NULL DEFAULT 0.025,
    "stress"            BOOLEAN      NOT NULL DEFAULT TRUE,
    "files_count"       INTEGER      NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at"       TIMESTAMPTZ  NULL,
    "deleted_at"        TIMESTAMPTZ  NULL
)
SQL;

        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_643_revisions" (
    "id"                BIGSERIAL PRIMARY KEY,
    "part_id"           BIGINT       NOT NULL,
    "rev"               INTEGER      NOT NULL DEFAULT 1,
    "action"            VARCHAR(16)  NOT NULL DEFAULT 'generate',
    "instruction"       TEXT         NULL,
    "code_py"           TEXT         NULL,
    "schema_definition" JSONB        NULL,
    "params"            JSONB        NULL,
    "status"            VARCHAR(12)  NOT NULL DEFAULT 'queued',
    "report"            JSONB        NULL,
    "error"             TEXT         NULL,
    "attempts"          INTEGER      NOT NULL DEFAULT 0,
    "job_id"            BIGINT       NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at"       TIMESTAMPTZ  NULL
)
SQL;

        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_643_files" (
    "id"          BIGSERIAL PRIMARY KEY,
    "part_id"     BIGINT       NOT NULL,
    "revision_id" BIGINT       NOT NULL DEFAULT 0,
    "kind"        VARCHAR(16)  NOT NULL DEFAULT 'other',
    "view"        VARCHAR(40)  NOT NULL DEFAULT '',
    "name"        VARCHAR(200) NOT NULL DEFAULT '',
    "path"        VARCHAR(300) NOT NULL DEFAULT '',
    "size"        BIGINT       NOT NULL DEFAULT 0,
    "meta"        JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL;

        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_643_runs" (
    "id"          BIGSERIAL PRIMARY KEY,
    "part_id"     BIGINT       NOT NULL DEFAULT 0,
    "revision_id" BIGINT       NOT NULL DEFAULT 0,
    "kind"        VARCHAR(20)  NOT NULL DEFAULT 'build',
    "ok"          BOOLEAN      NOT NULL DEFAULT FALSE,
    "http_status" INTEGER      NOT NULL DEFAULT 0,
    "duration_ms" INTEGER      NOT NULL DEFAULT 0,
    "engine"      JSONB        NULL,
    "error"       VARCHAR(500) NOT NULL DEFAULT '',
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL;

        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_643_usage" (
    "id"                BIGSERIAL PRIMARY KEY,
    "part_id"           BIGINT       NOT NULL DEFAULT 0,
    "revision_id"       BIGINT       NOT NULL DEFAULT 0,
    "job_id"            BIGINT       NOT NULL DEFAULT 0,
    "phase"             VARCHAR(60)  NOT NULL DEFAULT '',
    "model"             VARCHAR(160) NOT NULL DEFAULT '',
    "prompt_tokens"     INTEGER      NOT NULL DEFAULT 0,
    "completion_tokens" INTEGER      NOT NULL DEFAULT 0,
    "duration_ms"       INTEGER      NOT NULL DEFAULT 0,
    "note"              TEXT         NOT NULL DEFAULT '',
    "created_at"        TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL;

        $stmts[] = 'CREATE UNIQUE INDEX IF NOT EXISTS "nj_643_revisions_uniq" ON "nj_643_revisions" ("part_id", "rev")';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_643_parts_user_idx" ON "nj_643_parts" ("user_id", "id" DESC)';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_643_parts_status_idx" ON "nj_643_parts" ("status", "id" DESC)';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_643_files_part_idx" ON "nj_643_files" ("part_id", "kind", "id")';
        // Файлы всегда выбираются «по ревизии»: индекс ускоряет и страницу детали,
        // и разовую привязку бесхозных файлов (revision_id = 0).
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_643_files_rev_idx" ON "nj_643_files" ("part_id", "revision_id")';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_643_runs_idx" ON "nj_643_runs" ("kind", "id" DESC)';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_643_usage_part_idx" ON "nj_643_usage" ("part_id", "id" DESC)';

        // Колонка появилась 27.09.2026: владелец спросил, почему на короткий код
        // уходит 4598 токенов, — в note воркер пишет разбор (знаки ответа, где
        // начинается JSON, сколько списано на рассуждения модели).
        $stmts[] = 'ALTER TABLE "nj_643_usage" ADD COLUMN IF NOT EXISTS "note" TEXT NOT NULL DEFAULT \'\'';

        // Таблица следов удаления. Владелец (28.09.2026): «ещё надо чтобы детали
        // не удалялись просто так». Деталь удаляется мягко (deleted_at), но файлы
        // при этом стираются с диска — единственная память о ней и есть эта
        // строка: кто, что и когда убрал.
        $stmts[] = 'CREATE TABLE IF NOT EXISTS "nj_643_audit" ('
            .'"id" BIGSERIAL PRIMARY KEY, '
            .'"part_id" BIGINT NOT NULL DEFAULT 0, '
            .'"code" VARCHAR(16) NOT NULL DEFAULT \'\', '
            .'"user_id" BIGINT NOT NULL DEFAULT 0, '
            .'"action" VARCHAR(30) NOT NULL DEFAULT \'\', '
            .'"title" VARCHAR(200) NOT NULL DEFAULT \'\', '
            .'"detail" TEXT NOT NULL DEFAULT \'\', '
            .'"created_at" TIMESTAMPTZ NOT NULL DEFAULT now())';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_643_audit_idx" ON "nj_643_audit" ("id" DESC)';

        foreach ($stmts as $sql)
        {
            try
            {
                DB::query(NULL, $sql)->execute();
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj643: схема CAD-деталей: :e', [':e' => $e->getMessage()]);
            }
        }

        self::_adopt_orphan_files();
    }

    /**
     * Разовые «бесхозные» файлы (revision_id = 0) привязываем к последней ревизии.
     *
     * Откуда они: ранняя версия воркера грузила файлы без revision_id, а колонка
     * объявлена NOT NULL DEFAULT 0 — файлы попадали в «нулевую» ревизию. Дальше
     * страница детали показывала их как файлы любой ревизии: владелец открыл
     * /cad/cade8a2f512 и увидел в «Файлы · ревизия 2» файлы всех сборок
     * (27.09.2026). Привязка идёт к последней ревизии детали — так эти файлы и
     * создавались; сама операция идемпотентна и ничего не удаляет.
     */
    protected static function _adopt_orphan_files()
    {
        self::_db()->query(Database::UPDATE,
            'UPDATE nj_643_files f SET revision_id = ('
            .'  SELECT r.id FROM nj_643_revisions r WHERE r.part_id = f.part_id ORDER BY r.rev DESC LIMIT 1) '
            .'WHERE f.revision_id = 0 AND EXISTS ('
            .'  SELECT 1 FROM nj_643_revisions r WHERE r.part_id = f.part_id)', FALSE);
    }

    // ------------------------------------------------------------------
    // Создание и запуск
    // ------------------------------------------------------------------

    /**
     * Новая деталь по описанию: запись + первая ревизия + задача воркеру.
     *
     * @param  int   $user_id
     * @param  array $in prompt, title?, exports[], deflection?, stress?
     * @return array ['ok' => TRUE, 'code' => 'cad…', 'revision' => 1] | ['error' => …]
     */
    public static function create_part($user_id, array $in)
    {
        self::ensure_tables();

        $user_id = (int) $user_id;

        if ($user_id <= 0)
        {
            return ['error' => 'Сервис для зарегистрированных — войдите'];
        }

        $prompt = trim((string) ($in['prompt'] ?? ''));

        if (mb_strlen($prompt) < 10)
        {
            return ['error' => 'Опишите деталь подробнее: что это, для чего и какие размеры важны'];
        }

        $max = (int) self::_cfg('max_prompt_chars', 4000);

        if (mb_strlen($prompt) > $max)
        {
            return ['error' => 'Описание длинновато: уложитесь в '.$max.' знаков'];
        }

        $run = self::run_options($in);

        $db   = self::_db();
        $code = 'cad'.bin2hex(random_bytes(4));

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_643_parts (code, user_id, title, prompt, status, stage, exports, deflection, stress)
             VALUES ('.$db->quote($code).', '.$user_id.', '
            .$db->quote(mb_substr(self::guess_title($prompt), 0, 200)).', '
            .$db->quote($prompt).', '.$db->quote(self::P_QUEUED).', '.$db->quote('В очереди — берём модель и код').', '
            .$db->quote(json_encode($run['exports'], JSON_UNESCAPED_UNICODE)).'::jsonb, '
            .$db->quote($run['deflection']).', '.($run['stress'] ? 'TRUE' : 'FALSE').')
             RETURNING id', FALSE)->current();

        if (empty($row))
        {
            return ['error' => 'Не получилось создать деталь — попробуйте ещё раз'];
        }

        $part_id = (int) $row['id'];

        $rev_id = self::_add_revision($part_id, 1, self::ACT_GENERATE, $prompt, $user_id);
        $job_id = self::_enqueue($part_id, $rev_id, self::ACT_GENERATE);

        if ($job_id <= 0)
        {
            self::fail_part($part_id, $rev_id,
                'Задача не встала в очередь — воркер недоступен. Проверьте, что воркер запущен (канал '.self::CHANNEL.')');

            return ['error' => 'Воркер недоступен: задача не встала в очередь'];
        }

        return ['ok' => TRUE, 'code' => $code, 'revision' => 1, 'job_id' => $job_id];
    }

    /**
     * Правка словами: «сделай отверстие 6 мм», «добавь рёбра жёсткости».
     * Новая ревизия берёт текущий код и просит LLM его изменить.
     */
    public static function revise_part($code, $instruction, $user_id, $is_admin = FALSE)
    {
        self::ensure_tables();

        $p = self::by_code($code);

        if ($p === NULL)
        {
            return ['error' => 'Деталь не найдена'];
        }

        if ( ! self::can_edit($p, $user_id, $is_admin))
        {
            return ['error' => 'Это не ваша деталь'];
        }

        $instruction = trim((string) $instruction);

        if (mb_strlen($instruction) < 3)
        {
            return ['error' => 'Напишите, что поправить: например «отверстие 6 мм и фаска 1 мм»'];
        }

        if (trim((string) $p['code_py']) === '' AND (string) $p['status'] !== self::P_RUNNING)
        {
            // Кода ещё нет (первый прогон не удался) — считаем это новым описанием
            return ['error' => 'Модель ещё не собрана — сначала дождитесь первого прогона или запустите его заново'];
        }

        // Незавершённый прогон не трогаем: две задачи на одну деталь — гонка
        $busy = self::active_job_id((int) $p['id']);

        if ($busy > 0)
        {
            return ['error' => 'Сборка уже идёт — дождитесь её окончания или остановите'];
        }

        $rev = (int) $p['revision'] + 1;

        $rev_id = self::_add_revision((int) $p['id'], $rev, self::ACT_REVISE, $instruction, $user_id);
        $job_id = self::_enqueue((int) $p['id'], $rev_id, self::ACT_REVISE);

        if ($job_id <= 0)
        {
            self::fail_revision($rev_id, 'Задача не встала в очередь — воркер недоступен');

            return ['error' => 'Воркер недоступен: задача не встала в очередь'];
        }

        self::_db()->query(Database::UPDATE,
            'UPDATE nj_643_parts SET revision = '.$rev.', status = '.self::_db()->quote(self::P_QUEUED).', '
            .'stage = '.self::_db()->quote('Правлю модель по вашей просьбе').', error = NULL, updated_at = now() '
            .'WHERE id = '.(int) $p['id'], FALSE);

        return ['ok' => TRUE, 'revision' => $rev, 'job_id' => $job_id];
    }

    /**
     * Пересборка: те же код и параметры (или новые значения параметров) —
     * «покрутить размеры», не переписывая модель заново.
     *
     * @param array $params новые значения параметров (пусто = как в детали)
     */
    public static function rebuild_part($code, array $params, $user_id, $is_admin = FALSE)
    {
        self::ensure_tables();

        $p = self::by_code($code);

        if ($p === NULL)
        {
            return ['error' => 'Деталь не найдена'];
        }

        if ( ! self::can_edit($p, $user_id, $is_admin))
        {
            return ['error' => 'Это не ваша деталь'];
        }

        if (trim((string) $p['code_py']) === '')
        {
            return ['error' => 'Модель ещё не собрана — запустите сборку'];
        }

        $busy = self::active_job_id((int) $p['id']);

        if ($busy > 0)
        {
            return ['error' => 'Сборка уже идёт — дождитесь её окончания или остановите'];
        }

        $clean = self::clean_params($params, self::schema_of($p));
        $rev   = (int) $p['revision'] + 1;

        $db  = self::_db();
        $rev_id = $db->query(Database::SELECT,
            'INSERT INTO nj_643_revisions (part_id, rev, action, instruction, code_py, schema_definition, params, status)
             VALUES ('.(int) $p['id'].', '.$rev.', '.$db->quote(self::ACT_REBUILD).', '
            .$db->quote('Пересборка по параметрам').', '.$db->quote((string) $p['code_py']).', '
            .($p['schema_definition'] ? $db->quote((string) $p['schema_definition']).'::jsonb' : 'NULL').', '
            .$db->quote(json_encode($clean, JSON_UNESCAPED_UNICODE)).'::jsonb, '
            .$db->quote(self::P_QUEUED).')
             RETURNING id', FALSE)->current();

        $rev_id = $rev_id ? (int) $rev_id['id'] : 0;

        if ($rev_id <= 0)
        {
            return ['error' => 'Не получилось создать ревизию'];
        }

        // Параметры фиксируем сразу: страница показывает то, что заказали
        $db->query(Database::UPDATE,
            'UPDATE nj_643_parts SET revision = '.$rev.', params = '
            .$db->quote(json_encode($clean, JSON_UNESCAPED_UNICODE)).'::jsonb, '
            .'status = '.$db->quote(self::P_QUEUED).', stage = '.$db->quote('Пересобираю по параметрам').', '
            .'error = NULL, updated_at = now() WHERE id = '.(int) $p['id'], FALSE);

        $job_id = self::_enqueue((int) $p['id'], $rev_id, self::ACT_REBUILD);

        if ($job_id <= 0)
        {
            self::fail_revision($rev_id, 'Задача не встала в очередь — воркер недоступен');

            return ['error' => 'Воркер недоступен: задача не встала в очередь'];
        }

        return ['ok' => TRUE, 'revision' => $rev, 'job_id' => $job_id];
    }

    /**
     * «Сделать по образцу»: новая деталь из того же кода модели и размеров.
     *
     * Владелец (27.09.2026): итерации «а если на 5 мм длиннее» не должны тратить
     * токены ИИ и портить историю рабочей детали. Копия собирается движком из
     * готового кода — модель не вызывается вовсе. Хозяин копии — тот, кто её
     * сделал (у управления так тоже: копия чужой детали становится его).
     *
     * @return array ['ok' => TRUE, 'code' => 'cad…', 'revision' => 1] | ['error' => …]
     */
    public static function copy_part($code, $user_id, $is_admin = FALSE)
    {
        self::ensure_tables();

        $p = self::by_code($code);

        if ($p === NULL)
        {
            return ['error' => 'Деталь не найдена'];
        }

        if ( ! self::can_edit($p, $user_id, $is_admin))
        {
            return ['error' => 'Это не ваша деталь'];
        }

        if (trim((string) $p['code_py']) === '')
        {
            return ['error' => 'Копировать пока нечего: у детали ещё нет кода модели — дождитесь сборки'];
        }

        $user_id = (int) $user_id;

        if ($user_id <= 0)
        {
            return ['error' => 'Копию можно сделать только под своей учётной записью'];
        }

        $db     = self::_db();
        $new    = 'cad'.bin2hex(random_bytes(4));
        $title  = mb_substr(trim((string) $p['title']).' (копия)', 0, 200);
        $schema = self::schema_of($p);
        $values = [];

        foreach (self::params_of($p) as $item)
        {
            $values[$item['name']] = $item['value'];
        }

        $exports = json_decode((string) $p['exports'], TRUE);
        $exports = (is_array($exports) AND $exports) ? array_values($exports) : self::EXPORTS;

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_643_parts (code, user_id, title, prompt, status, stage, code_py, schema_definition, params, exports, deflection, stress)'
            .' VALUES ('.$db->quote($new).', '.$user_id.', '.$db->quote($title).', '.$db->quote((string) $p['prompt']).', '
            .$db->quote(self::P_QUEUED).', '.$db->quote('Собираю копию — модель та же').', '
            .$db->quote((string) $p['code_py']).', '
            .($schema ? $db->quote(json_encode($schema, JSON_UNESCAPED_UNICODE)).'::jsonb' : 'NULL').', '
            .$db->quote(json_encode($values, JSON_UNESCAPED_UNICODE)).'::jsonb, '
            .$db->quote(json_encode($exports, JSON_UNESCAPED_UNICODE)).'::jsonb, '
            .(float) $p['deflection'].', '.($p['stress'] ? 'TRUE' : 'FALSE').')
             RETURNING id', FALSE)->current();

        if (empty($row))
        {
            return ['error' => 'Не получилось создать копию — попробуйте ещё раз'];
        }

        $part_id = (int) $row['id'];

        $rev = $db->query(Database::SELECT,
            'INSERT INTO nj_643_revisions (part_id, rev, action, instruction, code_py, schema_definition, params, status)'
            .' VALUES ('.$part_id.', 1, '.$db->quote(self::ACT_REBUILD).', '
            .$db->quote('Копия детали '.$code).', '.$db->quote((string) $p['code_py']).', '
            .($schema ? $db->quote(json_encode($schema, JSON_UNESCAPED_UNICODE)).'::jsonb' : 'NULL').', '
            .$db->quote(json_encode($values, JSON_UNESCAPED_UNICODE)).'::jsonb, '
            .$db->quote(self::P_QUEUED).')
             RETURNING id', FALSE)->current();

        $rev_id = $rev ? (int) $rev['id'] : 0;

        if ($rev_id <= 0)
        {
            self::fail_part($part_id, 0, 'Ревизия копии не завелась');

            return ['error' => 'Копия создана, но не поставилась в работу — откройте её и нажмите «Собрать заново»'];
        }

        $job_id = self::_enqueue($part_id, $rev_id, self::ACT_REBUILD);

        if ($job_id <= 0)
        {
            self::fail_part($part_id, $rev_id, 'Задача не встала в очередь — воркер недоступен');

            return ['error' => 'Копия создана, но воркер недоступен — откройте её и нажмите «Собрать заново»'];
        }

        return ['ok' => TRUE, 'code' => $new, 'revision' => 1, 'job_id' => $job_id];
    }

    /** «Собрать ещё раз»: тем же способом, что и последняя ревизия. */
    public static function run_again($code, $user_id, $is_admin = FALSE)
    {
        $p = self::by_code($code);

        if ($p === NULL)
        {
            return ['error' => 'Деталь не найдена'];
        }

        if ( ! self::can_edit($p, $user_id, $is_admin))
        {
            return ['error' => 'Это не ваша деталь'];
        }

        if (trim((string) $p['code_py']) === '')
        {
            // кода нет — значит первый прогон не удался: повторяем генерацию
            return self::retry_generate($p);
        }

        $params = self::params_of($p);

        return self::rebuild_part($code, $params, $user_id, $is_admin);
    }

    /** Повторная генерация по исходному описанию (когда кода ещё нет). */
    protected static function retry_generate(array $p)
    {
        $busy = self::active_job_id((int) $p['id']);

        if ($busy > 0)
        {
            return ['error' => 'Сборка уже идёт — дождитесь её окончания или остановите'];
        }

        $rev    = (int) $p['revision'] + 1;
        $rev_id = self::_add_revision((int) $p['id'], $rev, self::ACT_GENERATE, (string) $p['prompt'], (int) $p['user_id']);
        $job_id = self::_enqueue((int) $p['id'], $rev_id, self::ACT_GENERATE);

        if ($job_id <= 0)
        {
            self::fail_revision($rev_id, 'Задача не встала в очередь — воркер недоступен');

            return ['error' => 'Воркер недоступен: задача не встала в очередь'];
        }

        self::_db()->query(Database::UPDATE,
            'UPDATE nj_643_parts SET revision = '.$rev.', status = '.self::_db()->quote(self::P_QUEUED).', '
            .'stage = '.self::_db()->quote('Пробую собрать заново').', error = NULL, updated_at = now() '
            .'WHERE id = '.(int) $p['id'], FALSE);

        return ['ok' => TRUE, 'revision' => $rev, 'job_id' => $job_id];
    }

    /** Остановить текущий прогон детали. */
    public static function cancel_part($code, $user_id, $is_admin = FALSE)
    {
        $p = self::by_code($code);

        if ($p === NULL)
        {
            return ['error' => 'Деталь не найдена'];
        }

        if ( ! self::can_edit($p, $user_id, $is_admin))
        {
            return ['error' => 'Это не ваша деталь'];
        }

        $job_id = self::active_job_id((int) $p['id'], TRUE);

        if ($job_id <= 0)
        {
            return ['error' => 'Активной сборки нет — останавливать нечего'];
        }

        try
        {
            Service_Nj120_JobQueue::instance()->cancel($job_id, TRUE);
        }
        catch (Throwable $e)
        {
            return ['error' => 'Не удалось остановить сборку'];
        }

        self::_db()->query(Database::UPDATE,
            'UPDATE nj_643_parts SET status = '.self::_db()->quote(self::P_CANCELLED).', '
            .'stage = '.self::_db()->quote('Остановлено вручную').', finished_at = now(), updated_at = now() '
            .'WHERE id = '.(int) $p['id'], FALSE);

        return ['ok' => TRUE, 'job_id' => $job_id];
    }

    /** Мягкое удаление: деталь пропадает из списка, ссылка-код перестаёт открываться. */
    public static function delete_part($code, $user_id, $is_admin = FALSE)
    {
        $p = self::by_code($code);

        if ($p === NULL)
        {
            return ['error' => 'Деталь не найдена'];
        }

        if ( ! self::can_edit($p, $user_id, $is_admin))
        {
            return ['error' => 'Это не ваша деталь'];
        }

        $job_id = self::active_job_id((int) $p['id'], TRUE);

        if ($job_id > 0)
        {
            try
            {
                Service_Nj120_JobQueue::instance()->cancel($job_id, TRUE);
            }
            catch (Throwable $e)
            {
                // деталь всё равно удаляем
            }
        }

        self::_db()->query(Database::UPDATE,
            'UPDATE nj_643_parts SET deleted_at = now(), status = '.self::_db()->quote(self::P_CANCELLED).' '
            .'WHERE id = '.(int) $p['id'], FALSE);

        // Файлы убираем с диска (строки оставляем — история и расход целы)
        $files_gone = 0;

        foreach (self::files_of((int) $p['id']) as $f)
        {
            $path = self::file_path((string) $f['path']);

            if (is_file($path))
            {
                @unlink($path);
                $files_gone++;
            }
        }

        // След удаления: кто, что и когда убрал. Восстановить деталь нельзя
        // (файлы с диска стёрты), но видно, чья это была деталь и сколько в ней
        // было ревизий — это и есть «не просто так» из просьбы владельца.
        self::audit((int) $p['id'], (string) $p['code'], (int) $user_id, 'delete_part',
            (string) $p['title'],
            'ревизий: '.(int) $p['revision'].', файлов убрано с диска: '.$files_gone
            .($is_admin ? ', удалено из управления' : ''));

        return ['ok' => TRUE];
    }

    // ------------------------------------------------------------------
    // Приём результата от воркера (X-Worker-Token)
    // ------------------------------------------------------------------

    /**
     * POST /cad/api/upload — файл ревизии (step/stl/glb/ракурс).
     *
     * Файлы идут файлами на диск (public_html/storage/nj643): в базе только
     * метаданные. Воркер отдаёт base64 — json-тело, поэтому лимит задан явно.
     *
     * @param array $in part_id, revision_id, kind, name, b64, view?, meta?
     */
    public static function api_upload(array $in)
    {
        self::ensure_tables();

        $part_id = (int) ($in['part_id'] ?? 0);
        $rev_id  = (int) ($in['revision_id'] ?? 0);
        $kind    = strtolower(trim((string) ($in['kind'] ?? '')));
        $name    = trim((string) ($in['name'] ?? ''));
        $view    = mb_substr(trim((string) ($in['view'] ?? '')), 0, 40);
        $b64     = (string) ($in['b64'] ?? '');
        $meta    = (is_array($in['meta'] ?? NULL)) ? $in['meta'] : [];

        $p = self::part_by_id($part_id);

        if ($p === NULL)
        {
            return ['error' => 'Деталь не найдена', 'status' => 404];
        }

        // Вид файла: по имени/подсказке воркера, иначе — по kind
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if ($ext !== '' AND isset(self::$kind_by_ext[$ext]))
        {
            $kind = self::$kind_by_ext[$ext];
        }
        elseif ( ! in_array($kind, array_values(self::$kind_by_ext), TRUE))
        {
            $kind = 'other';
        }

        if ($b64 === '')
        {
            return ['error' => 'Пустой файл', 'status' => 422];
        }

        $bytes = base64_decode($b64, TRUE);

        if ($bytes === FALSE OR $bytes === '')
        {
            return ['error' => 'Файл не читается (ожидаю base64)', 'status' => 422];
        }

        $max = (int) self::_cfg('max_file_bytes', 62914560);

        if (strlen($bytes) > $max)
        {
            return ['error' => 'Файл больше '.round($max / 1048576).' МБ', 'status' => 413];
        }

        // Файл без ревизии больше не создаём: иначе он «прилипал» к нулевой
        // ревизии и показывался на странице любой (см. _adopt_orphan_files).
        if ($rev_id <= 0)
        {
            $rev_id = self::_last_rev_id($p);
        }

        $stored = self::store_file($p, $rev_id, $kind, $name, $bytes, $view, $meta);

        if (isset($stored['error']))
        {
            return $stored + ['status' => 500];
        }

        return ['ok' => TRUE, 'file_id' => (int) $stored['id'], 'kind' => $kind, 'size' => strlen($bytes)];
    }

    /**
     * POST /cad/api/result — итог прогона: код модели, параметры, отчёт движка.
     *
     * Одна точка приёма и для успеха, и для ошибки: воркер всегда говорит, чем
     * закончилось (владелец требует «понятный финал», а не молчание).
     *
     * @param array $in part_id, revision_id, ok, code, schema_definition, params,
     *                    report, error, engine, http_status, duration_ms, use_llm?, usage[]
     */
    public static function api_result(array $in)
    {
        self::ensure_tables();

        $part_id = (int) ($in['part_id'] ?? 0);
        $rev_id  = (int) ($in['revision_id'] ?? 0);
        $p       = self::part_by_id($part_id);

        if ($p === NULL)
        {
            return ['error' => 'Деталь не найдена', 'status' => 404];
        }

        $ok      = ! empty($in['ok']);
        $err     = trim((string) ($in['error'] ?? ''));
        $code    = (string) ($in['code'] ?? '');
        $schema  = (is_array($in['schema_definition'] ?? NULL)) ? $in['schema_definition'] : NULL;
        $params  = (is_array($in['params'] ?? NULL)) ? $in['params'] : NULL;
        $report  = (is_array($in['report'] ?? NULL)) ? $in['report'] : [];
        $attempts = (int) ($in['attempts'] ?? 0);

        // Журнал обращений к движку — даже когда движок ответил ошибкой
        if ( ! empty($in['engine']) OR ! empty($in['http_status']))
        {
            self::log_run($part_id, $rev_id, 'build', $ok,
                (int) ($in['http_status'] ?? 0), (int) ($in['duration_ms'] ?? 0),
                is_array($in['engine'] ?? NULL) ? $in['engine'] : NULL,
                mb_substr($err, 0, 500));
        }

        self::_save_usage($part_id, $rev_id, (int) ($in['job_id'] ?? 0), $in['usage'] ?? []);

        if ($rev_id > 0)
        {
            $fields = [
                'status'  => $ok ? self::P_DONE : self::P_FAILED,
                'error'   => $ok ? NULL : mb_substr($err !== '' ? $err : 'Не получилось собрать модель', 0, 2000),
                'attempts' => $attempts,
            ];

            if ($code !== '')
            {
                $fields['code_py'] = $code;
            }

            if ($schema !== NULL)
            {
                $fields['schema_definition'] = $schema;
            }

            if ($params !== NULL)
            {
                $fields['params'] = $params;
            }

            if ($report)
            {
                $fields['report'] = $report;
            }

            self::_update_revision($rev_id, $fields);
        }

        $part_fields = [
            'status'      => $ok ? self::P_DONE : self::P_FAILED,
            'error'       => $ok ? NULL : mb_substr($err !== '' ? $err : 'Не получилось собрать модель', 0, 2000),
            'stage'       => $ok ? 'Готово' : 'Остановлено ошибкой',
            'finished_at' => 'now()',
            'updated_at'  => 'now()',
        ];

        if ($code !== '')
        {
            $part_fields['code_py'] = $code;
        }

        if ($schema !== NULL)
        {
            $part_fields['schema_definition'] = $schema;
        }

        if ($params !== NULL AND $params !== [])
        {
            $part_fields['params'] = $params;
        }

        if ($report)
        {
            $part_fields['report'] = $report;
        }

        self::_update_part($part_id, $part_fields);

        // Название от мета-прохода (второй прогон ИИ) — человеческое имя детали
        // вместо догадки из описания. Владелец (28.09.2026): «потом на основании
        // кода метаинформацию и т.п. следующим прогоном» — название часть неё.
        self::_apply_meta_title($part_id, $p, $report);

        if ($ok)
        {
            self::recount_files($part_id);
        }

        // Финал принят сайтом — задача очереди обязана закрыться здесь же.
        // Иначе строка остаётся processing без пульса, а страница рисует ЖИВОЙ
        // ряд «Собираю деталь» поверх готовой детали: владелец 27.09 видел
        // «Всё отработал, но процесс залип», и эта же строка попадала под
        // авто-остановку/свипер (лишние «отменено» в мониторе).
        self::_close_job($part_id, $ok, $err);

        // Владельцу — знать об исходе, а не ходить проверять страницу: сборка идёт
        // минутами, а о неудачной вообще никто не сообщал (в просьбе владельца
        // 28.09.2026 это «и т.п.»). Задача очереди закрывается здесь же, минуя хук
        // nj597 в JobQueue, поэтому уведомляем сами — и один раз на ревизию.
        self::_notify_owner($p, $ok, $err, $report);

        return ['ok' => TRUE, 'status' => $ok ? self::P_DONE : self::P_FAILED];
    }

    // ------------------------------------------------------------------
    // Чтение для страниц
    // ------------------------------------------------------------------

    /** Деталь по коду-ссылке (мягко удалённые не отдаём). */
    public static function by_code($code)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_643_parts WHERE code = '.self::_db()->quote((string) $code)
            .' AND deleted_at IS NULL', FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function part_by_id($id)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_643_parts WHERE id = '.(int) $id.' AND deleted_at IS NULL', FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Владелец (или управление) — может менять деталь. */
    public static function can_edit(array $p, $user_id, $is_admin = FALSE)
    {
        if ($is_admin)
        {
            return TRUE;
        }

        return ($user_id !== NULL AND (int) $p['user_id'] === (int) $user_id);
    }

    /**
     * Список деталей пользователя. Фильтры: q (поиск), status (чип).
     *
     * @return array строки с полями превью (min(png) файл) — страница рисует карточки
     */
    public static function parts_of($user_id, array $f = [], $limit = 20, $offset = 0, $is_admin = FALSE)
    {
        self::ensure_tables();

        $db    = self::_db();
        $where = ['p.deleted_at IS NULL'];

        if ( ! $is_admin OR empty($f['all']))
        {
            $where[] = 'p.user_id = '.(int) $user_id;
        }

        if (trim((string) ($f['status'] ?? '')) !== '')
        {
            $where[] = 'p.status = '.$db->quote((string) $f['status']);
        }

        $q = trim((string) ($f['q'] ?? ''));

        if ($q !== '')
        {
            $like = $db->quote('%'.$q.'%');
            $where[] = '(p.title ILIKE '.$like.' OR p.prompt ILIKE '.$like.' OR p.code ILIKE '.$like.')';
        }

        $limit  = max(1, min((int) $limit, 100));
        $offset = max(0, (int) $offset);

        // Превью — ракурс ПОСЛЕДНЕЙ ревизии, а не первый попавшийся: раньше
        // стояло `ORDER BY f.id LIMIT 1`, то есть картинка САМОЙ ПЕРВОЙ сборки,
        // и после правок в списке продолжала висеть ревизия 1 (владелец,
        // 27.09: «делаю ревизии, а смотрю только на ревизию 1»).
        $rows = $db->query(Database::SELECT,
            'SELECT p.*, '
            .'(SELECT f.id FROM nj_643_files f WHERE f.part_id = p.id AND f.kind = \'png\' '
            .'   ORDER BY f.revision_id DESC NULLS LAST, f.id DESC LIMIT 1) AS preview_id, '
            .'(SELECT count(*) FROM nj_643_files f WHERE f.part_id = p.id AND f.kind = \'png\') AS views_n, '
            .'(SELECT count(*) FROM nj_643_files f WHERE f.part_id = p.id AND f.kind IN (\'step\', \'stl\', \'glb\')) AS models_n, '
            .'u.email AS owner_email, u.username AS owner_name '
            .'FROM nj_643_parts p LEFT JOIN users u ON u.id = p.user_id '
            .'WHERE '.implode(' AND ', $where)
            .' ORDER BY p.id DESC LIMIT '.$limit.' OFFSET '.$offset, FALSE)->as_array();

        return $rows ? $rows : [];
    }

    /** Сколько всего деталей под фильтр (для «Строк:» и пагинации). */
    public static function count_parts($user_id, array $f = [], $is_admin = FALSE)
    {
        self::ensure_tables();

        $db    = self::_db();
        $where = ['deleted_at IS NULL'];

        if ( ! $is_admin OR empty($f['all']))
        {
            $where[] = 'user_id = '.(int) $user_id;
        }

        if (trim((string) ($f['status'] ?? '')) !== '')
        {
            $where[] = 'status = '.$db->quote((string) $f['status']);
        }

        $q = trim((string) ($f['q'] ?? ''));

        if ($q !== '')
        {
            $like = $db->quote('%'.$q.'%');
            $where[] = '(title ILIKE '.$like.' OR prompt ILIKE '.$like.' OR code ILIKE '.$like.')';
        }

        $row = $db->query(Database::SELECT,
            'SELECT count(*) AS n FROM nj_643_parts WHERE '.implode(' AND ', $where), FALSE)->current();

        return $row ? (int) $row['n'] : 0;
    }

    /** Сколько деталей в каждом статусе (для чипов). */
    public static function counts_of($user_id, $is_admin = FALSE, $all = FALSE)
    {
        self::ensure_tables();

        $out = [
            self::P_DONE      => 0,
            self::P_RUNNING   => 0,
            self::P_QUEUED    => 0,
            self::P_FAILED    => 0,
            self::P_CANCELLED => 0,
        ];

        $where = ['deleted_at IS NULL'];

        if ( ! ($is_admin AND $all))
        {
            $where[] = 'user_id = '.(int) $user_id;
        }

        foreach (self::_db()->query(Database::SELECT,
            'SELECT status, count(*) AS n FROM nj_643_parts WHERE '.implode(' AND ', $where)
            .' GROUP BY status', FALSE)->as_array() as $r)
        {
            $st = (string) $r['status'];

            if (isset($out[$st]))
            {
                $out[$st] = (int) $r['n'];
            }
        }

        return $out;
    }

    /**
     * История ревизий: каждая строка несёт число файлов и id превью-ракурса —
     * по ним на странице строится переключатель ревизий («показать эту»).
     */
    public static function revisions_of($part_id, $limit = 20)
    {
        self::ensure_tables();

        $part_id = (int) $part_id;

        return self::_db()->query(Database::SELECT,
            'SELECT r.id, r.part_id, r.rev, r.action, r.instruction, r.status, r.error, r.attempts, '
            .'       r.created_at, r.finished_at, '
            .'(SELECT count(*) FROM nj_643_files f WHERE f.revision_id = r.id) AS files_n, '
            .'(SELECT count(*) FROM nj_643_files f WHERE f.revision_id = r.id AND f.kind = \'png\') AS views_n, '
            .'(SELECT f.id FROM nj_643_files f WHERE f.revision_id = r.id AND f.kind = \'png\' '
            .'   ORDER BY f.id LIMIT 1) AS preview_id '
            .'FROM nj_643_revisions r WHERE r.part_id = '.$part_id
            .' ORDER BY r.rev DESC LIMIT '.max(1, (int) $limit), FALSE)->as_array();
    }

    /** Ревизия детали по её номеру (для ?rev=N на странице). */
    public static function revision_by_rev($part_id, $rev)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_643_revisions WHERE part_id = '.(int) $part_id.' AND rev = '.(int) $rev.' LIMIT 1', FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function revision($id)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_643_revisions WHERE id = '.(int) $id, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Файлы детали: сначала последняя ревизия, но видны и прошлые (история). */
    public static function files_of($part_id, $revision_id = NULL)
    {
        self::ensure_tables();

        $where = 'part_id = '.(int) $part_id;

        if ($revision_id !== NULL)
        {
            $where .= ' AND revision_id = '.(int) $revision_id;
        }

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_643_files WHERE '.$where.' ORDER BY id', FALSE)->as_array();
    }

    // Ракурсы (PNG) собирает движок, но на странице детали их больше не
    // показываем (владелец, 27.09.2026: «ракурсы не показывай»). Их единственная
    // роль теперь — превью деталей в списках: см. `preview_id` в parts_of().
    public static function model_files_of(array $p, $revision_id = NULL)
    {
        // Файлы — ТОЛЬКО выбранной ревизии, без «покажи хоть что-нибудь»: раньше
        // здесь был откат на файлы других ревизий, и в «Файлы · ревизия 2»
        // владелец видел файлы всех сборок (27.09.2026). Если у ревизии файлов
        // нет — так и пишем, а не подсовываем чужие: по номеру ревизии владелец
        // заказывает печать.
        $rev = ($revision_id !== NULL) ? (int) $revision_id : self::_last_rev_id($p);

        $rows = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_643_files
              WHERE part_id = '.(int) $p['id'].' AND kind IN (\'step\', \'stl\', \'glb\')
                AND revision_id = '.$rev.'
              ORDER BY id DESC', FALSE)->as_array();

        return $rows ? $rows : [];
    }

    public static function file($id)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_643_files WHERE id = '.(int) $id, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** id последней ревизии детали (для «показываем актуальное»). */
    protected static function _last_rev_id(array $p)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT id FROM nj_643_revisions WHERE part_id = '.(int) $p['id'].' ORDER BY rev DESC LIMIT 1', FALSE)->current();

        if ($row)
        {
            return (int) $row['id'];
        }

        return (int) ($p['revision'] ?? 1);
    }

    /**
     * Схема параметров → плоский список для ползунков.
     *
     * Движок (и LLM) может отдать схему в двух формах, принимаем обе:
     *   {"parameters": {"width": {"default": 60, "min": 20, "max": 200}}}
     *   {"width": {"default": 60, "min": 20, "max": 200}}
     *
     * @return array [['name','label','unit','default','min','max','step','value'], …]
     */
    public static function schema_list($schema)
    {
        if (is_string($schema))
        {
            $schema = json_decode((string) $schema, TRUE);
        }

        if ( ! is_array($schema))
        {
            return [];
        }

        if (isset($schema['parameters']) AND is_array($schema['parameters']))
        {
            $schema = $schema['parameters'];
        }

        $out = [];

        foreach ($schema as $name => $def)
        {
            if ( ! is_string($name) OR ! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,40}$/', $name))
            {
                continue;
            }

            $def = is_array($def) ? $def : ['default' => $def];

            $min = isset($def['min']) ? (float) $def['min'] : NULL;
            $max = isset($def['max']) ? (float) $def['max'] : NULL;
            $def_val = isset($def['default']) ? (float) $def['default'] : ($min !== NULL ? $min : 0);

            // шаг ползунка: целые значения — 1, дробные — 0.5 (мельче не нужно)
            $step = ((float) (int) $def_val === (float) $def_val AND ($min === NULL OR (float) (int) $min === $min))
                ? 1 : 0.5;

            $out[] = [
                'name'    => $name,
                'label'   => (string) ($def['label'] ?? $name),
                'unit'    => (string) ($def['unit'] ?? 'мм'),
                'default' => $def_val,
                'min'     => $min,
                'max'     => $max,
                'step'    => $step,
                'value'   => $def_val,
            ];
        }

        return $out;
    }

    /** Схема детали (jsonb) как массив. */
    public static function schema_of(array $p)
    {
        $s = $p['schema_definition'] ?? NULL;

        if (is_string($s))
        {
            $s = json_decode((string) $s, TRUE);
        }

        return is_array($s) ? $s : [];
    }

    /** Текущие значения параметров детали (то, чем собирали в последний раз). */
    public static function params_of(array $p)
    {
        if (is_string($p['params'] ?? NULL))
        {
            $p['params'] = json_decode((string) $p['params'], TRUE);
        }

        $cur = is_array($p['params'] ?? NULL) ? $p['params'] : [];

        // Схема + текущие значения в одном списке: страница рисует ползунки
        $list = [];

        foreach (self::schema_list(self::schema_of($p)) as $item)
        {
            $name = $item['name'];
            $val  = array_key_exists($name, $cur) ? $cur[$name] : $item['default'];

            $item['value'] = is_numeric($val) ? (float) $val : $item['default'];
            $list[] = $item;
        }

        return $list;
    }

    /**
     * Привести присланные параметры к схеме: только известные имена, числа,
     * зажатые в min/max. Так «Пересобрать» не может отправить в движок мусор.
     */
    public static function clean_params(array $in, array $schema)
    {
        $out = [];

        foreach (self::schema_list($schema) as $item)
        {
            $name = $item['name'];

            if ( ! array_key_exists($name, $in))
            {
                continue;
            }

            $val = $in[$name];

            if ( ! is_numeric($val))
            {
                continue;
            }

            $val = (float) $val;

            if ($item['min'] !== NULL AND $val < $item['min'])
            {
                $val = $item['min'];
            }

            if ($item['max'] !== NULL AND $val > $item['max'])
            {
                $val = $item['max'];
            }

            // целые параметры отдаём целыми — так их ждёт код модели
            $out[$name] = ((float) (int) $val === $val) ? (int) $val : round($val, 3);
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Живой статус-ряд (общий механизм partials/live_status)
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

    /** Живая задача детали (payload задачи несёт part_id). */
    public static function live_for($code)
    {
        $p = self::by_code($code);

        if ($p === NULL OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return $p === NULL ? NULL : self::_empty_live();
        }

        // Деталь уже закончена (успех или ошибка) — «идущей» сборки быть не
        // может. Живую строку очереди закрываем, а ряд не показываем: страница
        // нарисует итог. Так «процесс залип» лечится сам при первом открытии
        // страницы, даже если строку оставила старая версия сайта/воркера.
        if (in_array((string) $p['status'], [self::P_DONE, self::P_FAILED], TRUE) AND ! empty($p['finished_at']))
        {
            self::_close_stale_job($p);

            return self::_empty_live();
        }

        return Service_Nj120_JobQueue::instance()->live_status(self::CHANNEL, 'part_id', (int) $p['id']);
    }

    /**
     * Закрыть задачу очереди по факту финала детали (приёмка итога или показ
     * страницы). Единая точка: и «Готово» (mark_delivered), и «Ошибка»
     * (mark_failed_from_site) — иначе ряд висит до авто-остановки.
     */
    protected static function _close_job($part_id, $ok, $error = '')
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return;
        }

        $p = self::part_by_id($part_id);

        if ($p === NULL)
        {
            return;
        }

        $job_id = (int) ($p['job_id'] ?? 0);

        if ($job_id <= 0)
        {
            $job_id = self::active_job_id((int) $part_id, TRUE);
        }

        if ($job_id <= 0)
        {
            return;
        }

        try
        {
            $queue = Service_Nj120_JobQueue::instance();

            if ($ok)
            {
                $queue->mark_delivered($job_id, 'Деталь собрана — результат принят сайтом',
                    ['files' => (int) ($p['files_count'] ?? 0), 'revision' => (int) ($p['revision'] ?? 0)]);
            }
            else
            {
                $queue->mark_failed_from_site($job_id,
                    $error !== '' ? $error : (string) $p['error'],
                    'Деталь не собралась — задача закрыта приёмкой ('.$job_id.')');
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj643: закрытие задачи очереди: :e', [':e' => $e->getMessage()]);
        }
    }

    /**
     * Закрыть зависшую строку очереди, если деталь уже закончена.
     * Идемпотентно; вызывается при отдаче живого ряда.
     */
    protected static function _close_stale_job(array $p)
    {
        $job_id = self::active_job_id((int) $p['id']);

        if ($job_id <= 0)
        {
            return;
        }

        self::_close_job((int) $p['id'], ((string) $p['status'] === self::P_DONE), (string) $p['error']);
    }

    /** Последняя задача детали — включая завершённую (плашка итога). */
    public static function last_for($code)
    {
        $p = self::by_code($code);

        if ($p === NULL OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        $st = Service_Nj120_JobQueue::instance()->last_status(self::CHANNEL, 'part_id', (int) $p['id']);

        return ($st AND ! empty($st['found'])) ? $st : NULL;
    }

    /** Идущая задача детали: id или 0. */
    public static function active_job_id($part_id, $any_state = FALSE)
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return 0;
        }

        $queue = Service_Nj120_JobQueue::instance();

        if ($any_state AND method_exists($queue, 'find_for_delivery'))
        {
            return (int) $queue->find_for_delivery(self::CHANNEL, 'part_id', (int) $part_id);
        }

        return method_exists($queue, 'find_active')
            ? (int) $queue->find_active(self::CHANNEL, 'part_id', (int) $part_id)
            : 0;
    }

    /**
     * Удалить ОДНУ ревизию детали (владелец, 27.09.2026: «Удалить ревизию нужно
     * добавить»).
     *
     * Удаляем именно ревизию, а не деталь: строку в `nj_643_revisions`, её файлы
     * на диске и строки о файлах/расходе. Последнюю (актуальную) ревизию удалять
     * нельзя — именно её код, схему и файлы показывает страница детали, по ней
     * заказывают печать; чтобы её «убрать», нужна новая сборка или удаление
     * детали целиком. Идущую сборку трогать тоже нельзя: воркер допишет файлы в
     * уже удалённую ревизию и они останутся сиротами.
     *
     * @return array ['ok' => TRUE] либо ['error' => 'причина для человека']
     */
    public static function delete_revision($code, $rev, $user_id, $is_admin = FALSE)
    {
        self::ensure_tables();

        $p = self::by_code($code);

        if ($p === NULL)
        {
            return ['error' => 'Деталь не найдена'];
        }

        if ( ! self::can_edit($p, $user_id, $is_admin))
        {
            return ['error' => 'Это не ваша деталь'];
        }

        $row = self::revision_by_rev((int) $p['id'], (int) $rev);

        if ($row === NULL)
        {
            return ['error' => 'Ревизия не найдена'];
        }

        $rev_id = (int) $row['id'];

        if ($rev_id === self::_last_rev_id($p))
        {
            return ['error' => 'Это последняя ревизия детали: она показывает текущее состояние. '
                .'Соберите новую — или удалите деталь целиком.'];
        }

        if (in_array((string) $row['status'], [self::P_QUEUED, self::P_RUNNING], TRUE))
        {
            return ['error' => 'Ревизия ещё собирается — сначала остановите сборку'];
        }

        // Файлы убираем с диска, потом строки (порядок важен: если удалить строки
        // первыми, пути к файлам потеряются и мусор останется навсегда).
        $files_gone = 0;

        foreach (self::files_of((int) $p['id'], $rev_id) as $f)
        {
            $path = self::file_path((string) $f['path']);

            if (is_file($path))
            {
                @unlink($path);
                $files_gone++;
            }
        }

        $db = self::_db();

        $db->query(Database::DELETE, 'DELETE FROM nj_643_files WHERE revision_id = '.$rev_id, FALSE);
        $db->query(Database::DELETE, 'DELETE FROM nj_643_usage WHERE revision_id = '.$rev_id, FALSE);
        $db->query(Database::DELETE, 'DELETE FROM nj_643_revisions WHERE id = '.$rev_id, FALSE);

        // Счётчик файлов детали показываем на странице — пересчитываем честно.
        $db->query(Database::UPDATE,
            'UPDATE nj_643_parts SET files_count = ('
            .'  SELECT count(*) FROM nj_643_files WHERE part_id = '.(int) $p['id'].' AND kind IN (\'step\', \'stl\', \'glb\')'
            .') , updated_at = now() WHERE id = '.(int) $p['id'], FALSE);

        self::audit((int) $p['id'], (string) $p['code'], (int) $user_id, 'delete_revision',
            (string) $p['title'],
            'удалена ревизия '.(int) $row['rev'].' ('.(string) $row['action'].'), '
            .'файлов убрано с диска: '.$files_gone.($is_admin ? ', удалено из управления' : ''));

        return ['ok' => TRUE, 'rev' => (int) $row['rev']];
    }

    /**
     * Журнал обращений к движку (управление).
     *
     * Метод остаётся нужным и после того, как кнопку «Проверить движок» убрали
     * (владелец, 27.09.2026): по журналу видно, как отвечает движок в реальных
     * сборках — сколько занял запрос, был ли отказ. Именно его и показывает
     * /cad/admin. Удалять его вместе с диагностикой не надо: я это уже сделал
     * разок и получил «Call to undefined method Service_Nj643_Cad::runs()».
     */
    public static function runs($limit = 30)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_643_runs ORDER BY id DESC LIMIT '.max(1, (int) $limit), FALSE)->as_array();
    }

    // ------------------------------------------------------------------
    // Расход LLM
    // ------------------------------------------------------------------

    /** Расход по деталям (управление): токены и время по фазам. */
    /**
     * Состояние движка по журналу сборок — без кнопки «Проверить движок».
     *
     * Владелец (27.09.2026) просил убрать ручную проверку: состояние должно
     * читаться из того, что уже произошло. Считаем по `nj_643_runs` за сутки.
     */
    public static function engine_state()
    {
        self::ensure_tables();

        $db = self::_db();

        $ok = $db->query(Database::SELECT,
            'SELECT created_at, duration_ms, kind, http_status FROM nj_643_runs '
            .'WHERE ok = TRUE ORDER BY id DESC LIMIT 1', FALSE)->current();

        $bad = $db->query(Database::SELECT,
            'SELECT created_at, duration_ms, kind, http_status, error FROM nj_643_runs '
            .'WHERE ok = FALSE ORDER BY id DESC LIMIT 1', FALSE)->current();

        $day = $db->query(Database::SELECT,
            'SELECT count(*) AS n, '
            .'SUM(CASE WHEN ok THEN 1 ELSE 0 END) AS ok_n, '
            .'COALESCE(AVG(CASE WHEN ok THEN duration_ms END), 0) AS avg_ms '
            .'FROM nj_643_runs WHERE created_at > now() - interval \'24 hours\'', FALSE)->current();

        return [
            'last_ok'    => $ok,
            'last_bad'   => $bad,
            'day_total'  => $day ? (int) $day['n'] : 0,
            'day_ok'     => $day ? (int) $day['ok_n'] : 0,
            'day_avg_ms' => $day ? (int) $day['avg_ms'] : 0,
        ];
    }

    /** Расход ИИ по дням: вызовы, токены, размер самого большого ответа. */
    public static function usage_daily($days = 7)
    {
        self::ensure_tables();

        $days = max(1, min(60, (int) $days));

        $rows = self::_db()->query(Database::SELECT,
            'SELECT to_char(date_trunc(\'day\', created_at), \'DD.MM\') AS day, '
            .'count(*) AS calls, '
            .'SUM(prompt_tokens) AS prompt_tokens, '
            .'SUM(completion_tokens) AS completion_tokens, '
            .'SUM(prompt_tokens + completion_tokens) AS tokens, '
            .'MAX(completion_tokens) AS max_reply '
            .'FROM nj_643_usage WHERE created_at > now() - interval \''.$days.' days\' '
            .'GROUP BY date_trunc(\'day\', created_at) '
            .'ORDER BY date_trunc(\'day\', created_at) DESC', FALSE)->as_array();

        return $rows ? $rows : [];
    }

    /** Итоги расхода за сутки и за неделю: «сколько уходит на сервис». */
    public static function usage_totals()
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT '
            .'SUM(CASE WHEN created_at > now() - interval \'24 hours\' THEN prompt_tokens + completion_tokens ELSE 0 END) AS day_tokens, '
            .'SUM(CASE WHEN created_at > now() - interval \'24 hours\' THEN 1 ELSE 0 END) AS day_calls, '
            .'SUM(CASE WHEN created_at > now() - interval \'7 days\' THEN prompt_tokens + completion_tokens ELSE 0 END) AS week_tokens, '
            .'SUM(CASE WHEN created_at > now() - interval \'7 days\' THEN 1 ELSE 0 END) AS week_calls '
            .'FROM nj_643_usage', FALSE)->current();

        return [
            'day_tokens'  => $row ? (int) $row['day_tokens'] : 0,
            'day_calls'   => $row ? (int) $row['day_calls'] : 0,
            'week_tokens' => $row ? (int) $row['week_tokens'] : 0,
            'week_calls'  => $row ? (int) $row['week_calls'] : 0,
        ];
    }

    public static function usage_report($limit = 40)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT u.part_id, p.code, p.title, count(*) AS calls,
                    sum(u.prompt_tokens) AS prompt_tokens, sum(u.completion_tokens) AS completion_tokens,
                    sum(u.duration_ms) AS duration_ms, max(u.created_at) AS last_at
               FROM nj_643_usage u LEFT JOIN nj_643_parts p ON p.id = u.part_id
              GROUP BY u.part_id, p.code, p.title
              ORDER BY max(u.created_at) DESC LIMIT '.max(1, (int) $limit), FALSE)->as_array();
    }

    /**
     * Последние вызовы ИИ с разбором расхода (управление).
     *
     * Владелец спрашивал: «почему для генерации такого маленького кода ушло 4598
     * токенов?» — агрегированная таблица на это не отвечает, а здесь видно по
     * каждому вызову: фаза, модель, токены и note от воркера (сколько знаков
     * ответа, где начинается JSON, сколько списано на рассуждения модели).
     */
    public static function usage_log($limit = 30)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT u.*, p.code, p.title FROM nj_643_usage u '
            .'LEFT JOIN nj_643_parts p ON p.id = u.part_id '
            .'ORDER BY u.id DESC LIMIT '.max(1, (int) $limit), FALSE)->as_array();
    }

    /** Расход по одной детали (показываем админу на странице детали). */
    public static function usage_of($part_id)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_643_usage WHERE part_id = '.(int) $part_id.' ORDER BY id DESC LIMIT 40', FALSE)->as_array();
    }

    /** Сводка для управления: всего деталей, в работе, ошибок, файлов, расход. */
    public static function admin_stats()
    {
        self::ensure_tables();

        $out = ['parts' => 0, 'running' => 0, 'failed' => 0, 'done' => 0,
                'files' => 0, 'files_bytes' => 0, 'jobs' => 0, 'tokens' => 0];

        $row = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS parts,
                    count(*) FILTER (WHERE status IN (\'queued\', \'running\')) AS running,
                    count(*) FILTER (WHERE status = \'failed\') AS failed,
                    count(*) FILTER (WHERE status = \'done\') AS done
               FROM nj_643_parts WHERE deleted_at IS NULL', FALSE)->current();

        if ($row)
        {
            $out['parts']   = (int) $row['parts'];
            $out['running'] = (int) $row['running'];
            $out['failed']  = (int) $row['failed'];
            $out['done']    = (int) $row['done'];
        }

        $row = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS files, coalesce(sum(size), 0) AS bytes FROM nj_643_files', FALSE)->current();

        if ($row)
        {
            $out['files']       = (int) $row['files'];
            $out['files_bytes'] = (int) $row['bytes'];
        }

        $row = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS n, coalesce(sum(prompt_tokens + completion_tokens), 0) AS tokens FROM nj_643_usage', FALSE)->current();

        if ($row)
        {
            $out['jobs']   = (int) $row['n'];
            $out['tokens'] = (int) $row['tokens'];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Файлы
    // ------------------------------------------------------------------

    /**
     * Каталог файлов: public_html/storage/nj643 — как у nj641/nj570, потому что
     * вне DOCROOT у php-fpm на проде нет прав на запись.
     */
    public static function storage_dir()
    {
        $dir = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj643';

        if ( ! is_dir($dir))
        {
            @mkdir($dir, 0775, TRUE);
        }

        return $dir;
    }

    /** Абсолютный путь относительного имени (защита от «..»). */
    public static function file_path($rel)
    {
        $rel = str_replace(['..', "\0", '\\'], '', (string) $rel);

        return self::storage_dir().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    /** Человеческое имя для скачивания: «cad1a2b3c4-r2-bracket.step». */
    public static function download_name(array $p, array $f, $rev = NULL)
    {
        $base = trim((string) $p['title']);

        if ($base === '')
        {
            $base = 'model';
        }

        $base = preg_replace('/[^\p{L}\p{N}\- _]+/u', '', $base);
        $base = trim(preg_replace('/\s+/u', '-', (string) $base));
        $base = mb_substr($base !== '' ? $base : 'model', 0, 60);

        $ext = strtolower((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION));

        if ($ext === '')
        {
            $ext = ((string) $f['kind'] === 'step') ? 'step' : (string) $f['kind'];
        }

        $suffix = ((string) $f['kind'] === 'png' AND (string) $f['view'] !== '')
            ? '-'.preg_replace('/[^a-z0-9\-]+/i', '-', (string) $f['view'])
            : '';

        return $base.'-r'.(int) ($rev !== NULL ? $rev : $p['revision']).$suffix.'.'.$ext;
    }

    /**
     * Записать файл ревизии на диск + строку в базе.
     * @return array ['id' => …] | ['error' => …]
     */
    protected static function store_file(array $p, $revision_id, $kind, $name, $bytes, $view = '', array $meta = [])
    {
        $dir = self::storage_dir().DIRECTORY_SEPARATOR.date('Ym');

        if ( ! is_dir($dir) AND ! @mkdir($dir, 0775, TRUE) AND ! is_dir($dir))
        {
            return ['error' => 'Нет прав на запись файлов модели ('.DOCROOT.'storage/nj643)'];
        }

        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if ($ext === '' OR ! preg_match('/^[a-z0-9]{1,6}$/', $ext))
        {
            $ext = ($kind === 'step') ? 'step' : (($kind === 'other') ? 'bin' : $kind);
        }

        $seq  = self::_next_seq((int) $p['id'], (int) $revision_id);
        $file = (string) $p['code'].'-r'.(int) $p['revision'].'-'.$seq.'.'.$ext;

        $full = $dir.DIRECTORY_SEPARATOR.$file;

        if (@file_put_contents($full, $bytes) === FALSE)
        {
            return ['error' => 'Не получилось записать файл модели на диск'];
        }

        @chmod($full, 0664);

        $rel = date('Ym').'/'.$file;
        $db  = self::_db();

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_643_files (part_id, revision_id, kind, view, name, path, size, meta)
             VALUES ('.(int) $p['id'].', '.(int) $revision_id.', '.$db->quote($kind).', '
            .$db->quote(mb_substr($view, 0, 40)).', '.$db->quote(mb_substr($name, 0, 200)).', '
            .$db->quote($rel).', '.strlen($bytes).', '
            .($meta ? $db->quote(json_encode($meta, JSON_UNESCAPED_UNICODE)).'::jsonb' : 'NULL').')
             RETURNING id', FALSE)->current();

        return ['id' => $row ? (int) $row['id'] : 0];
    }

    /** Порядковый номер файла в ревизии (для имени на диске). */
    protected static function _next_seq($part_id, $revision_id)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS n FROM nj_643_files WHERE part_id = '.(int) $part_id
            .' AND revision_id = '.(int) $revision_id, FALSE)->current();

        return ($row ? (int) $row['n'] : 0) + 1;
    }

    /** Пересчитать число файлов у детали (показываем в списке). */
    public static function recount_files($part_id)
    {
        self::_db()->query(Database::UPDATE,
            'UPDATE nj_643_parts SET files_count = (SELECT count(*) FROM nj_643_files WHERE part_id = '.(int) $part_id.') '
            .'WHERE id = '.(int) $part_id, FALSE);
    }

    // ------------------------------------------------------------------
    // Один архив на деталь (ZIP)
    // ------------------------------------------------------------------

    /**
     * Всё для цеха одним архивом: STEP, STL, GLB, код модели и README.
     *
     * Владелец (28.09.2026): «Когда я качаю деталь, то нифига не понятно» и
     * «надо чтобы детали не удалялись просто так (для этого надо сделать архив
     * и запись или что-то в этом духе)». Архив отдаём одним файлом: STEP — CAD
     * и заказ, STL — печать, GLB — просмотр, код модели — его можно править и
     * запускать у себя, README — размеры и параметры.
     *
     * Архив собирается НА ЛЕТУ и не хранится: на диске по-прежнему только файлы
     * ревизии, а архив всегда свежий и не занимает места. Если файла на диске
     * уже нет — он попадает в missing, а не ломает архив.
     *
     * @param array $p          строка детали (parts)
     * @param int   $rev_number номер ревизии со страницы (?rev=N), 0 — последняя
     * @return array ['name', 'bytes', 'entries', 'missing'] | ['error' => …]
     */
    public static function archive_of(array $p, $rev_number = 0)
    {
        $row = ((int) $rev_number > 0) ? self::revision_by_rev((int) $p['id'], (int) $rev_number) : NULL;

        if ($row === NULL)
        {
            $row = self::revision_by_rev((int) $p['id'], (int) ($p['revision'] ?? 1));
        }

        if ($row === NULL)
        {
            return ['error' => 'У детали ещё нет ревизий'];
        }

        $files = self::model_files_of($p, (int) $row['id']);

        if ( ! $files)
        {
            return ['error' => 'В этой ревизии нет файлов модели — сначала соберите её'];
        }

        $stem    = self::archive_stem($p, (int) $row['rev']);
        $entries = [];
        $missing = [];
        $used    = [];
        $total   = 0;

        foreach ($files as $f)
        {
            $path = self::file_path((string) $f['path']);

            if ( ! is_file($path))
            {
                $missing[] = (string) $f['name'];
                continue;
            }

            $raw = @file_get_contents($path);

            if ($raw === FALSE)
            {
                $missing[] = (string) $f['name'];
                continue;
            }

            $ext  = strtolower((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            $ext  = ($ext !== '') ? $ext : (((string) $f['kind'] === 'step') ? 'step' : (string) $f['kind']);
            $base = $stem.'.'.$ext;

            // Один и тот же вид файла мог приехать дважды: имена в архиве обязаны
            // быть уникальными, иначе распаковка спросит «заменить файл?».
            if (isset($used[$base]))
            {
                $used[$base]++;
                $base = $stem.'-'.$used[$base].'.'.$ext;
            }

            $total += strlen($raw);

            if ($total > self::ARCHIVE_MAX_BYTES)
            {
                return ['error' => 'Файлы ревизии слишком тяжёлые для одного архива — '
                    .'скачайте их по одному'];
            }

            $used[$base] = 1;
            $entries[] = ['name' => $base, 'data' => $raw];
        }

        if ( ! $entries)
        {
            return ['error' => 'Файлы ревизии не найдены на диске'];
        }

        // Код модели — часть детали: без него её не пересобрать.
        if (trim((string) ($row['code_py'] ?? '')) !== '')
        {
            $entries[] = ['name' => $stem.'.py', 'data' => (string) $row['code_py']];
        }

        $entries[] = ['name' => 'README.txt',
            'data' => self::_archive_readme($p, $row, (int) $row['rev'], $entries, $missing)];

        return [
            'name'    => $stem.'.zip',
            'bytes'   => self::zip_bytes($entries),
            'entries' => array_map(function ($e) { return $e['name']; }, $entries),
            'missing' => $missing,
        ];
    }

    /** Общее начало имён в архиве: код детали и ревизия (например cad1a2b3c4-r2). */
    public static function archive_stem(array $p, $rev_number)
    {
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $p['code']);
        $base = trim((string) $base, '-');

        return ($base !== '' ? $base : 'part').'-r'.(int) $rev_number;
    }

    /**
     * Геометрия из отчёта движка — то же, что показывает страница детали.
     *
     * @return array ['dims' => float[]|NULL, 'volume' => float|NULL, 'solids' => int|NULL]
     */
    public static function geometry_of(array $report)
    {
        $geo  = (isset($report['geometry']) AND is_array($report['geometry'])) ? $report['geometry'] : [];
        $bbox = $geo['bbox'] ?? NULL;
        $dims = NULL;

        if (is_array($bbox) AND count($bbox) >= 3)
        {
            $dims = [];

            foreach (array_slice(array_values($bbox), 0, 3) as $v)
            {
                $dims[] = round((float) $v, 2);
            }
        }

        return [
            'dims'   => $dims,
            'volume' => (isset($geo['volume']) AND is_numeric($geo['volume'])) ? (float) $geo['volume'] : NULL,
            'solids' => isset($geo['solids']) ? (int) $geo['solids'] : NULL,
        ];
    }

    /**
     * README внутри архива: что за деталь, чем собрана и как пересобрать.
     *
     * Человек, получивший только ZIP, должен понять всё без сайта: габарит,
     * объём, зачем каждый файл и какие размеры можно менять.
     */
    protected static function _archive_readme(array $p, array $rev, $rev_number, array $entries, array $missing)
    {
        $report = $rev['report'] ?? NULL;

        if ( ! is_array($report))
        {
            $report = json_decode((string) $report, TRUE);
        }

        $report = is_array($report) ? $report : [];
        $geo    = self::geometry_of($report);
        $lines  = [];

        $lines[] = 'Деталь: '.trim((string) $p['title']);
        $lines[] = 'Код ссылки: '.(string) $p['code'].'   Ревизия: '.(int) $rev_number;
        $lines[] = 'Ссылка: https://n2j.ru/cad/'.(string) $p['code'].'?rev='.(int) $rev_number;
        $lines[] = 'Собрана: '.(string) $rev['created_at']
            .(((string) $rev['action'] !== 'generate') ? ' ('.(string) $rev['action'].')' : '');
        $lines[] = '';

        if ($geo['dims'] !== NULL)
        {
            $lines[] = 'Габарит: '.implode(' × ', $geo['dims']).' мм'
                .(($geo['volume'] !== NULL) ? '   Объём: '.round($geo['volume'] / 1000, 2).' см³' : '')
                .(($geo['solids'] !== NULL) ? '   Тел: '.$geo['solids'] : '');
            $lines[] = '';
        }

        $lines[] = 'Файлы в архиве:';

        foreach ($entries as $e)
        {
            $lines[] = '  '.$e['name'].' — '.round(strlen((string) $e['data']) / 1024, 1).' КБ';
        }

        $lines[] = '';
        $lines[] = 'Что зачем:';
        $lines[] = '  .step — CAD и заказ (точная геометрия), .stl — печать, .glb — просмотр,';
        $lines[] = '  .py — параметрическая модель на build123d (можно править и запускать у себя).';
        $lines[] = '';

        $params = self::params_of($p);

        if ($params)
        {
            $lines[] = 'Размеры (значения по умолчанию; ползунки — на странице детали):';

            foreach ($params as $item)
            {
                $bounds = '';

                if ($item['min'] !== NULL OR $item['max'] !== NULL)
                {
                    $bounds = ' (от '.(($item['min'] !== NULL) ? $item['min'] : '—')
                        .' до '.(($item['max'] !== NULL) ? $item['max'] : '—').' '.$item['unit'].')';
                }

                $lines[] = '  '.$item['label'].' ('.$item['name'].') = '.$item['value'].' '.$item['unit'].$bounds;
            }

            $lines[] = '';
        }

        $lines[] = 'Пересобрать с другими размерами: откройте ссылку выше, подвиньте ползунки';
        $lines[] = 'и нажмите «Пересобрать» — движок соберёт деталь с новыми размерами.';

        if ($missing)
        {
            $lines[] = '';
            $lines[] = 'Не попали в архив (нет на диске): '.implode(', ', $missing);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * ZIP «на лету», без расширения zip: на проде оно не гарантировано.
     *
     * Формат простой и стабильный: локальные заголовки с данными, затем
     * центральный каталог и его конец — этого достаточно любому распаковщику.
     * Давление — сырой deflate (gzdeflate), если zlib есть; иначе файл кладётся
     * как есть (method 0). Имена — UTF-8 с флагом 0x0800: иначе Windows покажет
     * крякозябры вместо русских названий.
     *
     * @param array $entries [['name' => 'файл.step', 'data' => '…'], …]
     */
    public static function zip_bytes(array $entries)
    {
        $deflate = function_exists('gzdeflate');
        $stamp   = self::_zip_stamp();
        $locals  = '';
        $central = '';
        $count   = 0;
        $offset  = 0;

        foreach ($entries as $e)
        {
            $name   = self::_zip_name((string) $e['name']);
            $data   = (string) $e['data'];
            $crc    = crc32($data);
            $stored = $data;
            $method = 0;

            if ($deflate AND $data !== '')
            {
                $packed = gzdeflate($data, 6);

                if ($packed !== FALSE AND strlen($packed) < strlen($data))
                {
                    $stored = $packed;
                    $method = 8;
                }
            }

            $head = "PK\x03\x04".pack('v', 20).pack('v', 0x0800).pack('v', $method)
                .pack('v', $stamp[0]).pack('v', $stamp[1])
                .pack('V', $crc).pack('V', strlen($stored)).pack('V', strlen($data))
                .pack('v', strlen($name)).pack('v', 0).$name;

            $locals  .= $head.$stored;
            $central .= "PK\x01\x02".pack('v', 20).pack('v', 20).pack('v', 0x0800).pack('v', $method)
                .pack('v', $stamp[0]).pack('v', $stamp[1])
                .pack('V', $crc).pack('V', strlen($stored)).pack('V', strlen($data))
                .pack('v', strlen($name)).pack('v', 0).pack('v', 0).pack('v', 0).pack('v', 0)
                .pack('V', 32).pack('V', $offset).$name;

            $offset += strlen($head) + strlen($stored);
            $count++;
        }

        if ($count === 0)
        {
            return '';
        }

        return $locals.$central."PK\x05\x06".pack('v', 0).pack('v', 0)
            .pack('v', $count).pack('v', $count)
            .pack('V', strlen($central)).pack('V', $offset).pack('v', 0);
    }

    /** Дата и время в формате MS-DOS: ZIP хранит их именно так. */
    protected static function _zip_stamp()
    {
        $t = getdate();

        return [
            (($t['hours'] & 0x1F) << 11) | (($t['minutes'] & 0x3F) << 5) | ((int) ($t['seconds'] / 2) & 0x1F),
            ((max(1980, (int) $t['year']) - 1980) << 9) | (((int) $t['mon'] & 0x0F) << 5) | ((int) $t['mday'] & 0x1F),
        ];
    }

    /** Имя внутри архива: без «..», без ведущих слэшей, без управляющих знаков. */
    protected static function _zip_name($name)
    {
        $name = str_replace(['\\', "\0"], '/', (string) $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', (string) $name);
        $name = preg_replace('#\.\.+#', '', (string) $name);
        $name = preg_replace('#/{2,}#', '/', (string) $name);
        $name = trim((string) $name, '/');

        if ($name === '' OR $name === '/')
        {
            return 'file';
        }

        return mb_substr($name, 0, 120);
    }

    // ------------------------------------------------------------------
    // Следы удалений
    // ------------------------------------------------------------------

    /**
     * Записать след удаления: кто, что и когда убрал.
     *
     * Владелец (28.09.2026): «ещё надо чтобы детали не удалялись просто так».
     * Удаление детали мягкое (deleted_at), но файлы при этом стираются с диска,
     * поэтому единственная память о ней — эта строка. Журнал не должен мешать
     * удалению: не записался — пишем в лог и продолжаем.
     */
    public static function audit($part_id, $code, $user_id, $action, $title = '', $detail = '')
    {
        try
        {
            $db = self::_db();

            $db->query(Database::INSERT,
                'INSERT INTO nj_643_audit (part_id, code, user_id, action, title, detail) VALUES ('
                .(int) $part_id.', '.$db->quote(mb_substr((string) $code, 0, 16)).', '
                .(int) $user_id.', '.$db->quote(mb_substr((string) $action, 0, 30)).', '
                .$db->quote(mb_substr((string) $title, 0, 200)).', '
                .$db->quote(mb_substr((string) $detail, 0, 2000)).')', FALSE);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj643: след удаления не записан: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Журнал удалений (управление): свежие сверху, с почтой того, кто удалил. */
    public static function audit_log($limit = 30)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT a.*, u.email FROM nj_643_audit a '
            .'LEFT JOIN users u ON u.id = a.user_id '
            .'ORDER BY a.id DESC LIMIT '.(int) max(1, min(200, (int) $limit)), FALSE)->as_array();
    }

    /**
     * Сказать владельцу, что сборка закончилась (через nj597).
     *
     * Задача очереди закрывается самой приёмкой результата
     * (`mark_delivered`/`mark_failed_from_site`), минуя хук nj597 в JobQueue, —
     * значит об исходе сборки надо сообщить отсюда, иначе владелец узнаёт о ней,
     * только зайдя на страницу. Один раз на ревизию: ссылка в уведомлении
     * содержит номер ревизии, и повтор в пределах десяти минут (переотправка
     * результата при сетевой ошибке) уже не пишем.
     */
    protected static function _notify_owner(array $p, $ok, $error, array $report)
    {
        if ( ! class_exists('Service_Nj597_Notify'))
        {
            return;
        }

        $owner = (int) ($p['user_id'] ?? 0);

        if ($owner <= 0)
        {
            return;
        }

        $title = trim((string) ($p['title'] ?? ''));

        if ($title === '')
        {
            $title = 'CAD-деталь';
        }

        $rev  = (int) ($p['revision'] ?? 1);
        $href = '/cad/'.(string) $p['code'].'?rev='.$rev;

        try
        {
            $dupe = self::_db()->query(Database::SELECT,
                'SELECT count(*) AS c FROM nj_597_notifications '
                .'WHERE user_id = '.$owner.' AND href = '.self::_db()->quote($href)
                ." AND created_at > now() - interval '10 minutes'", FALSE)->get('c');

            if ((int) $dupe > 0)
            {
                return;
            }
        }
        catch (Throwable $e)
        {
            // таблицы уведомлений нет — это не повод молчать владельцу
        }

        $geo  = self::geometry_of($report);
        $body = '';

        if ($ok)
        {
            $body = 'Ревизия '.$rev.' собрана';

            if ($geo['dims'] !== NULL)
            {
                $body .= ', габарит '.implode(' × ', $geo['dims']).' мм';
            }

            if ($geo['volume'] !== NULL)
            {
                $body .= ', объём '.round($geo['volume'] / 1000, 2).' см³';
            }

            $body .= '. Файлы STEP/STL/GLB готовы — можно скачивать архивом.';
        }
        else
        {
            $body = 'Ревизия '.$rev.' не собралась: '
                .mb_substr(trim((string) $error) !== '' ? trim((string) $error) : 'причина не названа', 0, 300);
        }

        try
        {
            Service_Nj597_Notify::notify($owner, $ok ? 'job_done' : 'job_failed',
                ($ok ? 'Деталь готова: ' : 'Деталь не собралась: ').$title, $body, $href);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj643: уведомление владельцу не ушло: :e', [':e' => $e->getMessage()]);
        }
    }

    /**
     * Взять название детали из мета-прохода (второй прогон ИИ).
     *
     * При создании детали названия нет — есть догадка из описания
     * (`guess_title`: первая строка описания). Мета-проход считает имя по
     * ГОТОВОМУ коду, и оно точнее: «Планка крепления с бобышкой» вместо «Сделай
     * планку…». Подставляем имя, ПОКА название осталось той самой догадкой: если
     * человек переименует деталь (или решит иначе), мета её не перепишет.
     */
    protected static function _apply_meta_title($part_id, array $p, array $report)
    {
        $meta = trim((string) ($report['title'] ?? ''));

        if ($meta === '')
        {
            return;
        }

        $guess = mb_substr(trim((string) self::guess_title((string) $p['prompt'])), 0, 200);

        if (trim((string) $p['title']) !== $guess)
        {
            return;
        }

        self::_update_part((int) $part_id, [
            'title'      => mb_substr($meta, 0, 200),
            'updated_at' => 'now()',
        ]);
    }

    /** Тип содержимого для раздачи файла. */
    public static function mime_of($kind)
    {
        return isset(self::$mime_by_kind[$kind]) ? self::$mime_by_kind[$kind] : 'application/octet-stream';
    }

    // ------------------------------------------------------------------
    // Служебное
    // ------------------------------------------------------------------

    /** Название детали до ответа LLM — по описанию, чтобы список не был безликим. */
    public static function guess_title($prompt)
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $prompt));
        $t = mb_substr($t, 0, 70);

        return $t !== '' ? $t : 'Новая деталь';
    }

    /** Настройки запуска из формы: экспорты, точность сетки, стресс-тест. */
    public static function run_options(array $in)
    {
        $exports = (array) ($in['exports'] ?? self::_cfg('exports', self::EXPORTS));
        $clean   = [];

        foreach (self::EXPORTS as $e)
        {
            if (in_array($e, $exports, TRUE))
            {
                $clean[] = $e;
            }
        }

        if ($clean === [])
        {
            $clean = self::EXPORTS;
        }

        $deflection = isset($in['deflection']) ? (float) $in['deflection'] : (float) self::_cfg('linear_deflection', 0.025);
        $deflection = max(0.005, min($deflection, 0.5));

        return [
            'exports'    => $clean,
            'deflection' => $deflection,
            'stress'     => array_key_exists('stress', $in) ? ! empty($in['stress']) : TRUE,
        ];
    }

    /** Поставить задачу воркеру и запомнить её id в детали и ревизии. */
    protected static function _enqueue($part_id, $revision_id, $action)
    {
        $p = self::part_by_id($part_id);

        if ($p === NULL)
        {
            return 0;
        }

        $params = self::params_of($p);
        $values = [];

        foreach ($params as $item)
        {
            $values[$item['name']] = $item['value'];
        }

        $payload = [
            'action'      => (string) $action,
            'part_id'     => (int) $part_id,
            'revision_id' => (int) $revision_id,
            'code'        => (string) $p['code'],
            'rev'         => (int) $p['revision'],
            'prompt'      => (string) $p['prompt'],
            'code_py'     => (string) $p['code_py'],
            'schema_definition' => self::schema_of($p),
            'params'      => $values,
            'exports'     => (array) json_decode((string) $p['exports'], TRUE) ?: self::EXPORTS,
            'deflection'  => (float) $p['deflection'],
            'stress'      => ! empty($p['stress']),
        ];

        if ($action === self::ACT_REVISE)
        {
            $rev = self::revision($revision_id);
            $payload['instruction'] = $rev ? (string) $rev['instruction'] : '';
        }

        try
        {
            $job_id = (int) Service_Nj120_JobQueue::push(self::CHANNEL, $payload, [
                'module'       => 'nj643',
                'created_by'   => (int) $p['user_id'],
                'max_attempts' => 1,
                'timeout_sec'  => (int) self::_cfg('job_timeout_sec', 1800),
            ]);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj643: задача сборки: :e', [':e' => $e->getMessage()]);

            return 0;
        }

        $db = self::_db();

        $db->query(Database::UPDATE,
            'UPDATE nj_643_parts SET job_id = '.$job_id.', updated_at = now() WHERE id = '.(int) $part_id, FALSE);

        if ($revision_id > 0)
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_643_revisions SET job_id = '.$job_id.' WHERE id = '.(int) $revision_id, FALSE);
        }

        return $job_id;
    }

    /** Новая ревизия детали (строка в истории). */
    protected static function _add_revision($part_id, $rev, $action, $instruction, $user_id)
    {
        $db = self::_db();

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_643_revisions (part_id, rev, action, instruction, status)
             VALUES ('.(int) $part_id.', '.(int) $rev.', '.$db->quote((string) $action).', '
            .$db->quote(mb_substr((string) $instruction, 0, 4000)).', '.$db->quote(self::P_QUEUED).')
             RETURNING id', FALSE)->current();

        return $row ? (int) $row['id'] : 0;
    }

    /** Отметить ревизию ошибкой (когда задача даже не поставилась). */
    public static function fail_revision($revision_id, $error)
    {
        self::_db()->query(Database::UPDATE,
            'UPDATE nj_643_revisions SET status = '.self::_db()->quote(self::P_FAILED).', '
            .'error = '.self::_db()->quote(mb_substr((string) $error, 0, 2000)).', finished_at = now() '
            .'WHERE id = '.(int) $revision_id, FALSE);
    }

    /** Деталь + ревизия в ошибку (когда задача не поставилась или упала сразу). */
    public static function fail_part($part_id, $revision_id, $error)
    {
        $db = self::_db();

        $db->query(Database::UPDATE,
            'UPDATE nj_643_parts SET status = '.$db->quote(self::P_FAILED).', error = '
            .$db->quote(mb_substr((string) $error, 0, 2000)).', stage = '.$db->quote('Остановлено ошибкой').', '
            .'finished_at = now(), updated_at = now() WHERE id = '.(int) $part_id, FALSE);

        if ($revision_id > 0)
        {
            self::fail_revision($revision_id, $error);
        }
    }

    /** Обновить поля ревизии (JSON-поля экранируются как jsonb). */
    protected static function _update_revision($revision_id, array $fields)
    {
        $db   = self::_db();
        $sets = [];

        foreach ($fields as $k => $v)
        {
            $sets[] = self::_set_sql($db, $k, $v);
        }

        $sets[] = 'finished_at = now()';

        $db->query(Database::UPDATE,
            'UPDATE nj_643_revisions SET '.implode(', ', $sets).' WHERE id = '.(int) $revision_id, FALSE);
    }

    /** Обновить поля детали. */
    protected static function _update_part($part_id, array $fields)
    {
        $db   = self::_db();
        $sets = [];

        foreach ($fields as $k => $v)
        {
            $sets[] = self::_set_sql($db, $k, $v);
        }

        if ($sets === [])
        {
            return;
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_643_parts SET '.implode(', ', $sets).' WHERE id = '.(int) $part_id, FALSE);
    }

    /** Одно поле SET: jsonb для массивов, now() для времени, иначе — quote. */
    protected static function _set_sql($db, $field, $value)
    {
        if (is_array($value))
        {
            return $field.' = '.$db->quote(json_encode($value, JSON_UNESCAPED_UNICODE)).'::jsonb';
        }

        if ($value === 'now()')
        {
            return $field.' = now()';
        }

        if ($value === NULL)
        {
            return $field.' = NULL';
        }

        if (is_bool($value))
        {
            return $field.' = '.($value ? 'TRUE' : 'FALSE');
        }

        if (is_int($value))
        {
            return $field.' = '.(int) $value;
        }

        return $field.' = '.$db->quote((string) $value);
    }

    /** Записать журнал обращения к движку. */
    protected static function log_run($part_id, $revision_id, $kind, $ok, $http_status, $duration_ms, $engine, $error)
    {
        try
        {
            $db = self::_db();

            $row = $db->query(Database::SELECT,
                'INSERT INTO nj_643_runs (part_id, revision_id, kind, ok, http_status, duration_ms, engine, error)
                 VALUES ('.(int) $part_id.', '.(int) $revision_id.', '.$db->quote((string) $kind).', '
                .($ok ? 'TRUE' : 'FALSE').', '.(int) $http_status.', '.(int) $duration_ms.', '
                .($engine ? $db->quote(json_encode($engine, JSON_UNESCAPED_UNICODE)).'::jsonb' : 'NULL').', '
                .$db->quote(mb_substr((string) $error, 0, 500)).')
                 RETURNING id', FALSE)->current();

            return $row ? (int) $row['id'] : 0;
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj643: журнал движка: :e', [':e' => $e->getMessage()]);

            return 0;
        }
    }

    /** Строки расхода LLM от воркера → таблица (пишет только сайт). */
    protected static function _save_usage($part_id, $revision_id, $job_id, $usage)
    {
        if ( ! is_array($usage) OR $usage === [])
        {
            return;
        }

        $db = self::_db();

        foreach ($usage as $row)
        {
            if ( ! is_array($row))
            {
                continue;
            }

            try
            {
                $db->query(Database::INSERT,
                    'INSERT INTO nj_643_usage (part_id, revision_id, job_id, phase, model, prompt_tokens, completion_tokens, duration_ms, note)
                     VALUES ('.(int) $part_id.', '.(int) $revision_id.', '.(int) $job_id.', '
                    .$db->quote(mb_substr((string) ($row['phase'] ?? ''), 0, 60)).', '
                    .$db->quote(mb_substr((string) ($row['model'] ?? ''), 0, 160)).', '
                    .(int) ($row['prompt_tokens'] ?? 0).', '.(int) ($row['completion_tokens'] ?? 0).', '
                    .(int) ($row['duration_ms'] ?? 0).', '
                    .$db->quote(mb_substr(trim((string) ($row['note'] ?? '')), 0, 300)).')', FALSE);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj643: расход LLM: :e', [':e' => $e->getMessage()]);
            }
        }
    }

    protected static function _cfg($key, $default = NULL)
    {
        if (class_exists('Kohana') AND Kohana::$config)
        {
            $v = Kohana::$config->load('nj643_cad.'.$key);

            if ($v !== NULL)
            {
                return $v;
            }
        }

        return $default;
    }

    protected static function _db()
    {
        return Database::instance();
    }
}
