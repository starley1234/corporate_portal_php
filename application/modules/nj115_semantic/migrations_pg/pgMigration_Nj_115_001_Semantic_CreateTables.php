<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция NJ-115: хранилище эмбеддингов и семантический поиск.
 *
 * nj_115_collections — именованные коллекции векторов (по типу сущностей);
 * nj_115_vectors     — векторы: сущность, текст, embedding, модель.
 *
 * Колонка embedding: тип vector (pgvector + HNSW-индекс), если расширение
 * доступно; иначе real[] и функция косинусной дистанции nj_115_vec_dist.
 * Оба варианта поддерживаются сервисом и python-воркером автоматически.
 */
class pgMigration_Nj_115_001_Semantic_CreateTables extends pgMigration {

    public function up()
    {
        // Размерность векторов платформы (одна модель эмбеддингов на инсталляцию)
        $dims = (int) (getenv('EMBEDDING_DIMENSIONS') ?: 1024);
        // 1. pgvector, если доступен в этой инсталляции (docker-образ pgvector).
        // Ошибка создания (нет прав суперпользователя) не рвёт миграцию —
        // тогда работаем в режиме real[] с функцией nj_115_vec_dist.
        $this->run_query("DO \$vec\$ BEGIN
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_available_extensions WHERE name = 'vector') THEN
                    CREATE EXTENSION IF NOT EXISTS vector;
                END IF;
            EXCEPTION WHEN OTHERS THEN
                RAISE NOTICE 'pgvector недоступен (%), работаем в режиме real[]', SQLERRM;
            END;
        END \$vec\$;");

        // 2. Коллекции
        $this->run_query("CREATE TABLE IF NOT EXISTS nj_115_collections
        (
          id          bigserial PRIMARY KEY,
          name        varchar(64) NOT NULL,
          model       varchar(128) NOT NULL DEFAULT '',
          dims        integer NOT NULL DEFAULT 0,
          description varchar(255) NOT NULL DEFAULT '',
          created_at  timestamptz NOT NULL DEFAULT now(),
          CONSTRAINT nj_115_collections_name_key UNIQUE (name)
        );");

        // 3. Векторы — тип колонки зависит от наличия pgvector
        $this->run_query("DO \$vec\$
        BEGIN
            IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') THEN
                CREATE TABLE IF NOT EXISTS nj_115_vectors
                (
                  id            bigserial PRIMARY KEY,
                  collection_id integer NOT NULL,
                  entity        varchar(128) NOT NULL,
                  entity_id     varchar(128) NOT NULL,
                  content       text NOT NULL DEFAULT '',
                  metadata      jsonb NOT NULL DEFAULT '{}'::jsonb,
                  embedding     vector({$dims}) NOT NULL,
                  model         varchar(128) NOT NULL,
                  dims          integer NOT NULL,
                  created_at    timestamptz NOT NULL DEFAULT now(),
                  updated_at    timestamptz NOT NULL DEFAULT now(),
                  CONSTRAINT nj_115_vectors_uniq UNIQUE (collection_id, entity, entity_id)
                );
            ELSE
                CREATE TABLE IF NOT EXISTS nj_115_vectors
                (
                  id            bigserial PRIMARY KEY,
                  collection_id integer NOT NULL,
                  entity        varchar(128) NOT NULL,
                  entity_id     varchar(128) NOT NULL,
                  content       text NOT NULL DEFAULT '',
                  metadata      jsonb NOT NULL DEFAULT '{}'::jsonb,
                  embedding     real[] NOT NULL,
                  model         varchar(128) NOT NULL,
                  dims          integer NOT NULL,
                  created_at    timestamptz NOT NULL DEFAULT now(),
                  updated_at    timestamptz NOT NULL DEFAULT now(),
                  CONSTRAINT nj_115_vectors_uniq UNIQUE (collection_id, entity, entity_id)
                );
            END IF;
        END
        \$vec\$;");

        // 4. Косинусная дистанция для режима без pgvector (vanilla PostgreSQL)
        $this->run_query("CREATE OR REPLACE FUNCTION nj_115_vec_dist(a real[], b real[])
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

        // 5. HNSW-индекс для pgvector (в режиме real[] ищем полным перебором)
        $this->run_query("DO \$vec\$ BEGIN
            IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') THEN
                EXECUTE 'CREATE INDEX IF NOT EXISTS nj_115_vectors_hnsw_idx
                         ON nj_115_vectors USING hnsw (embedding vector_cosine_ops)';
            END IF;
        END \$vec\$;");
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS nj_115_vectors;");
        $this->run_query("DROP TABLE IF EXISTS nj_115_collections;");
        $this->run_query("DROP FUNCTION IF EXISTS nj_115_vec_dist(real[], real[]);");
    }
}
