<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-645/001: таблицы «Лаборатории LLM» (nj645_llmlab).
 *
 * Три вида накопления:
 *   * КОРПУС → n-граммы: посетители добавляют тексты, из одобренных
 *     пересчитывается статистика `nj_645_ngrams`, на которой работает
 *     демонстрация «следующий токен». Чем больше корпус, тем богаче модель.
 *   * ЗНАНИЯ: глоссарий (`nj_645_terms`) с накоплением ответов квиза
 *     (сколько раз спрашивали / угадали) и картой путаниц (`nj_645_quiz_misses`).
 *   * ОПЫТ: прогоны реальных LLM (`nj_645_runs`) + слепые сравнения
 *     (`nj_645_prompt_votes`, `nj_645_guesses`) — из них собирается «что
 *     действительно работает».
 *
 * Все запросы идемпотентны: повторный накат безопасен.
 */
class pgMigration_Nj_645_001_Llmlab_Tables extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL

-- 1. Корпус: тексты для обучения n-граммной модели и мини-BPE.
CREATE TABLE IF NOT EXISTS "nj_645_corpus" (
    "id"           BIGSERIAL PRIMARY KEY,
    "user_id"      INTEGER      NULL,
    "visitor"      VARCHAR(64)  NOT NULL DEFAULT '',
    "kind"         VARCHAR(16)  NOT NULL DEFAULT 'prose',
    "lang"         VARCHAR(8)   NOT NULL DEFAULT 'ru',
    "title"        VARCHAR(200) NOT NULL DEFAULT '',
    "content"      TEXT         NOT NULL,
    "content_hash" CHAR(64)     NOT NULL,
    "chars"        INTEGER      NOT NULL DEFAULT 0,
    "tokens_est"   INTEGER      NOT NULL DEFAULT 0,
    "source"       VARCHAR(16)  NOT NULL DEFAULT 'user',
    "status"       VARCHAR(12)  NOT NULL DEFAULT 'pending',
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_645_corpus_hash_uniq" UNIQUE ("content_hash")
);
CREATE INDEX IF NOT EXISTS "nj_645_corpus_status_idx"
    ON "nj_645_corpus" ("status", "created_at" DESC);

COMMENT ON TABLE  "nj_645_corpus"  IS 'NJ-645 корпус: тексты для n-грамм и мини-BPE (модерация: pending/approved/rejected)';
COMMENT ON COLUMN "nj_645_corpus"."content_hash" IS 'sha256 нормализованного текста — один и тот же текст не хранится дважды';
COMMENT ON COLUMN "nj_645_corpus"."visitor"      IS 'обезличенная метка браузера для суточного лимита (без IP и логина)';

-- 2. N-граммы: контекст → токен → счётчик. Основа демонстрации сэмплирования.
CREATE TABLE IF NOT EXISTS "nj_645_ngrams" (
    "id"         BIGSERIAL PRIMARY KEY,
    "n"          SMALLINT     NOT NULL,           -- длина контекста: 0, 1 или 2
    "ctx"        VARCHAR(160) NOT NULL,           -- токены контекста через chr(31); '' — униграммы
    "token"      VARCHAR(80)  NOT NULL,
    "count"      INTEGER      NOT NULL DEFAULT 0,
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_645_ngrams_uniq" UNIQUE ("ctx", "token")
);
CREATE INDEX IF NOT EXISTS "nj_645_ngrams_ctx_idx" ON "nj_645_ngrams" ("ctx");
CREATE INDEX IF NOT EXISTS "nj_645_ngrams_top_idx" ON "nj_645_ngrams" ("ctx", "count" DESC);

COMMENT ON TABLE  "nj_645_ngrams" IS 'NJ-645 n-граммная модель: сколько раз токен встречался после данного контекста';
COMMENT ON COLUMN "nj_645_ngrams"."n" IS 'Длина контекста: 0 — униграмма, 1 — биграмма, 2 — триграмма';

-- 3. Глоссарий и статистика квиза (накопление «что знают/путают»).
CREATE TABLE IF NOT EXISTS "nj_645_terms" (
    "id"           BIGSERIAL PRIMARY KEY,
    "slug"         VARCHAR(80)  NOT NULL,
    "term"         VARCHAR(160) NOT NULL,
    "topic"        VARCHAR(40)  NOT NULL DEFAULT 'основы',
    "difficulty"   SMALLINT     NOT NULL DEFAULT 1,
    "short"        VARCHAR(400) NOT NULL DEFAULT '',
    "body"         TEXT         NOT NULL DEFAULT '',
    "source"       VARCHAR(200) NOT NULL DEFAULT '',
    "quiz_asked"   INTEGER      NOT NULL DEFAULT 0,
    "quiz_correct" INTEGER      NOT NULL DEFAULT 0,
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_645_terms_slug_uniq" UNIQUE ("slug")
);
CREATE INDEX IF NOT EXISTS "nj_645_terms_topic_idx" ON "nj_645_terms" ("topic", "difficulty");

COMMENT ON TABLE "nj_645_terms" IS 'NJ-645 глоссарий LLM-терминов + накопленная статистика квиза';

-- 4. Карта путаниц: какой неверный вариант выбирают чаще всего.
CREATE TABLE IF NOT EXISTS "nj_645_quiz_misses" (
    "id"         BIGSERIAL PRIMARY KEY,
    "term_id"    BIGINT   NOT NULL,
    "chosen_id"  BIGINT   NOT NULL,
    "count"      INTEGER  NOT NULL DEFAULT 0,
    "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT "nj_645_quiz_misses_uniq" UNIQUE ("term_id", "chosen_id")
);

COMMENT ON TABLE "nj_645_quiz_misses" IS 'NJ-645 какие термины путают между собой (накопление ошибок квиза)';

-- 5. Библиотека промптов и голоса за полезность.
CREATE TABLE IF NOT EXISTS "nj_645_prompts" (
    "id"         BIGSERIAL PRIMARY KEY,
    "user_id"    INTEGER      NULL,
    "slug"       VARCHAR(80)  NOT NULL,
    "title"      VARCHAR(200) NOT NULL,
    "goal"       VARCHAR(40)  NOT NULL DEFAULT 'другое',
    "tags"       JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "body"       TEXT         NOT NULL,
    "source"     VARCHAR(16)  NOT NULL DEFAULT 'user',
    "status"     VARCHAR(12)  NOT NULL DEFAULT 'published',
    "votes_up"   INTEGER      NOT NULL DEFAULT 0,
    "votes_down" INTEGER      NOT NULL DEFAULT 0,
    "runs"       INTEGER      NOT NULL DEFAULT 0,
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_645_prompts_slug_uniq" UNIQUE ("slug")
);
CREATE INDEX IF NOT EXISTS "nj_645_prompts_rating_idx"
    ON "nj_645_prompts" ("status", "votes_up" DESC);

COMMENT ON TABLE "nj_645_prompts" IS 'NJ-645 библиотека промптов: стартовые из конфига + присланные посетителями, с голосами';

CREATE TABLE IF NOT EXISTS "nj_645_prompt_votes" (
    "id"         BIGSERIAL PRIMARY KEY,
    "prompt_id"  BIGINT      NOT NULL,
    "voter"      VARCHAR(64) NOT NULL,
    "value"      SMALLINT    NOT NULL,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT "nj_645_prompt_votes_uniq" UNIQUE ("prompt_id", "voter")
);

COMMENT ON TABLE "nj_645_prompt_votes" IS 'NJ-645 голоса за промпты: один посетитель — один голос на промпт';

-- 6. Прогоны реальных LLM: демонстрация работы модели + оценки.
CREATE TABLE IF NOT EXISTS "nj_645_runs" (
    "id"           BIGSERIAL PRIMARY KEY,
    "user_id"      INTEGER      NULL,
    "visitor"      VARCHAR(64)  NOT NULL DEFAULT '',
    "mode"         VARCHAR(16)  NOT NULL DEFAULT 'answer',   -- answer | review
    "series"       VARCHAR(40)  NOT NULL DEFAULT '',         -- общий код пары для арены
    "variant"      VARCHAR(40)  NOT NULL DEFAULT '',         -- подпись варианта
    "model"        VARCHAR(160) NOT NULL DEFAULT '',
    "temperature"  REAL         NOT NULL DEFAULT 0.2,
    "system"       TEXT         NOT NULL DEFAULT '',
    "prompt"       TEXT         NOT NULL DEFAULT '',
    "answer"       TEXT         NOT NULL DEFAULT '',
    "verdict"      JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "status"       VARCHAR(16)  NOT NULL DEFAULT 'queued',   -- queued|running|done|error|cancelled
    "stage"        VARCHAR(200) NOT NULL DEFAULT '',
    "job_id"       BIGINT       NULL,
    "elapsed_ms"   INTEGER      NOT NULL DEFAULT 0,
    "usage"        JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "error"        TEXT         NOT NULL DEFAULT '',
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at"  TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_645_runs_series_idx" ON "nj_645_runs" ("series", "id");
CREATE INDEX IF NOT EXISTS "nj_645_runs_status_idx" ON "nj_645_runs" ("status", "created_at" DESC);
CREATE INDEX IF NOT EXISTS "nj_645_runs_visitor_idx" ON "nj_645_runs" ("visitor", "created_at" DESC);

COMMENT ON TABLE "nj_645_runs" IS 'NJ-645 прогоны LLM (промпт → ответ): демонстрации, сравнения и LLM-ревью промпта';

-- 7. Семантическая карта: векторы текстов посетителей (локальные или серверные).
CREATE TABLE IF NOT EXISTS "nj_645_vectors" (
    "id"         BIGSERIAL PRIMARY KEY,
    "user_id"    INTEGER      NULL,
    "visitor"    VARCHAR(64)  NOT NULL DEFAULT '',
    "label"      VARCHAR(200) NOT NULL DEFAULT '',
    "text"       TEXT         NOT NULL,
    "source"     VARCHAR(16)  NOT NULL DEFAULT 'local',      -- local | embedding
    "dims"       SMALLINT     NOT NULL DEFAULT 0,
    "vector"     JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "neighbors"  JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_645_vectors_created_idx" ON "nj_645_vectors" ("created_at" DESC);

COMMENT ON TABLE "nj_645_vectors" IS 'NJ-645 точки семантической карты: текст → вектор → ближайшие соседи (накопление)';

-- 8. Игра «угадай следующий токен»: человек против модели.
CREATE TABLE IF NOT EXISTS "nj_645_guesses" (
    "id"          BIGSERIAL PRIMARY KEY,
    "user_id"     INTEGER      NULL,
    "visitor"     VARCHAR(64)  NOT NULL DEFAULT '',
    "context"     VARCHAR(240) NOT NULL,
    "context_key" CHAR(64)     NOT NULL,
    "guess"       VARCHAR(80)  NOT NULL,
    "model_token" VARCHAR(80)  NOT NULL,
    "model_prob"  REAL         NOT NULL DEFAULT 0,
    "correct"     BOOLEAN      NOT NULL DEFAULT FALSE,
    "temperature" REAL         NOT NULL DEFAULT 0.7,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_645_guesses_ctx_idx" ON "nj_645_guesses" ("context_key");

COMMENT ON TABLE "nj_645_guesses" IS 'NJ-645 попытки угадать следующий токен: статистика «человек против модели»';

-- 9. Суточные счётчики токенизации (анонимные агрегаты, без текстов).
CREATE TABLE IF NOT EXISTS "nj_645_tokenstats" (
    "id"         BIGSERIAL PRIMARY KEY,
    "day"        DATE        NOT NULL,
    "lang"       VARCHAR(8)  NOT NULL DEFAULT 'ru',
    "kind"       VARCHAR(16) NOT NULL DEFAULT 'prose',
    "chars"      BIGINT      NOT NULL DEFAULT 0,
    "tokens"     BIGINT      NOT NULL DEFAULT 0,
    "samples"    INTEGER     NOT NULL DEFAULT 0,
    "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT "nj_645_tokenstats_uniq" UNIQUE ("day", "lang", "kind")
);

COMMENT ON TABLE "nj_645_tokenstats" IS 'NJ-645 агрегаты замеров токенизации: сколько знаков и токенов прошло через демо';

-- 10. Реестры платформы: чистка старых прогонов и агрегатов.
CREATE INDEX IF NOT EXISTS "nj_645_runs_cleanup_idx"   ON "nj_645_runs" ("status", "created_at");
CREATE INDEX IF NOT EXISTS "nj_645_guesses_created_idx" ON "nj_645_guesses" ("created_at");
CREATE INDEX IF NOT EXISTS "nj_645_corpus_created_idx" ON "nj_645_corpus" ("created_at");
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        foreach ([
            'nj_645_tokenstats', 'nj_645_guesses', 'nj_645_vectors', 'nj_645_runs',
            'nj_645_prompt_votes', 'nj_645_prompts', 'nj_645_quiz_misses',
            'nj_645_terms', 'nj_645_ngrams', 'nj_645_corpus',
        ] as $table)
        {
            $this->run_query('DROP TABLE IF EXISTS "'.$table.'" CASCADE;');
        }
    }
}
