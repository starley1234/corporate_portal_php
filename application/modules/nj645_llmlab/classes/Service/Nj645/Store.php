<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-645 Store — вся работа «Лаборатории LLM» с базой.
 *
 * Модуль накапливает три рода знания:
 *   * КОРПУС → N-ГРАММЫ. Одобренные тексты превращаются в статистику
 *     «контекст → следующий токен». Это «мозг» демонстрации сэмплирования:
 *     он растёт от каждого добавленного текста.
 *   * ЗНАНИЯ. Глоссарий с накоплением ответов квиза (спрашивали/угадали) и
 *     карта путаниц — видно, какие термины путают чаще всего.
 *   * ОПЫТ. Прогоны реальных LLM, голоса за промпты и попытки угадать
 *     следующий токен. Из них собирается ответ «что действительно работает».
 *
 * Чистая логика (токенизация, softmax, оценка промпта, локальные векторы)
 * живёт в Service_Nj645_Lab и тестируется без базы.
 */
class Service_Nj645_Store
{
    /** @var Database|NULL */
    protected static $_db = NULL;

    /** @var bool защита от повторного создания таблиц в одном запросе */
    protected static $_ensured = FALSE;

    /** @var array|NULL кеш словаря мини-BPE на запрос */
    protected static $_vocab = NULL;

    /** @var array|NULL кеш синонимов/терминов на запрос */
    protected static $_terms = NULL;

    public static function db()
    {
        return self::$_db !== NULL ? self::$_db : self::$_db = Database::instance();
    }

    /** Публичный доступ к конфигу модуля (для службы засева и вьюх). */
    public static function cfg($key = NULL, $default = NULL)
    {
        return $key === NULL ? self::_cfg(NULL, $default) : self::_cfg($key, $default);
    }

    protected static function _cfg($key, $default = NULL)
    {
        static $cfg = NULL;

        if ($cfg === NULL)
        {
            $cfg = (array) Kohana::$config->load('nj645_llmlab');
        }

        $value = $cfg;

        foreach (explode('.', $key) as $part)
        {
            if ( ! is_array($value) OR ! array_key_exists($part, $value))
            {
                return $default;
            }

            $value = $value[$part];
        }

        return $value === NULL ? $default : $value;
    }

    protected static function _limit($key, $default)
    {
        return (int) self::_cfg('limits.'.$key, $default);
    }

    /** Безопасный JSON для вставки в колонку jsonb. */
    protected static function _json($value)
    {
        return self::db()->quote(json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    /** Однострочный SELECT с нормализацией пустого результата (FALSE ≠ NULL). */
    protected static function _one($sql, $key = NULL)
    {
        $row = self::db()->query(Database::SELECT, $sql, FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        $row = (array) $row;

        return $key === NULL ? $row : ($row[$key] ?? NULL);
    }

    protected static function _all($sql)
    {
        $rows = self::db()->query(Database::SELECT, $sql, FALSE)->as_array();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Создать таблицы, если миграция ещё не накатана (стенды и первые
     * запросы до `db:migrate`). Идемпотентно, повторный вызов — no-op.
     */
    public static function ensure_tables()
    {
        if (self::$_ensured)
        {
            return;
        }

        self::$_ensured = TRUE;

        // Таблицы уже есть (обычный случай после миграции) — не гоняем DDL.
        try
        {
            self::db()->query(Database::SELECT, 'SELECT 1 FROM nj_645_corpus LIMIT 1', FALSE);
            return;
        }
        catch (Throwable $e)
        {
            // Таблиц нет — создаём (миграция 001 делает то же самое).
        }

        $stmts = [
            'CREATE TABLE IF NOT EXISTS "nj_645_corpus" (
                "id" BIGSERIAL PRIMARY KEY, "user_id" INTEGER NULL, "visitor" VARCHAR(64) NOT NULL DEFAULT \'\',
                "kind" VARCHAR(16) NOT NULL DEFAULT \'prose\', "lang" VARCHAR(8) NOT NULL DEFAULT \'ru\',
                "title" VARCHAR(200) NOT NULL DEFAULT \'\', "content" TEXT NOT NULL, "content_hash" CHAR(64) NOT NULL,
                "chars" INTEGER NOT NULL DEFAULT 0, "tokens_est" INTEGER NOT NULL DEFAULT 0,
                "source" VARCHAR(16) NOT NULL DEFAULT \'user\', "status" VARCHAR(12) NOT NULL DEFAULT \'pending\',
                "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(), "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT "nj_645_corpus_hash_uniq" UNIQUE ("content_hash"))',
            'CREATE TABLE IF NOT EXISTS "nj_645_ngrams" (
                "id" BIGSERIAL PRIMARY KEY, "n" SMALLINT NOT NULL, "ctx" VARCHAR(160) NOT NULL,
                "token" VARCHAR(80) NOT NULL, "count" INTEGER NOT NULL DEFAULT 0,
                "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT "nj_645_ngrams_uniq" UNIQUE ("ctx", "token"))',
            'CREATE TABLE IF NOT EXISTS "nj_645_terms" (
                "id" BIGSERIAL PRIMARY KEY, "slug" VARCHAR(80) NOT NULL, "term" VARCHAR(160) NOT NULL,
                "topic" VARCHAR(40) NOT NULL DEFAULT \'основы\', "difficulty" SMALLINT NOT NULL DEFAULT 1,
                "short" VARCHAR(400) NOT NULL DEFAULT \'\', "body" TEXT NOT NULL DEFAULT \'\',
                "source" VARCHAR(200) NOT NULL DEFAULT \'\', "quiz_asked" INTEGER NOT NULL DEFAULT 0,
                "quiz_correct" INTEGER NOT NULL DEFAULT 0, "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
                "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now(), CONSTRAINT "nj_645_terms_slug_uniq" UNIQUE ("slug"))',
            'CREATE TABLE IF NOT EXISTS "nj_645_quiz_misses" (
                "id" BIGSERIAL PRIMARY KEY, "term_id" BIGINT NOT NULL, "chosen_id" BIGINT NOT NULL,
                "count" INTEGER NOT NULL DEFAULT 0, "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT "nj_645_quiz_misses_uniq" UNIQUE ("term_id", "chosen_id"))',
            'CREATE TABLE IF NOT EXISTS "nj_645_prompts" (
                "id" BIGSERIAL PRIMARY KEY, "user_id" INTEGER NULL, "slug" VARCHAR(80) NOT NULL,
                "title" VARCHAR(200) NOT NULL, "goal" VARCHAR(40) NOT NULL DEFAULT \'другое\',
                "tags" JSONB NOT NULL DEFAULT \'[]\'::jsonb, "body" TEXT NOT NULL,
                "source" VARCHAR(16) NOT NULL DEFAULT \'user\', "status" VARCHAR(12) NOT NULL DEFAULT \'published\',
                "votes_up" INTEGER NOT NULL DEFAULT 0, "votes_down" INTEGER NOT NULL DEFAULT 0,
                "runs" INTEGER NOT NULL DEFAULT 0, "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
                "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now(), CONSTRAINT "nj_645_prompts_slug_uniq" UNIQUE ("slug"))',
            'CREATE TABLE IF NOT EXISTS "nj_645_prompt_votes" (
                "id" BIGSERIAL PRIMARY KEY, "prompt_id" BIGINT NOT NULL, "voter" VARCHAR(64) NOT NULL,
                "value" SMALLINT NOT NULL, "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT "nj_645_prompt_votes_uniq" UNIQUE ("prompt_id", "voter"))',
            'CREATE TABLE IF NOT EXISTS "nj_645_runs" (
                "id" BIGSERIAL PRIMARY KEY, "user_id" INTEGER NULL, "visitor" VARCHAR(64) NOT NULL DEFAULT \'\',
                "mode" VARCHAR(16) NOT NULL DEFAULT \'answer\', "series" VARCHAR(40) NOT NULL DEFAULT \'\',
                "variant" VARCHAR(40) NOT NULL DEFAULT \'\', "model" VARCHAR(160) NOT NULL DEFAULT \'\',
                "temperature" REAL NOT NULL DEFAULT 0.2, "system" TEXT NOT NULL DEFAULT \'\',
                "prompt" TEXT NOT NULL DEFAULT \'\', "answer" TEXT NOT NULL DEFAULT \'\',
                "verdict" JSONB NOT NULL DEFAULT \'{}\'::jsonb, "status" VARCHAR(16) NOT NULL DEFAULT \'queued\',
                "stage" VARCHAR(200) NOT NULL DEFAULT \'\', "job_id" BIGINT NULL,
                "elapsed_ms" INTEGER NOT NULL DEFAULT 0, "usage" JSONB NOT NULL DEFAULT \'{}\'::jsonb,
                "error" TEXT NOT NULL DEFAULT \'\', "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
                "finished_at" TIMESTAMPTZ NULL)',
            'CREATE TABLE IF NOT EXISTS "nj_645_vectors" (
                "id" BIGSERIAL PRIMARY KEY, "user_id" INTEGER NULL, "visitor" VARCHAR(64) NOT NULL DEFAULT \'\',
                "label" VARCHAR(200) NOT NULL DEFAULT \'\', "text" TEXT NOT NULL,
                "source" VARCHAR(16) NOT NULL DEFAULT \'local\', "dims" SMALLINT NOT NULL DEFAULT 0,
                "vector" JSONB NOT NULL DEFAULT \'[]\'::jsonb, "neighbors" JSONB NOT NULL DEFAULT \'[]\'::jsonb,
                "created_at" TIMESTAMPTZ NOT NULL DEFAULT now())',
            'CREATE TABLE IF NOT EXISTS "nj_645_guesses" (
                "id" BIGSERIAL PRIMARY KEY, "user_id" INTEGER NULL, "visitor" VARCHAR(64) NOT NULL DEFAULT \'\',
                "context" VARCHAR(240) NOT NULL, "context_key" CHAR(64) NOT NULL, "guess" VARCHAR(80) NOT NULL,
                "model_token" VARCHAR(80) NOT NULL, "model_prob" REAL NOT NULL DEFAULT 0,
                "correct" BOOLEAN NOT NULL DEFAULT FALSE, "temperature" REAL NOT NULL DEFAULT 0.7,
                "created_at" TIMESTAMPTZ NOT NULL DEFAULT now())',
            'CREATE TABLE IF NOT EXISTS "nj_645_tokenstats" (
                "id" BIGSERIAL PRIMARY KEY, "day" DATE NOT NULL, "lang" VARCHAR(8) NOT NULL DEFAULT \'ru\',
                "kind" VARCHAR(16) NOT NULL DEFAULT \'prose\', "chars" BIGINT NOT NULL DEFAULT 0,
                "tokens" BIGINT NOT NULL DEFAULT 0, "samples" INTEGER NOT NULL DEFAULT 0,
                "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT "nj_645_tokenstats_uniq" UNIQUE ("day", "lang", "kind"))',
            'CREATE INDEX IF NOT EXISTS "nj_645_ngrams_top_idx" ON "nj_645_ngrams" ("ctx", "count" DESC)',
            'CREATE INDEX IF NOT EXISTS "nj_645_terms_topic_idx" ON "nj_645_terms" ("topic", "difficulty")',
            'CREATE INDEX IF NOT EXISTS "nj_645_runs_series_idx" ON "nj_645_runs" ("series", "id")',
            'CREATE INDEX IF NOT EXISTS "nj_645_vectors_created_idx" ON "nj_645_vectors" ("created_at" DESC)',
        ];

        foreach ($stmts as $sql)
        {
            try
            {
                DB::query(NULL, $sql)->execute();
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj645: ensure_tables: :e', [':e' => $e->getMessage()]);
            }
        }
    }

    // ------------------------------------------------------------------ корпус

    /**
     * Добавить текст в корпус. Дубль по нормализованному тексту возвращает
     * существующую запись («такой текст уже есть»), а не создаёт вторую.
     *
     * @return array ['status' => 'stored'|'known'|'error', 'id' => int, 'error' => string]
     */
    public static function corpus_add($text, $kind, $lang, $title, $visitor, $user_id = NULL)
    {
        self::ensure_tables();

        $text  = trim((string) $text);
        $norm  = Service_Nj645_Lab::normalize_text($text);
        $hash  = hash('sha256', $norm);
        $chars = mb_strlen($text);
        $est   = Service_Nj645_Lab::estimate_tokens($text);

        $row = self::_one('SELECT id FROM nj_645_corpus WHERE content_hash = '.self::db()->quote($hash));

        if ($row)
        {
            return ['status' => 'known', 'id' => (int) $row['id'], 'error' => ''];
        }

        $sql = 'INSERT INTO nj_645_corpus
                    (user_id, visitor, kind, lang, title, content, content_hash, chars, tokens_est, source, status)
                VALUES ('.($user_id ? (int) $user_id : 'NULL').', '.self::db()->quote((string) $visitor).',
                        '.self::db()->quote((string) $kind).', '.self::db()->quote((string) $lang).',
                        '.self::db()->quote(mb_substr((string) $title, 0, 200)).', '.self::db()->quote($text).',
                        '.self::db()->quote($hash).', '.(int) $chars.', '.(int) $est['total'].', \'user\', \'pending\')
                RETURNING id';

        $id = self::_one($sql, 'id');

        return $id
            ? ['status' => 'stored', 'id' => (int) $id, 'error' => '']
            : ['status' => 'error', 'id' => 0, 'error' => 'Не удалось сохранить текст'];
    }

    /** Сколько текстов посетитель добавил за сутки (лимит без участия админа). */
    public static function corpus_today($visitor)
    {
        self::ensure_tables();

        return (int) self::_one(
            'SELECT COUNT(*) AS c FROM nj_645_corpus
             WHERE visitor = '.self::db()->quote((string) $visitor).'
               AND created_at > now() - interval \'1 day\'', 'c');
    }

    /** Тексты корпуса по статусу: pending (на модерации) / approved / rejected. */
    public static function corpus_list($status, $limit = 30)
    {
        self::ensure_tables();

        $limit = max(1, min(200, (int) $limit));

        return self::_all(
            'SELECT id, user_id, kind, lang, title, chars, tokens_est, source, status, created_at
             FROM nj_645_corpus
             WHERE status = '.self::db()->quote((string) $status).'
             ORDER BY id DESC LIMIT '.$limit);
    }

    /**
     * Модерация текста: одобренный текст сразу учит n-граммную модель, а
     * отклонённый исключается из неё (пересборкой, если он успел повлиять).
     *
     * @return array ['status' => ..., 'text' => '...']
     */
    public static function corpus_moderate($id, $status)
    {
        self::ensure_tables();

        $id     = (int) $id;
        $status = in_array($status, ['approved', 'rejected', 'pending'], TRUE) ? $status : 'pending';

        $row = self::_one('SELECT id, content, status FROM nj_645_corpus WHERE id = '.$id);

        if ( ! $row)
        {
            return ['status' => 'error', 'text' => 'Текст не найден'];
        }

        DB::update('nj_645_corpus')
            ->set(['status' => $status, 'updated_at' => DB::expr('now()')])
            ->where('id', '=', $id)
            ->execute();

        if ($status === 'approved')
        {
            self::learn_text((string) $row['content']);
        }
        elseif ($row['status'] === 'approved')
        {
            // Текст был в модели — честно пересобираем статистику без него.
            self::rebuild_ngrams();
        }

        return ['status' => 'ok', 'text' => 'Статус обновлён'];
    }

    /**
     * Выучить текст: разложить на токены и увеличить счётчики n-грамм
     * (униграммы, биграммы, триграммы). Пачками по 200 значений.
     */
    public static function learn_text($text)
    {
        self::ensure_tables();

        $tokens = Service_Nj645_Lab::ngram_tokens($text);

        if (count($tokens) < 2)
        {
            return 0;
        }

        $pairs = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++)
        {
            $token = $tokens[$i];

            $pairs[] = [0, '', $token];                     // униграмма

            if ($i >= 1)
            {
                $pairs[] = [1, $tokens[$i - 1], $token];    // биграмма
            }

            if ($i >= 2)
            {
                $pairs[] = [2, $tokens[$i - 2].chr(31).$tokens[$i - 1], $token];  // триграмма
            }
        }

        // Схлопываем одинаковые пары в PHP: меньше строк в одном INSERT.
        $agg = [];

        foreach ($pairs as $pair)
        {
            $key = $pair[0].chr(30).$pair[1].chr(30).$pair[2];
            $agg[$key] = isset($agg[$key]) ? $agg[$key] + 1 : 1;
        }

        $values = [];

        foreach ($agg as $key => $n)
        {
            list($n_ctx, $ctx, $token) = explode(chr(30), $key);

            $values[] = '('.((int) $n_ctx).', '.self::db()->quote($ctx).', '.self::db()->quote($token).', '.$n.')';
        }

        $learned = 0;

        foreach (array_chunk($values, 200) as $chunk)
        {
            $sql = 'INSERT INTO nj_645_ngrams (n, ctx, token, count)
                    VALUES '.implode(', ', $chunk).'
                    ON CONFLICT ("ctx", "token")
                    DO UPDATE SET count = nj_645_ngrams.count + EXCLUDED.count, updated_at = now()';

            DB::query(NULL, $sql)->execute();
            $learned += count($chunk);
        }

        self::$_vocab = NULL;

        return $learned;
    }

    /** Пересобрать n-граммы с нуля по всем одобренным текстам (модерация, seed). */
    public static function rebuild_ngrams($limit_docs = 500)
    {
        self::ensure_tables();

        DB::query(NULL, 'TRUNCATE TABLE nj_645_ngrams')->execute();

        $rows = self::_all('SELECT content FROM nj_645_corpus
                            WHERE status = \'approved\' ORDER BY id LIMIT '.(int) $limit_docs);

        $learned = 0;

        foreach ($rows as $row)
        {
            $learned += self::learn_text((string) $row['content']);
        }

        self::$_vocab = NULL;

        return $learned;
    }

    /**
     * Распределение «какой токен следующим» по n-граммам для данного контекста.
     *
     * Возвращает строки с весом: триграмма весит 1.0, биграмма 0.18,
     * униграмма 0.01 — стандартная схема backoff: точное совпадение решает,
     * редкое подстраховывает. Веса, а не вероятности: нормировку и
     * температуру применяет Service_Nj645_Lab::distribution().
     */
    public static function ngram_rows($ctx2, $ctx1, $limit = 60)
    {
        self::ensure_tables();

        $db = self::db();

        $sql = 'SELECT token, count, n FROM nj_645_ngrams
                WHERE ctx IN ('.$db->quote($ctx2).', '.$db->quote($ctx1).', \'\')
                ORDER BY count DESC LIMIT '.(int) max(10, min(400, $limit * 6));

        $weights = [2 => 1.0, 1 => 0.18, 0 => 0.01];
        $merged  = [];

        foreach (self::_all($sql) as $row)
        {
            $token = (string) $row['token'];
            $score = ((int) $row['count']) * $weights[(int) $row['n']];

            if ( ! isset($merged[$token]))
            {
                $merged[$token] = ['token' => $token, 'score' => 0.0, 'count' => 0, 'n' => (int) $row['n']];
            }

            $merged[$token]['score'] += $score;
            $merged[$token]['count'] += (int) $row['count'];
            $merged[$token]['n'] = max($merged[$token]['n'], (int) $row['n']);
        }

        usort($merged, function ($a, $b) {
            return $a['score'] < $b['score'] ? 1 : ($a['score'] > $b['score'] ? -1 : 0);
        });

        return array_slice($merged, 0, $limit);
    }

    /**
     * Словарь мини-BPE: слова корпуса, которые встречались достаточно часто,
     * чтобы стать целым токеном. Отсюда «почему у модели словарь, а не буквы».
     *
     * @return array ['words' => [слово => счётчик], 'pieces' => [строка,...]]
     */
    public static function vocabulary($force = FALSE)
    {
        if (self::$_vocab !== NULL AND ! $force)
        {
            return self::$_vocab;
        }

        self::ensure_tables();

        $min = (int) self::_cfg('vocab.min_word_count', 2);

        $words = [];

        foreach (self::_all('SELECT token, count FROM nj_645_ngrams
                             WHERE ctx = \'\' AND count >= '.$min.'
                             ORDER BY count DESC LIMIT 1000') as $row)
        {
            $token = (string) $row['token'];

            if (preg_match('/[\p{L}\p{N}]/u', $token) AND mb_strlen($token) >= 2)
            {
                $words[$token] = (int) $row['count'];
            }
        }

        $pieces = array_keys($words);

        // Частые начала и концы слов: ими режутся незнакомые слова.
        $prefix = [];
        $suffix = [];

        foreach ($words as $word => $count)
        {
            $len = mb_strlen($word);
            $pl  = min(6, $len - 1);
            $sl  = min(6, $len - 1);

            for ($i = 2; $i <= $pl; $i++)
            {
                $piece = mb_substr($word, 0, $i);
                $prefix[$piece] = (isset($prefix[$piece]) ? $prefix[$piece] : 0) + $count;
            }

            for ($i = 2; $i <= $sl; $i++)
            {
                $piece = mb_substr($word, $len - $i, $i);
                $suffix[$piece] = (isset($suffix[$piece]) ? $suffix[$piece] : 0) + $count;
            }
        }

        arsort($prefix);
        arsort($suffix);

        $pieces = array_merge($pieces, array_keys(array_slice($prefix, 0, 150, TRUE)),
            array_keys(array_slice($suffix, 0, 150, TRUE)));

        self::$_vocab = [
            'words'  => $words,
            'pieces' => array_values(array_unique($pieces)),
        ];

        return self::$_vocab;
    }

    // --------------------------------------------------------------- глоссарий

    public static function term_upsert($slug, $term, $topic, $difficulty, $short, $body, $source)
    {
        $sql = 'INSERT INTO nj_645_terms (slug, term, topic, difficulty, short, body, source)
                VALUES ('.self::db()->quote((string) $slug).', '.self::db()->quote((string) $term).',
                        '.self::db()->quote((string) $topic).', '.(int) $difficulty.',
                        '.self::db()->quote((string) $short).', '.self::db()->quote((string) $body).',
                        '.self::db()->quote((string) $source).')
                ON CONFLICT ("slug") DO UPDATE SET
                    term = EXCLUDED.term, topic = EXCLUDED.topic, difficulty = EXCLUDED.difficulty,
                    short = EXCLUDED.short, body = EXCLUDED.body, source = EXCLUDED.source,
                    updated_at = now()
                RETURNING id';

        return (int) self::_one($sql, 'id');
    }

    /** Все термины глоссария (с накопленной статистикой квиза). */
    public static function terms_all($topic = NULL)
    {
        self::ensure_tables();

        $where = $topic ? ' WHERE topic = '.self::db()->quote((string) $topic) : '';

        return self::_all('SELECT id, slug, term, topic, difficulty, short, body, source,
                                  quiz_asked, quiz_correct
                           FROM nj_645_terms'.$where.'
                           ORDER BY topic, difficulty, term');
    }

    /** Случайные термины для квиза. */
    public static function quiz_pick($n = 5, $difficulty = NULL, $topic = NULL)
    {
        self::ensure_tables();

        $n     = max(2, min(10, (int) $n));
        $where = [];

        if ($difficulty)
        {
            $where[] = 'difficulty = '.(int) $difficulty;
        }

        if ($topic)
        {
            $where[] = 'topic = '.self::db()->quote((string) $topic);
        }

        return self::_all('SELECT id, term, topic, difficulty, short FROM nj_645_terms'
            .($where ? ' WHERE '.implode(' AND ', $where) : '')
            .' ORDER BY random() LIMIT '.$n);
    }

    /** Варианты-обманки: определения других терминов (лучше — той же темы). */
    public static function quiz_distractors($term_id, $topic, $n = 3)
    {
        self::ensure_tables();

        $db = self::db();

        $rows = self::_all('SELECT id, term, short FROM nj_645_terms
                            WHERE id <> '.(int) $term_id.' AND topic = '.$db->quote((string) $topic).'
                            ORDER BY random() LIMIT '.(int) $n);

        if (count($rows) < $n)
        {
            $have = [];
            foreach ($rows as $row)
            {
                $have[] = (int) $row['id'];
            }

            $more = self::_all('SELECT id, term, short FROM nj_645_terms
                                WHERE id <> '.(int) $term_id
                .($have ? ' AND id NOT IN ('.implode(', ', $have).')' : '')
                .' ORDER BY random() LIMIT '.($n - count($rows)));

            $rows = array_merge($rows, $more);
        }

        return $rows;
    }

    /**
     * Записать ответ квиза: обновить статистику термина и карту путаниц.
     * Это и есть накопление «что люди знают и что путают».
     */
    public static function quiz_record($term_id, $chosen_id, $correct)
    {
        self::ensure_tables();

        DB::query(NULL, 'UPDATE nj_645_terms
                         SET quiz_asked = quiz_asked + 1,
                             quiz_correct = quiz_correct + '.($correct ? 1 : 0).',
                             updated_at = now()
                         WHERE id = '.(int) $term_id)->execute();

        if ( ! $correct AND $chosen_id)
        {
            DB::query(NULL, 'INSERT INTO nj_645_quiz_misses (term_id, chosen_id, count)
                             VALUES ('.(int) $term_id.', '.(int) $chosen_id.', 1)
                             ON CONFLICT ("term_id", "chosen_id")
                             DO UPDATE SET count = nj_645_quiz_misses.count + 1, updated_at = now()')->execute();
        }
    }

    /** Самые частые путаницы: термины, которые выбирают вместо правильного. */
    public static function quiz_confusions($limit = 8)
    {
        self::ensure_tables();

        return self::_all('SELECT m.count, t.term AS term, c.term AS chosen
                           FROM nj_645_quiz_misses m
                           JOIN nj_645_terms t ON t.id = m.term_id
                           JOIN nj_645_terms c ON c.id = m.chosen_id
                           ORDER BY m.count DESC, t.term
                           LIMIT '.(int) max(1, min(30, $limit)));
    }

    // ---------------------------------------------------------------- промпты

    public static function prompt_upsert($slug, $title, $goal, array $tags, $body, $source = 'user', $user_id = NULL)
    {
        self::ensure_tables();

        $sql = 'INSERT INTO nj_645_prompts (user_id, slug, title, goal, tags, body, source, status)
                VALUES ('.($user_id ? (int) $user_id : 'NULL').', '.self::db()->quote((string) $slug).',
                        '.self::db()->quote((string) $title).', '.self::db()->quote((string) $goal).',
                        '.self::_json($tags).', '.self::db()->quote((string) $body).',
                        '.self::db()->quote((string) $source).', \'published\')
                ON CONFLICT ("slug") DO NOTHING
                RETURNING id';

        return (int) self::_one($sql, 'id');
    }

    public static function prompts_list($goal = NULL, $limit = 60, $status = 'published')
    {
        self::ensure_tables();

        // Админке нужны и скрытые промпты: $status = NULL снимает фильтр.
        $where = $status !== NULL ? ' WHERE status = '.self::db()->quote((string) $status) : '';

        if ($goal)
        {
            $where .= ($where === '' ? ' WHERE' : ' AND').' goal = '.self::db()->quote((string) $goal);
        }

        return self::_all('SELECT id, title, goal, tags, body, source, status, votes_up, votes_down, runs, created_at
                           FROM nj_645_prompts'.$where.'
                           ORDER BY (votes_up - votes_down) DESC, votes_up DESC, id
                           LIMIT '.(int) max(1, min(200, $limit)));
    }

    /** Голос за промпт: один посетитель — один голос, повтор меняет его. */
    public static function prompt_vote($prompt_id, $voter, $value)
    {
        self::ensure_tables();

        $prompt_id = (int) $prompt_id;
        $value     = $value >= 0 ? 1 : -1;

        $old = self::_one('SELECT value FROM nj_645_prompt_votes
                           WHERE prompt_id = '.$prompt_id.' AND voter = '.self::db()->quote((string) $voter));

        if ($old)
        {
            $old_value = (int) $old['value'];

            if ($old_value === $value)
            {
                return ['status' => 'same'];
            }

            DB::update('nj_645_prompt_votes')
                ->set(['value' => $value])
                ->where('prompt_id', '=', $prompt_id)
                ->where('voter', '=', (string) $voter)
                ->execute();

            DB::query(NULL, 'UPDATE nj_645_prompts
                             SET votes_up = votes_up + '.($value === 1 ? 1 : -1).',
                                 votes_down = votes_down + '.($value === -1 ? 1 : -1).',
                                 updated_at = now()
                             WHERE id = '.$prompt_id)->execute();

            return ['status' => 'changed'];
        }

        DB::insert('nj_645_prompt_votes', ['prompt_id', 'voter', 'value'])
            ->values([$prompt_id, (string) $voter, $value])
            ->execute();

        DB::query(NULL, 'UPDATE nj_645_prompts
                         SET votes_up = votes_up + '.($value === 1 ? 1 : 0).',
                             votes_down = votes_down + '.($value === -1 ? 1 : 0).',
                             updated_at = now()
                         WHERE id = '.$prompt_id)->execute();

        return ['status' => 'voted'];
    }

    // ----------------------------------------------------------------- прогоны

    public static function run_create(array $data)
    {
        self::ensure_tables();

        $defaults = [
            'user_id' => NULL, 'visitor' => '', 'mode' => 'answer', 'series' => '', 'variant' => '',
            'model' => '', 'temperature' => 0.2, 'system' => '', 'prompt' => '', 'status' => 'queued',
        ];

        $data += $defaults;

        $sql = 'INSERT INTO nj_645_runs
                    (user_id, visitor, mode, series, variant, model, temperature, system, prompt, status)
                VALUES ('.($data['user_id'] ? (int) $data['user_id'] : 'NULL').',
                        '.self::db()->quote((string) $data['visitor']).',
                        '.self::db()->quote((string) $data['mode']).',
                        '.self::db()->quote((string) $data['series']).',
                        '.self::db()->quote((string) $data['variant']).',
                        '.self::db()->quote((string) $data['model']).',
                        '.number_format((float) $data['temperature'], 2, '.', '').',
                        '.self::db()->quote((string) $data['system']).',
                        '.self::db()->quote((string) $data['prompt']).',
                        '.self::db()->quote((string) $data['status']).')
                RETURNING id';

        return (int) self::_one($sql, 'id');
    }

    public static function run_set_job($id, $job_id)
    {
        DB::update('nj_645_runs')
            ->set(['job_id' => (int) $job_id])
            ->where('id', '=', (int) $id)
            ->execute();
    }

    public static function run_get($id)
    {
        self::ensure_tables();

        return self::_one('SELECT * FROM nj_645_runs WHERE id = '.(int) $id);
    }

    /** Прогоны одного посетителя (для панели «мои опыты»). */
    public static function runs_mine($visitor, $limit = 12)
    {
        self::ensure_tables();

        return self::_all('SELECT id, mode, series, variant, model, temperature, status, stage,
                                  left(prompt, 200) AS prompt, answer, error, created_at, finished_at
                           FROM nj_645_runs
                           WHERE visitor = '.self::db()->quote((string) $visitor).'
                           ORDER BY id DESC LIMIT '.(int) max(1, min(50, $limit)));
    }

    /** Публичная лента удачных прогонов — «что умеет модель». */
    public static function runs_public($limit = 10)
    {
        self::ensure_tables();

        return self::_all('SELECT id, mode, variant, model, temperature, answer, created_at
                           FROM nj_645_runs
                           WHERE status = \'done\' AND length(answer) > 0
                           ORDER BY id DESC LIMIT '.(int) max(1, min(30, $limit)));
    }

    /** Применить результат воркера (или ошибку) к прогону. */
    public static function run_apply($id, array $result)
    {
        $status = (string) ($result['status'] ?? 'done');

        if ( ! in_array($status, ['done', 'error', 'running', 'cancelled'], TRUE))
        {
            $status = 'done';
        }

        $data = [
            'status'  => $status,
            'answer'  => (string) ($result['answer'] ?? ''),
            'stage'   => (string) ($result['stage'] ?? ''),
            'error'   => (string) ($result['error'] ?? ''),
            'usage'   => DB::expr(self::_json((array) ($result['usage'] ?? []))),
            'verdict' => DB::expr(self::_json((array) ($result['verdict'] ?? []))),
        ];

        if (isset($result['elapsed_ms']))
        {
            $data['elapsed_ms'] = (int) $result['elapsed_ms'];
        }

        if (in_array($status, ['done', 'error', 'cancelled'], TRUE))
        {
            $data['finished_at'] = DB::expr('now()');
        }

        if (isset($result['model']) AND $result['model'] !== '')
        {
            $data['model'] = (string) $result['model'];
        }

        DB::update('nj_645_runs')->set($data)->where('id', '=', (int) $id)->execute();

        return $status;
    }

    /** Сколько прогонов посетитель запустил за час (лимит LLM-расхода). */
    public static function runs_last_hour($visitor)
    {
        self::ensure_tables();

        return (int) self::_one('SELECT COUNT(*) AS c FROM nj_645_runs
                                 WHERE visitor = '.self::db()->quote((string) $visitor).'
                                   AND created_at > now() - interval \'1 hour\'', 'c');
    }

    // ---------------------------------------------------------------- векторы

    public static function vectors_add($visitor, $label, $text, array $vector, $source, array $neighbors = [])
    {
        self::ensure_tables();

        $sql = 'INSERT INTO nj_645_vectors (visitor, label, text, source, dims, vector, neighbors)
                VALUES ('.self::db()->quote((string) $visitor).',
                        '.self::db()->quote(mb_substr((string) $label, 0, 200)).',
                        '.self::db()->quote((string) $text).',
                        '.self::db()->quote((string) $source).',
                        '.count($vector).', '.self::_json(array_map('floatval', $vector)).',
                        '.self::_json($neighbors).')
                RETURNING id';

        return (int) self::_one($sql, 'id');
    }

    /** Точки семантической карты: свежие + сколько всего накоплено. */
    public static function vectors_map($limit = 60, $exclude_id = NULL)
    {
        self::ensure_tables();

        $where = $exclude_id ? ' WHERE id <> '.(int) $exclude_id : '';

        $rows = self::_all('SELECT id, label, text, source, dims, vector, created_at
                            FROM nj_645_vectors'.$where.'
                            ORDER BY id DESC LIMIT '.(int) max(2, min(300, $limit)));

        foreach ($rows as $i => $row)
        {
            $rows[$i]['text'] = mb_substr((string) $row['text'], 0, 160);
        }

        return $rows;
    }

    public static function vectors_count()
    {
        self::ensure_tables();

        return (int) self::_one('SELECT COUNT(*) AS c FROM nj_645_vectors', 'c');
    }

    /** Старые точки не копим вечно: держим последние N (реестр чистки). */
    public static function vectors_trim($keep = 400)
    {
        self::ensure_tables();

        DB::query(NULL, 'DELETE FROM nj_645_vectors
                         WHERE id NOT IN (SELECT id FROM nj_645_vectors ORDER BY id DESC LIMIT '.(int) $keep.')')->execute();
    }

    // --------------------------------------------------------------- угадайка

    public static function guess_add($visitor, $user_id, $context, $guess, $model_token, $model_prob, $correct, $temperature)
    {
        self::ensure_tables();

        $sql = 'INSERT INTO nj_645_guesses
                    (user_id, visitor, context, context_key, guess, model_token, model_prob, correct, temperature)
                VALUES ('.($user_id ? (int) $user_id : 'NULL').', '.self::db()->quote((string) $visitor).',
                        '.self::db()->quote(mb_substr((string) $context, 0, 240)).',
                        '.self::db()->quote(hash('sha256', $context)).',
                        '.self::db()->quote(mb_substr((string) $guess, 0, 80)).',
                        '.self::db()->quote(mb_substr((string) $model_token, 0, 80)).',
                        '.number_format((float) $model_prob, 5, '.', '').',
                        '.($correct ? 'TRUE' : 'FALSE').',
                        '.number_format((float) $temperature, 2, '.', '').')';

        DB::query(NULL, $sql)->execute();
    }

    /** Hit-парад: статистика «человек против n-граммной модели». */
    public static function guess_stats()
    {
        self::ensure_tables();

        $row = self::_one('SELECT COUNT(*) AS total,
                                  COUNT(*) FILTER (WHERE correct) AS correct,
                                  COUNT(DISTINCT context_key) AS contexts
                           FROM nj_645_guesses');

        $total   = (int) ($row['total'] ?? 0);
        $correct = (int) ($row['correct'] ?? 0);

        return [
            'total'    => $total,
            'correct'  => $correct,
            'contexts' => (int) ($row['contexts'] ?? 0),
            'accuracy' => $total > 0 ? round(100 * $correct / $total, 1) : NULL,
        ];
    }

    /** Контексты, где люди чаще всего расходятся с моделью. */
    public static function guess_hardest($limit = 6)
    {
        self::ensure_tables();

        return self::_all('SELECT context, COUNT(*) AS tries,
                                  COUNT(*) FILTER (WHERE correct) AS hits,
                                  max(model_token) AS model_token,
                                  round(avg(model_prob)::numeric, 3) AS avg_prob
                           FROM nj_645_guesses
                           GROUP BY context
                           HAVING COUNT(*) >= 2
                           ORDER BY (COUNT(*) - COUNT(*) FILTER (WHERE correct)) DESC, COUNT(*) DESC
                           LIMIT '.(int) max(1, min(20, $limit)));
    }

    // ---------------------------------------------------- токенизация и итоги

    /** Суточный агрегат: знаки/токены по языкам — без текстов и без людей. */
    public static function tokenstat_add($lang, $kind, $chars, $tokens)
    {
        self::ensure_tables();

        $lang = preg_match('/^[a-z]{2,8}$/', (string) $lang) ? $lang : 'other';
        $kind = preg_match('/^[a-z]{2,16}$/', (string) $kind) ? $kind : 'prose';

        $sql = 'INSERT INTO nj_645_tokenstats (day, lang, kind, chars, tokens, samples)
                VALUES (CURRENT_DATE, '.self::db()->quote($lang).', '.self::db()->quote($kind).',
                        '.(int) $chars.', '.(int) $tokens.', 1)
                ON CONFLICT ("day", "lang", "kind") DO UPDATE SET
                    chars = nj_645_tokenstats.chars + EXCLUDED.chars,
                    tokens = nj_645_tokenstats.tokens + EXCLUDED.tokens,
                    samples = nj_645_tokenstats.samples + 1,
                    updated_at = now()';

        DB::query(NULL, $sql)->execute();
    }

    /**
     * Сводка лаборатории: всё, что она накопила. Отсюда собирается вкладка
     * «Что узнала лаборатория» — цифры меняются от работы посетителей.
     */
    public static function stats()
    {
        self::ensure_tables();

        $counts = self::_one('SELECT
            (SELECT COUNT(*) FROM nj_645_corpus WHERE status = \'approved\') AS corpus_ok,
            (SELECT COUNT(*) FROM nj_645_corpus WHERE status = \'pending\')  AS corpus_pending,
            (SELECT COUNT(*) FROM nj_645_corpus WHERE status = \'approved\') AS corpus_total,
            (SELECT COALESCE(SUM(chars), 0) FROM nj_645_corpus WHERE status = \'approved\') AS corpus_chars,
            (SELECT COUNT(*) FROM nj_645_ngrams) AS ngrams,
            (SELECT COALESCE(SUM(count), 0) FROM nj_645_ngrams WHERE n = 1) AS bigram_hits,
            (SELECT COUNT(*) FROM nj_645_terms) AS terms,
            (SELECT COALESCE(SUM(quiz_asked), 0) FROM nj_645_terms) AS quiz_asked,
            (SELECT COALESCE(SUM(quiz_correct), 0) FROM nj_645_terms) AS quiz_correct,
            (SELECT COUNT(*) FROM nj_645_prompts WHERE status = \'published\') AS prompts,
            (SELECT COUNT(*) FROM nj_645_runs) AS runs,
            (SELECT COUNT(*) FROM nj_645_runs WHERE status = \'done\') AS runs_done,
            (SELECT COUNT(*) FROM nj_645_vectors) AS vectors,
            (SELECT COUNT(*) FROM nj_645_guesses) AS guesses,
            (SELECT COUNT(*) FROM nj_645_guesses WHERE correct) AS guesses_hit',
            NULL);

        $counts = is_array($counts) ? $counts : [];
        $asked  = (int) ($counts['quiz_asked'] ?? 0);
        $right  = (int) ($counts['quiz_correct'] ?? 0);

        $token = self::_one('SELECT COALESCE(SUM(chars), 0) AS chars,
                                    COALESCE(SUM(tokens), 0) AS tokens,
                                    COALESCE(SUM(samples), 0) AS samples
                             FROM nj_645_tokenstats');

        return [
            'corpus_ok'      => (int) ($counts['corpus_ok'] ?? 0),
            'corpus_pending' => (int) ($counts['corpus_pending'] ?? 0),
            'corpus_chars'   => (int) ($counts['corpus_chars'] ?? 0),
            'ngrams'         => (int) ($counts['ngrams'] ?? 0),
            'bigram_hits'    => (int) ($counts['bigram_hits'] ?? 0),
            'terms'          => (int) ($counts['terms'] ?? 0),
            'quiz_asked'     => $asked,
            'quiz_accuracy'  => $asked > 0 ? round(100 * $right / $asked, 1) : NULL,
            'prompts'        => (int) ($counts['prompts'] ?? 0),
            'runs'           => (int) ($counts['runs'] ?? 0),
            'runs_done'      => (int) ($counts['runs_done'] ?? 0),
            'vectors'        => (int) ($counts['vectors'] ?? 0),
            'guesses'        => (int) ($counts['guesses'] ?? 0),
            'guesses_hit'    => (int) ($counts['guesses_hit'] ?? 0),
            'token_chars'    => (int) ($token['chars'] ?? 0),
            'token_tokens'   => (int) ($token['tokens'] ?? 0),
            'token_samples'  => (int) ($token['samples'] ?? 0),
        ];
    }

    /** Тексты корпуса — для статистики «сколько чего в базе» (без содержимого). */
    public static function corpus_titles($limit = 12)
    {
        self::ensure_tables();

        return self::_all('SELECT id, title, kind, lang, chars, tokens_est, source, created_at
                           FROM nj_645_corpus WHERE status = \'approved\'
                           ORDER BY id DESC LIMIT '.(int) max(1, min(50, $limit)));
    }
}
