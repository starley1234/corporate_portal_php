<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646/001: таблицы сервиса «Разбор состава» (nj646_label).
 *
 * Сервис отвечает на вопрос «что в этом составе и чем это грозит мне?» и
 * накапливает три рода знания:
 *   * БАЗА ВЕЩЕСТВ (`nj_646_ingredients`): карточки ингредиентов — риск,
 *     категория, пояснение, синонимы. Растёт из конфига (посев), из правок
 *     посетителей и из ИИ-изучения неизвестных веществ.
 *   * ОПЫТ РАЗБОРОВ (`nj_646_analyses` + `nj_646_unknowns`): каждый разбор
 *     сохраняется анонимно, незнакомые вещества попадают в очередь изучения —
 *     база знаний закрывает пробелы сама.
 *   * ПРАВКИ (`nj_646_votes`): посетитель может подтвердить или оспорить
 *     оценку; правки видны в админке и учитываются в статистике.
 *
 * Все запросы идемпотентны: повторный накат безопасен.
 */
class pgMigration_Nj_646_001_Label_Tables extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL

-- 1. База веществ: одна карточка на ингредиент (еда/косметика/оба).
CREATE TABLE IF NOT EXISTS "nj_646_ingredients" (
    "id"         BIGSERIAL PRIMARY KEY,
    "slug"       VARCHAR(120) NOT NULL,
    "name_ru"    VARCHAR(200) NOT NULL DEFAULT '',
    "name_int"   VARCHAR(200) NOT NULL DEFAULT '',
    "kind"       VARCHAR(12)  NOT NULL DEFAULT 'both',
    "risk"       SMALLINT     NOT NULL DEFAULT 1,
    "verdict"    VARCHAR(12)  NOT NULL DEFAULT 'neutral',
    "category"   VARCHAR(60)  NOT NULL DEFAULT '',
    "e_code"     VARCHAR(16)  NOT NULL DEFAULT '',
    "summary"    VARCHAR(400) NOT NULL DEFAULT '',
    "details"    TEXT         NOT NULL DEFAULT '',
    "synonyms"   JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "avoid"      JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "sources"    JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "source"     VARCHAR(12)  NOT NULL DEFAULT 'seed',
    "status"     VARCHAR(12)  NOT NULL DEFAULT 'published',
    "confidence" SMALLINT     NOT NULL DEFAULT 100,
    "hits"       INTEGER      NOT NULL DEFAULT 0,
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_646_ingredients_slug_uniq" UNIQUE ("slug")
);
CREATE INDEX IF NOT EXISTS "nj_646_ingredients_name_idx"
    ON "nj_646_ingredients" ("name_ru");
CREATE INDEX IF NOT EXISTS "nj_646_ingredients_kind_idx"
    ON "nj_646_ingredients" ("kind", "status");

COMMENT ON TABLE  "nj_646_ingredients" IS 'NJ-646 база веществ: риск, категория и пояснение для ингредиентов еды и косметики';
COMMENT ON COLUMN "nj_646_ingredients"."risk"    IS '0 полезно/безопасно, 1 нейтрально, 2 осторожно, 3 риск, 4 избегать';
COMMENT ON COLUMN "nj_646_ingredients"."source"  IS 'Откуда карточка: seed (конфиг), user (правка), llm (изучено ИИ)';
COMMENT ON COLUMN "nj_646_ingredients"."status"  IS 'published — показываем; draft — на модерации; hidden — скрыто';
COMMENT ON COLUMN "nj_646_ingredients"."synonyms" IS 'Синонимы и варианты написания (в т.ч. INCI и латиница)';

-- 2. Разборы составов: анонимная история для статистики и истории посетителя.
CREATE TABLE IF NOT EXISTS "nj_646_analyses" (
    "id"         BIGSERIAL PRIMARY KEY,
    "user_id"    INTEGER      NULL,
    "visitor"    VARCHAR(64)  NOT NULL DEFAULT '',
    "kind"       VARCHAR(12)  NOT NULL DEFAULT 'auto',
    "source"     VARCHAR(12)  NOT NULL DEFAULT 'text',
    "title"      VARCHAR(200) NOT NULL DEFAULT '',
    "raw_text"   TEXT         NOT NULL,
    "score"      SMALLINT     NOT NULL DEFAULT 0,
    "verdict"    VARCHAR(12)  NOT NULL DEFAULT 'neutral',
    "counts"     JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "items"      JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "unknown"    JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "profile"    JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_646_analyses_visitor_idx"
    ON "nj_646_analyses" ("visitor", "created_at" DESC);
CREATE INDEX IF NOT EXISTS "nj_646_analyses_created_idx"
    ON "nj_646_analyses" ("created_at" DESC);

COMMENT ON TABLE "nj_646_analyses" IS 'NJ-646 история разборов состава: чем чаще вещество встречается, тем выше его hits';

-- 3. Очередь неизвестных веществ: пробелы базы знаний, которые закрывает ИИ.
CREATE TABLE IF NOT EXISTS "nj_646_unknowns" (
    "id"            BIGSERIAL PRIMARY KEY,
    "name_key"      CHAR(64)     NOT NULL,
    "name_raw"      VARCHAR(200) NOT NULL,
    "kind"          VARCHAR(12)  NOT NULL DEFAULT 'both',
    "seen_count"    INTEGER      NOT NULL DEFAULT 1,
    "status"        VARCHAR(12)  NOT NULL DEFAULT 'new',
    "run_id"        BIGINT       NULL,
    "ingredient_id" BIGINT       NULL,
    "first_seen"    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "last_seen"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_646_unknowns_key_uniq" UNIQUE ("name_key")
);
CREATE INDEX IF NOT EXISTS "nj_646_unknowns_status_idx"
    ON "nj_646_unknowns" ("status", "seen_count" DESC);

COMMENT ON TABLE  "nj_646_unknowns" IS 'NJ-646 неизвестные ингредиенты: очередь на изучение ИИ (new → queued → studied)';
COMMENT ON COLUMN "nj_646_unknowns"."name_key" IS 'sha256 нормализованного названия — один и тот же ингредиент не дублируется';

-- 4. Прогоны воркера: распознавание фото этикетки и изучение ингредиента.
CREATE TABLE IF NOT EXISTS "nj_646_runs" (
    "id"          BIGSERIAL PRIMARY KEY,
    "user_id"     INTEGER      NULL,
    "visitor"     VARCHAR(64)  NOT NULL DEFAULT '',
    "mode"        VARCHAR(12)  NOT NULL DEFAULT 'photo',
    "client_ip"   VARCHAR(64)  NOT NULL DEFAULT '',
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'queued',
    "job_id"      BIGINT       NULL,
    "model"       VARCHAR(160) NOT NULL DEFAULT '',
    "result"      JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "error"       TEXT         NOT NULL DEFAULT '',
    "elapsed_ms"  INTEGER      NOT NULL DEFAULT 0,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_646_runs_visitor_idx" ON "nj_646_runs" ("visitor", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_646_runs_rate_idx" ON "nj_646_runs" ("client_ip", "mode", "created_at" DESC);
CREATE INDEX IF NOT EXISTS "nj_646_runs_created_idx" ON "nj_646_runs" ("created_at");

COMMENT ON TABLE "nj_646_runs" IS 'NJ-646 прогоны воркера: распознавание этикетки по фото и ИИ-изучение ингредиента';

-- 5. Правки и голоса: посетитель подтверждает или оспаривает оценку.
CREATE TABLE IF NOT EXISTS "nj_646_votes" (
    "id"            BIGSERIAL PRIMARY KEY,
    "ingredient_id" BIGINT       NOT NULL,
    "visitor"       VARCHAR(64)  NOT NULL DEFAULT '',
    "value"         SMALLINT     NOT NULL DEFAULT 1,
    "note"          VARCHAR(400) NOT NULL DEFAULT '',
    "created_at"    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_646_votes_uniq" UNIQUE ("ingredient_id", "visitor")
);

COMMENT ON TABLE "nj_646_votes" IS 'NJ-646 правки посетителей к карточкам веществ: +1 согласен, -1 оспариваю, с пояснением';

-- 6. Профиль посетителя: под него подстраиваются советы (анонимно, по куке).
CREATE TABLE IF NOT EXISTS "nj_646_profiles" (
    "id"         BIGSERIAL PRIMARY KEY,
    "visitor"    VARCHAR(64) NOT NULL,
    "skin"       VARCHAR(20) NOT NULL DEFAULT '',
    "diet"       VARCHAR(20) NOT NULL DEFAULT 'none',
    "conditions" JSONB       NOT NULL DEFAULT '[]'::jsonb,
    "allergies"  VARCHAR(400) NOT NULL DEFAULT '',
    "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT "nj_646_profiles_visitor_uniq" UNIQUE ("visitor")
);

COMMENT ON TABLE "nj_646_profiles" IS 'NJ-646 анонимный профиль посетителя: тип кожи, диета, ограничения — для персональных советов';
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        foreach ([
            'nj_646_profiles', 'nj_646_votes', 'nj_646_runs',
            'nj_646_unknowns', 'nj_646_analyses', 'nj_646_ingredients',
        ] as $table)
        {
            $this->run_query('DROP TABLE IF EXISTS "'.$table.'" CASCADE;');
        }
    }
}
