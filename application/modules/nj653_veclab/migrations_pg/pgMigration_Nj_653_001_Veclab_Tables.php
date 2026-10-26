<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция NJ-653: «Лаборатория векторов».
 *
 * nj_653_runs  — прогоны (проверка модели, добавление, поиск, сравнение):
 *                схема общая с VLM-сервисами, поэтому работают `Vision::run_*`;
 * nj_653_items — коллекция демонстрации: подпись, текст, превью картинки и
 *                сам вектор.
 *
 * Колонка embedding: тип `vector` (pgvector), если расширение доступно, иначе
 * `real[]` плюс функция косинусной дистанции `nj_653_vec_dist` — ровно как в
 * nj115_semantic, оба режима поддержаны сервисом.
 *
 * ПРО ИНДЕКС. HNSW в pgvector не строится на колонках шире 2000 измерений, а
 * у мультимодальных моделей размерность как раз больше (Qwen3-VL-Embedding —
 * 2048). Поэтому индекс создаётся ТОЛЬКО при dims <= 2000; при большей
 * размерности поиск идёт полным перебором. Для витрины на десятки элементов
 * это незаметно, а молча терять половину вектора ради индекса нельзя.
 */
class pgMigration_Nj_653_001_Veclab_Tables extends pgMigration {

    public function up()
    {
        // Размерность берём из окружения: модель одна на инсталляцию.
        $dims = (int) (getenv('EMBEDDING_MM_DIMENSIONS')
            ?: getenv('EMBEDDING_DIMENSIONS')
            ?: 2048);

        if ($dims < 8 OR $dims > 16000)
        {
            $dims = 2048;
        }

        // 1. pgvector, если он есть в этой инсталляции. Нет прав — не беда:
        //    работаем в режиме real[] (ошибка не рвёт миграцию).
        $this->run_query("DO \$vec\$ BEGIN
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_available_extensions WHERE name = 'vector') THEN
                    CREATE EXTENSION IF NOT EXISTS vector;
                END IF;
            EXCEPTION WHEN OTHERS THEN
                RAISE NOTICE 'pgvector недоступен (%), nj653 работает в режиме real[]', SQLERRM;
            END;
        END \$vec\$;");

        // 2. Прогоны. Схема — общая для сервисов на классе Vision.
        $this->run_query("CREATE TABLE IF NOT EXISTS nj_653_runs
        (
          id          bigserial PRIMARY KEY,
          user_id     integer NULL,
          visitor     varchar(64) NOT NULL DEFAULT '',
          client_ip   varchar(64) NOT NULL DEFAULT '',
          job_id      bigint NULL,
          status      varchar(16) NOT NULL DEFAULT 'queued',
          mode        varchar(16) NOT NULL DEFAULT 'probe',
          title       varchar(200) NOT NULL DEFAULT '',
          model       varchar(160) NOT NULL DEFAULT '',
          elapsed_ms  integer NOT NULL DEFAULT 0,
          error       text NOT NULL DEFAULT '',
          result      jsonb NOT NULL DEFAULT '{}'::jsonb,
          created_at  timestamptz NOT NULL DEFAULT now(),
          finished_at timestamptz NULL
        );");

        $this->run_query("CREATE INDEX IF NOT EXISTS nj_653_runs_visitor_idx
                          ON nj_653_runs (visitor, created_at DESC);");
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_653_runs_status_idx
                          ON nj_653_runs (status, created_at DESC);");
        $this->run_query("COMMENT ON TABLE nj_653_runs IS
            'NJ-653 прогоны лаборатории векторов: probe | add | search | compare';");
        $this->run_query("COMMENT ON COLUMN nj_653_runs.mode IS
            'Режим прогона: probe — проверка модели, add — элемент коллекции, search — поиск, compare — пара';");

        // 3. Коллекция. Тип колонки вектора зависит от наличия pgvector.
        $this->run_query("DO \$vec\$
        BEGIN
            IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') THEN
                CREATE TABLE IF NOT EXISTS nj_653_items
                (
                  id         bigserial PRIMARY KEY,
                  user_id    integer NULL,
                  visitor    varchar(64) NOT NULL DEFAULT '',
                  run_id     bigint NULL,
                  label      varchar(200) NOT NULL DEFAULT '',
                  kind       varchar(8) NOT NULL DEFAULT 'text',
                  content    text NOT NULL DEFAULT '',
                  preview    text NOT NULL DEFAULT '',
                  model      varchar(160) NOT NULL DEFAULT '',
                  dims       integer NOT NULL DEFAULT 0,
                  embedding  vector({$dims}) NOT NULL,
                  created_at timestamptz NOT NULL DEFAULT now()
                );
            ELSE
                CREATE TABLE IF NOT EXISTS nj_653_items
                (
                  id         bigserial PRIMARY KEY,
                  user_id    integer NULL,
                  visitor    varchar(64) NOT NULL DEFAULT '',
                  run_id     bigint NULL,
                  label      varchar(200) NOT NULL DEFAULT '',
                  kind       varchar(8) NOT NULL DEFAULT 'text',
                  content    text NOT NULL DEFAULT '',
                  preview    text NOT NULL DEFAULT '',
                  model      varchar(160) NOT NULL DEFAULT '',
                  dims       integer NOT NULL DEFAULT 0,
                  embedding  real[] NOT NULL,
                  created_at timestamptz NOT NULL DEFAULT now()
                );
            END IF;
        END
        \$vec\$;");

        $this->run_query("CREATE INDEX IF NOT EXISTS nj_653_items_visitor_idx
                          ON nj_653_items (visitor, created_at DESC);");
        $this->run_query("COMMENT ON TABLE nj_653_items IS
            'NJ-653 коллекция демонстрации: текст и картинки в одном векторном пространстве';");
        $this->run_query("COMMENT ON COLUMN nj_653_items.preview IS
            'Уменьшенная картинка (data URL) для показа в списке; оригинал кадра не хранится';");
        $this->run_query("COMMENT ON COLUMN nj_653_items.kind IS
            'Модальность элемента: text | image | both';");

        // 4. Косинусная дистанция для режима без pgvector.
        $this->run_query("CREATE OR REPLACE FUNCTION nj_653_vec_dist(a real[], b real[])
        RETURNS double precision AS \$vec\$
        DECLARE
          dot double precision := 0;
          na  double precision := 0;
          nb  double precision := 0;
          n   integer;
          i   integer;
        BEGIN
          n := LEAST(COALESCE(array_length(a, 1), 0), COALESCE(array_length(b, 1), 0));
          IF n = 0 THEN RETURN NULL; END IF;
          FOR i IN 1..n LOOP
            dot := dot + a[i]::double precision * b[i]::double precision;
            na  := na  + a[i]::double precision * a[i]::double precision;
            nb  := nb  + b[i]::double precision * b[i]::double precision;
          END LOOP;
          IF na = 0 OR nb = 0 THEN RETURN NULL; END IF;
          RETURN 1 - dot / sqrt(na * nb);
        END
        \$vec\$
        LANGUAGE plpgsql IMMUTABLE;");

        // 5. HNSW — только там, где pgvector его потянет (<= 2000 измерений).
        if ($dims <= 2000)
        {
            $this->run_query("DO \$vec\$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') THEN
                    BEGIN
                        EXECUTE 'CREATE INDEX IF NOT EXISTS nj_653_items_hnsw_idx
                                 ON nj_653_items USING hnsw (embedding vector_cosine_ops)';
                    EXCEPTION WHEN OTHERS THEN
                        RAISE NOTICE 'nj653: индекс HNSW не создан (%), ищем перебором', SQLERRM;
                    END;
                END IF;
            END \$vec\$;");
        }
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS nj_653_items;');
        $this->run_query('DROP TABLE IF EXISTS nj_653_runs;');
        $this->run_query('DROP FUNCTION IF EXISTS nj_653_vec_dist(real[], real[]);');
    }
}
