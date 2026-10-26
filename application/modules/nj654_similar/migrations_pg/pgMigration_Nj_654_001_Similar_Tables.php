<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция NJ-654: «Похожие картинки».
 *
 * nj_654_items   — банк: файл на диске, подпись, текст и вектор смысла;
 * nj_654_queries — журнал поисков: что искали и что нашлось.
 *
 * Колонка embedding — `vector` (pgvector), если расширение доступно, иначе
 * `real[]` плюс своя функция косинусной дистанции `nj_654_vec_dist`. Чужую
 * функцию (nj653) не зовём: модуль обязан работать сам по себе.
 *
 * HNSW в pgvector не строится шире 2000 измерений, поэтому индекс создаётся
 * только при dims <= 2000; при большей размерности поиск идёт перебором.
 */
class pgMigration_Nj_654_001_Similar_Tables extends pgMigration {

    public function up()
    {
        $dims = (int) (getenv('EMBEDDING_MM_DIMENSIONS')
            ?: getenv('EMBEDDING_DIMENSIONS')
            ?: 1024);

        if ($dims < 8 OR $dims > 16000)
        {
            $dims = 1024;
        }

        $this->run_query("DO \$vec\$ BEGIN
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_available_extensions WHERE name = 'vector') THEN
                    CREATE EXTENSION IF NOT EXISTS vector;
                END IF;
            EXCEPTION WHEN OTHERS THEN
                RAISE NOTICE 'pgvector недоступен (%), nj654 работает в режиме real[]', SQLERRM;
            END;
        END \$vec\$;");

        // Банк. Вектор NULL, пока его не посчитал воркер: элемент виден в
        // списке со словом «считается», а не исчезает до готовности.
        $this->run_query("DO \$vec\$
        BEGIN
            IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') THEN
                CREATE TABLE IF NOT EXISTS nj_654_items
                (
                  id          bigserial PRIMARY KEY,
                  user_id     integer NULL,
                  visitor     varchar(64) NOT NULL DEFAULT '',
                  title       varchar(200) NOT NULL DEFAULT '',
                  note        text NOT NULL DEFAULT '',
                  kind        varchar(8) NOT NULL DEFAULT 'text',
                  file        varchar(160) NOT NULL DEFAULT '',
                  mime        varchar(32) NOT NULL DEFAULT '',
                  width       integer NOT NULL DEFAULT 0,
                  height      integer NOT NULL DEFAULT 0,
                  bytes       integer NOT NULL DEFAULT 0,
                  model       varchar(160) NOT NULL DEFAULT '',
                  dims        integer NOT NULL DEFAULT 0,
                  embedding   vector({$dims}) NULL,
                  status      varchar(16) NOT NULL DEFAULT 'queued',
                  error       text NOT NULL DEFAULT '',
                  fingerprint char(64) NOT NULL DEFAULT '',
                  created_at  timestamptz NOT NULL DEFAULT now(),
                  ready_at    timestamptz NULL
                );
            ELSE
                CREATE TABLE IF NOT EXISTS nj_654_items
                (
                  id          bigserial PRIMARY KEY,
                  user_id     integer NULL,
                  visitor     varchar(64) NOT NULL DEFAULT '',
                  title       varchar(200) NOT NULL DEFAULT '',
                  note        text NOT NULL DEFAULT '',
                  kind        varchar(8) NOT NULL DEFAULT 'text',
                  file        varchar(160) NOT NULL DEFAULT '',
                  mime        varchar(32) NOT NULL DEFAULT '',
                  width       integer NOT NULL DEFAULT 0,
                  height      integer NOT NULL DEFAULT 0,
                  bytes       integer NOT NULL DEFAULT 0,
                  model       varchar(160) NOT NULL DEFAULT '',
                  dims        integer NOT NULL DEFAULT 0,
                  embedding   real[] NULL,
                  status      varchar(16) NOT NULL DEFAULT 'queued',
                  error       text NOT NULL DEFAULT '',
                  fingerprint char(64) NOT NULL DEFAULT '',
                  created_at  timestamptz NOT NULL DEFAULT now(),
                  ready_at    timestamptz NULL
                );
            END IF;
        END
        \$vec\$;");

        // Один и тот же файл (или текст) второй раз в банк не кладём: копии
        // засоряют выдачу — поиск начинает возвращать одно и то же.
        $this->run_query("CREATE UNIQUE INDEX IF NOT EXISTS nj_654_items_print_uidx
                          ON nj_654_items (fingerprint) WHERE fingerprint <> '';");
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_654_items_fresh_idx
                          ON nj_654_items (created_at DESC);");
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_654_items_visitor_idx
                          ON nj_654_items (visitor, created_at DESC);");
        $this->run_query("COMMENT ON TABLE nj_654_items IS
            'NJ-654 банк картинок: файл на диске + вектор смысла для поиска похожего';");
        $this->run_query("COMMENT ON COLUMN nj_654_items.file IS
            'Путь относительно public_html/storage/nj654 (ГГГГММ/хеш.jpg); пусто — элемент без картинки';");

        // Журнал поисков: админу нужно видеть, что ищут люди.
        $this->run_query("CREATE TABLE IF NOT EXISTS nj_654_queries
        (
          id          bigserial PRIMARY KEY,
          user_id     integer NULL,
          visitor     varchar(64) NOT NULL DEFAULT '',
          client_ip   varchar(64) NOT NULL DEFAULT '',
          kind        varchar(8) NOT NULL DEFAULT 'text',
          text        text NOT NULL DEFAULT '',
          file        varchar(160) NOT NULL DEFAULT '',
          top         smallint NOT NULL DEFAULT 1,
          status      varchar(16) NOT NULL DEFAULT 'queued',
          error       text NOT NULL DEFAULT '',
          hits        jsonb NOT NULL DEFAULT '[]'::jsonb,
          elapsed_ms  integer NOT NULL DEFAULT 0,
          created_at  timestamptz NOT NULL DEFAULT now(),
          ready_at    timestamptz NULL
        );");

        $this->run_query("CREATE INDEX IF NOT EXISTS nj_654_queries_fresh_idx
                          ON nj_654_queries (created_at DESC);");
        $this->run_query("COMMENT ON TABLE nj_654_queries IS
            'NJ-654 журнал поисков: что искали, чем (текст/картинка) и что нашлось';");

        $this->run_query("CREATE OR REPLACE FUNCTION nj_654_vec_dist(a real[], b real[])
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

        if ($dims <= 2000)
        {
            $this->run_query("DO \$vec\$ BEGIN
                IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') THEN
                    BEGIN
                        EXECUTE 'CREATE INDEX IF NOT EXISTS nj_654_items_hnsw_idx
                                 ON nj_654_items USING hnsw (embedding vector_cosine_ops)';
                    EXCEPTION WHEN OTHERS THEN
                        RAISE NOTICE 'nj654: индекс HNSW не создан (%), ищем перебором', SQLERRM;
                    END;
                END IF;
            END \$vec\$;");
        }
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS nj_654_queries;');
        $this->run_query('DROP TABLE IF EXISTS nj_654_items;');
        $this->run_query('DROP FUNCTION IF EXISTS nj_654_vec_dist(real[], real[]);');
    }
}
