<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 001: проекты пространственных графов, ревизии и
 * журнал модерации.
 *
 * Модель данных — «Figma + Git для графов»: граф лежит в jsonb целиком
 * (nodes/edges), а история правок — компактными дельта-патчами RFC 6902,
 * поэтому undo/redo и ветвление не стоят полной копии графа на каждый чих.
 *
 * nj_667_projects — проект: граф, саммари для RAG, вектор саммари, состояние
 *                   публикации (draft → review → published/rejected) и связь
 *                   с родителем при Remix («использовать как шаблон»);
 * nj_667_revisions — ревизии: kind=commit (патч RFC 6902) и kind=milestone
 *                   (именованная контрольная точка, граф снимком на момент);
 * nj_667_events   — журнал модерации: кто и что решил, с причиной.
 *
 * Колонка embedding: `vector` (pgvector) если расширение доступно, иначе
 * `real[]` плюс своя функция косинусной дистанции — ровно как в nj653/nj115.
 */
class pgMigration_Nj_667_001_Graph3d_CreateTables extends pgMigration
{
	public function up()
	{
		// Размерность берём из окружения: модель эмбеддингов одна на инсталляцию.
		$dims = (int) (getenv('EMBEDDING_MM_DIMENSIONS')
			?: getenv('EMBEDDING_DIMENSIONS')
			?: 2048);

		if ($dims < 8 OR $dims > 16000)
		{
			$dims = 2048;
		}

		// 1. pgvector, если он есть. Нет прав — не беда: работает режим real[].
		$this->run_query("DO \$vec\$ BEGIN
			BEGIN
				IF EXISTS (SELECT 1 FROM pg_available_extensions WHERE name = 'vector') THEN
					CREATE EXTENSION IF NOT EXISTS vector;
				END IF;
			EXCEPTION WHEN OTHERS THEN
				RAISE NOTICE 'pgvector недоступен (%), nj667 работает в режиме real[]', SQLERRM;
			END;
		END \$vec\$;" );

		$this->run_query('CREATE TABLE IF NOT EXISTS nj_667_projects (
			id              BIGSERIAL    PRIMARY KEY,
			code            VARCHAR(24)  NOT NULL UNIQUE,
			user_id         INTEGER      NOT NULL DEFAULT 0,
			visitor_uid     VARCHAR(40)  NOT NULL DEFAULT \'\',
			title           VARCHAR(200) NOT NULL DEFAULT \'\',
			summary         VARCHAR(1000) NOT NULL DEFAULT \'\',
			tags            VARCHAR(300) NOT NULL DEFAULT \'\',
			kind            VARCHAR(16)  NOT NULL DEFAULT \'private\',
			status          VARCHAR(16)  NOT NULL DEFAULT \'draft\',
			featured        SMALLINT     NOT NULL DEFAULT 0,
			graph_json      JSONB        NULL,
			settings_json   JSONB        NULL,
			cover_path      VARCHAR(200) NOT NULL DEFAULT \'\',
			nodes_count     INTEGER      NOT NULL DEFAULT 0,
			edges_count     INTEGER      NOT NULL DEFAULT 0,
			topo_hash       VARCHAR(64)  NOT NULL DEFAULT \'\',
			model           VARCHAR(160) NOT NULL DEFAULT \'\',
			embedding_model VARCHAR(160) NOT NULL DEFAULT \'\',
			embedding_dims  INTEGER      NOT NULL DEFAULT 0,
			remix_of        BIGINT       NOT NULL DEFAULT 0,
			remixes         INTEGER      NOT NULL DEFAULT 0,
			views           INTEGER      NOT NULL DEFAULT 0,
			moderation_note VARCHAR(500) NOT NULL DEFAULT \'\',
			reviewed_by     INTEGER      NOT NULL DEFAULT 0,
			reviewed_at     TIMESTAMPTZ  NULL,
			job_id          BIGINT       NOT NULL DEFAULT 0,
			created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
			updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
			deleted_at      TIMESTAMPTZ  NULL
		)');

		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_projects_code_idx
			ON nj_667_projects (code)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_projects_user_idx
			ON nj_667_projects (user_id, id DESC)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_projects_uid_idx
			ON nj_667_projects (visitor_uid, id DESC)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_projects_status_idx
			ON nj_667_projects (status, updated_at DESC)');
		$this->run_query('COMMENT ON TABLE nj_667_projects IS
			\'NJ-667 проекты пространственных графов: kind private|public, status draft|review|published|rejected\'');

		// 2. Вектор саммари: vector(N) при pgvector, иначе real[].
		$this->run_query("DO \$vec\$
		BEGIN
			IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') THEN
				ALTER TABLE nj_667_projects
					ADD COLUMN IF NOT EXISTS embedding vector({$dims});
			ELSE
				ALTER TABLE nj_667_projects
					ADD COLUMN IF NOT EXISTS embedding real[];
			END IF;
		END
		\$vec\$;" );

		// 3. Косинусная дистанция для режима без pgvector.
		$this->run_query("CREATE OR REPLACE FUNCTION nj_667_vec_dist(a real[], b real[])
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

		// 4. HNSW — только там, где pgvector его потянет (<= 2000 измерений).
		if ($dims <= 2000)
		{
			$this->run_query("DO \$vec\$ BEGIN
				IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') THEN
					BEGIN
						EXECUTE 'CREATE INDEX IF NOT EXISTS nj_667_projects_hnsw_idx
								 ON nj_667_projects USING hnsw (embedding vector_cosine_ops)';
					EXCEPTION WHEN OTHERS THEN
						RAISE NOTICE 'nj667: индекс HNSW не создан (%), ищем перебором', SQLERRM;
					END;
				END IF;
			END \$vec\$;" );
		}

		// 5. Ревизии: компактные патчи RFC 6902 + именованные контрольные точки.
		$this->run_query('CREATE TABLE IF NOT EXISTS nj_667_revisions (
			id          BIGSERIAL    PRIMARY KEY,
			project_id  BIGINT       NOT NULL,
			no          INTEGER      NOT NULL DEFAULT 0,
			kind        VARCHAR(16)  NOT NULL DEFAULT \'commit\',
			label       VARCHAR(200) NOT NULL DEFAULT \'\',
			patch_json  JSONB        NULL,
			graph_json  JSONB        NULL,
			author_kind VARCHAR(16)  NOT NULL DEFAULT \'user\',
			created_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
		)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_revisions_project_idx
			ON nj_667_revisions (project_id, no DESC)');
		$this->run_query('COMMENT ON TABLE nj_667_revisions IS
			\'NJ-667 ревизии графа: kind commit (patch RFC 6902) | milestone (снимок графа)\'');

		// 6. Журнал модерации: вердикт всегда с причиной, иначе автор не поймёт.
		$this->run_query('CREATE TABLE IF NOT EXISTS nj_667_events (
			id         BIGSERIAL   PRIMARY KEY,
			project_id BIGINT      NOT NULL,
			kind       VARCHAR(24) NOT NULL DEFAULT \'\',
			note       VARCHAR(500) NOT NULL DEFAULT \'\',
			user_id    INTEGER     NOT NULL DEFAULT 0,
			created_at TIMESTAMPTZ NOT NULL DEFAULT now()
		)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_events_project_idx
			ON nj_667_events (project_id, id DESC)');
		$this->run_query('COMMENT ON TABLE nj_667_events IS
			\'NJ-667 журнал проекта: submit|approve|reject|feature|unfeature|remix|guard\'');
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS nj_667_events');
		$this->run_query('DROP TABLE IF EXISTS nj_667_revisions');
		$this->run_query('DROP TABLE IF EXISTS nj_667_projects');
		$this->run_query('DROP FUNCTION IF EXISTS nj_667_vec_dist(real[], real[])');
	}
}
