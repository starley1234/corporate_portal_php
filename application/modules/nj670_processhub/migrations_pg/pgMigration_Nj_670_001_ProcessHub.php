<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ProcessHub (nj670): онтология предприятия, процессы, версии «План/Факт»,
 * семантический индекс шагов, импорты журналов, AI-аудит и отчёты.
 *
 * Все таблицы — только nj_670_*. Идемпотентно: повторный up на уже
 * применённой схеме ничего не делает. down снимает объекты в обратном
 * порядке; чужих таблиц не касается.
 *
 * Почему bigserial, а не UUID из ТЗ: платформа живёт на bigint-идентификаторах,
 * а публичный адрес процесса — короткий code (латиница/цифры). UUID добавил бы
 * второй стиль ключей и лишние преобразования в каждом запросе.
 */
class pgMigration_Nj_670_001_ProcessHub extends pgMigration {

	public function up()
	{
		// 1. Рабочее пространство = компания/контур пользователя.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_670_workspaces
		(
			id           bigserial PRIMARY KEY,
			user_id      integer NOT NULL,
			code         varchar(24) NOT NULL UNIQUE,
			title        varchar(255) NOT NULL DEFAULT '',
			company_name varchar(255) NOT NULL DEFAULT '',
			settings     jsonb NOT NULL DEFAULT '{}'::jsonb,
			created_at   timestamptz NOT NULL DEFAULT now(),
			updated_at   timestamptz NOT NULL DEFAULT now()
		)");

		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_workspaces_user_idx
			ON nj_670_workspaces (user_id)");

		// 2. Справочники онтологии: роли, системы, артефакты, события,
		//    метрики, правила, подразделения.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_670_entities
		(
			id           bigserial PRIMARY KEY,
			workspace_id bigint NOT NULL REFERENCES nj_670_workspaces (id) ON DELETE CASCADE,
			class_type   varchar(20) NOT NULL,
			code         varchar(100) NOT NULL,
			name         varchar(255) NOT NULL,
			attributes   jsonb NOT NULL DEFAULT '{}'::jsonb,
			is_base      boolean NOT NULL DEFAULT false,
			created_at   timestamptz NOT NULL DEFAULT now(),
			updated_at   timestamptz NOT NULL DEFAULT now(),
			CONSTRAINT nj_670_entities_class_chk CHECK (class_type IN
				('role', 'system', 'artifact', 'event', 'metric', 'rule', 'department')),
			CONSTRAINT nj_670_entities_code_uniq UNIQUE (workspace_id, class_type, code)
		)");

		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_entities_ws_idx
			ON nj_670_entities (workspace_id, class_type)");

		// 3. Каталог процессов. plan_version_id/fact_version_id — указатели на
		//    актуальный контур; FK не ставим из-за циклической ссылки таблиц.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_670_processes
		(
			id              bigserial PRIMARY KEY,
			workspace_id    bigint NOT NULL REFERENCES nj_670_workspaces (id) ON DELETE CASCADE,
			code            varchar(24) NOT NULL UNIQUE,
			parent_id       bigint NULL REFERENCES nj_670_processes (id) ON DELETE SET NULL,
			title           varchar(255) NOT NULL DEFAULT '',
			description     text NOT NULL DEFAULT '',
			department      varchar(255) NOT NULL DEFAULT '',
			owner_role_id   bigint NULL REFERENCES nj_670_entities (id) ON DELETE SET NULL,
			status          varchar(20) NOT NULL DEFAULT 'active',
			plan_version_id bigint NULL,
			fact_version_id bigint NULL,
			created_by      integer NOT NULL,
			is_demo         boolean NOT NULL DEFAULT false,
			created_at      timestamptz NOT NULL DEFAULT now(),
			updated_at      timestamptz NOT NULL DEFAULT now(),
			CONSTRAINT nj_670_processes_status_chk CHECK (status IN ('active', 'archived'))
		)");

		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_processes_ws_idx
			ON nj_670_processes (workspace_id, updated_at DESC)");

		// 4. Версии процесса: план (To-Be) и факт (As-Is). Топология — jsonb,
		//    синтаксический/семантический индекс — в nj_670_nodes/edges.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_670_versions
		(
			id                     bigserial PRIMARY KEY,
			process_id             bigint NOT NULL REFERENCES nj_670_processes (id) ON DELETE CASCADE,
			kind                   varchar(8) NOT NULL,
			version_tag            varchar(50) NOT NULL DEFAULT '',
			status                 varchar(20) NOT NULL DEFAULT 'draft',
			graph_topology         jsonb NOT NULL DEFAULT '{\"nodes\":[],\"edges\":[],\"lanes\":[]}'::jsonb,
			total_duration_minutes integer NOT NULL DEFAULT 0,
			total_cost_rub         numeric(14, 2) NOT NULL DEFAULT 0,
			source_kind            varchar(20) NOT NULL DEFAULT 'manual',
			source_text            text NOT NULL DEFAULT '',
			metrics                jsonb NOT NULL DEFAULT '{}'::jsonb,
			job_id                 bigint NOT NULL DEFAULT 0,
			model                  varchar(160) NOT NULL DEFAULT '',
			error                  text NOT NULL DEFAULT '',
			created_by             integer NOT NULL DEFAULT 0,
			created_at             timestamptz NOT NULL DEFAULT now(),
			updated_at             timestamptz NOT NULL DEFAULT now(),
			CONSTRAINT nj_670_versions_kind_chk CHECK (kind IN ('plan', 'fact')),
			CONSTRAINT nj_670_versions_status_chk CHECK (status IN
				('draft', 'queued', 'in_review', 'active', 'archived', 'error')),
			CONSTRAINT nj_670_versions_tag_uniq UNIQUE (process_id, kind, version_tag)
		)");

		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_versions_process_idx
			ON nj_670_versions (process_id, kind, created_at DESC)");

		// 5. Семантический индекс операций.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_670_nodes
		(
			id                 bigserial PRIMARY KEY,
			version_id         bigint NOT NULL REFERENCES nj_670_versions (id) ON DELETE CASCADE,
			step_id            varchar(50) NOT NULL,
			node_type          varchar(30) NOT NULL DEFAULT 'user_task',
			title              varchar(255) NOT NULL DEFAULT '',
			lane_id            varchar(60) NOT NULL DEFAULT '',
			role_id            bigint NULL REFERENCES nj_670_entities (id) ON DELETE SET NULL,
			system_id          bigint NULL REFERENCES nj_670_entities (id) ON DELETE SET NULL,
			input_artifact_id  bigint NULL REFERENCES nj_670_entities (id) ON DELETE SET NULL,
			output_artifact_id bigint NULL REFERENCES nj_670_entities (id) ON DELETE SET NULL,
			duration_minutes   integer NOT NULL DEFAULT 0,
			wait_time_minutes  integer NOT NULL DEFAULT 0,
			position_x         integer NOT NULL DEFAULT 0,
			position_y         integer NOT NULL DEFAULT 0,
			properties         jsonb NOT NULL DEFAULT '{}'::jsonb,
			CONSTRAINT nj_670_nodes_step_uniq UNIQUE (version_id, step_id)
		)");

		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_nodes_version_idx
			ON nj_670_nodes (version_id)");

		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_nodes_role_idx
			ON nj_670_nodes (role_id)");

		// 6. Семантические переходы.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_670_edges
		(
			id                   bigserial PRIMARY KEY,
			version_id           bigint NOT NULL REFERENCES nj_670_versions (id) ON DELETE CASCADE,
			source_step_id       varchar(50) NOT NULL,
			target_step_id       varchar(50) NOT NULL,
			transition_type      varchar(20) NOT NULL DEFAULT 'sequence',
			condition_expression text NOT NULL DEFAULT '',
			weight               numeric(12, 2) NOT NULL DEFAULT 1,
			avg_minutes          integer NOT NULL DEFAULT 0,
			properties           jsonb NOT NULL DEFAULT '{}'::jsonb,
			CONSTRAINT nj_670_edges_uniq UNIQUE (version_id, source_step_id, target_step_id)
		)");

		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_edges_version_idx
			ON nj_670_edges (version_id)");

		// 7. Импорт журналов событий (CSV/XLSX) и текстовых источников.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_670_imports
		(
			id          bigserial PRIMARY KEY,
			process_id  bigint NOT NULL REFERENCES nj_670_processes (id) ON DELETE CASCADE,
			user_id     integer NOT NULL,
			kind        varchar(12) NOT NULL DEFAULT 'csv',
			filename    varchar(255) NOT NULL DEFAULT '',
			stored_path varchar(255) NOT NULL DEFAULT '',
			size_bytes  bigint NOT NULL DEFAULT 0,
			rows_count  integer NOT NULL DEFAULT 0,
			status      varchar(20) NOT NULL DEFAULT 'uploaded',
			error       text NOT NULL DEFAULT '',
			job_id      bigint NOT NULL DEFAULT 0,
			created_at  timestamptz NOT NULL DEFAULT now(),
			updated_at  timestamptz NOT NULL DEFAULT now(),
			CONSTRAINT nj_670_imports_kind_chk CHECK (kind IN ('csv', 'xlsx', 'text')),
			CONSTRAINT nj_670_imports_status_chk CHECK (status IN
				('uploaded', 'queued', 'done', 'error'))
		)");

		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_imports_process_idx
			ON nj_670_imports (process_id, created_at DESC)");

		// 8. Результаты AI-аудита: разрывы плана и факта.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_670_audits
		(
			id                 bigserial PRIMARY KEY,
			process_id         bigint NOT NULL REFERENCES nj_670_processes (id) ON DELETE CASCADE,
			plan_version_id    bigint NOT NULL REFERENCES nj_670_versions (id) ON DELETE CASCADE,
			fact_version_id    bigint NOT NULL REFERENCES nj_670_versions (id) ON DELETE CASCADE,
			status             varchar(20) NOT NULL DEFAULT 'queued',
			summary            text NOT NULL DEFAULT '',
			findings           jsonb NOT NULL DEFAULT '[]'::jsonb,
			total_impact_rub   numeric(14, 2) NOT NULL DEFAULT 0,
			risk_level         varchar(20) NOT NULL DEFAULT '',
			model              varchar(160) NOT NULL DEFAULT '',
			mode               varchar(20) NOT NULL DEFAULT 'rules',
			job_id             bigint NOT NULL DEFAULT 0,
			error              text NOT NULL DEFAULT '',
			created_by         integer NOT NULL DEFAULT 0,
			created_at         timestamptz NOT NULL DEFAULT now(),
			updated_at         timestamptz NOT NULL DEFAULT now(),
			CONSTRAINT nj_670_audits_status_chk CHECK (status IN ('queued', 'done', 'error'))
		)");

		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_audits_process_idx
			ON nj_670_audits (process_id, created_at DESC)");

		// 9. Сформированные регламенты (DOCX/PDF) и их файлы.
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_670_reports
		(
			id            bigserial PRIMARY KEY,
			process_id    bigint NOT NULL REFERENCES nj_670_processes (id) ON DELETE CASCADE,
			version_id    bigint NOT NULL REFERENCES nj_670_versions (id) ON DELETE CASCADE,
			audit_id      bigint NULL REFERENCES nj_670_audits (id) ON DELETE SET NULL,
			status        varchar(20) NOT NULL DEFAULT 'queued',
			docx_path     varchar(255) NOT NULL DEFAULT '',
			docx_size     bigint NOT NULL DEFAULT 0,
			pdf_path      varchar(255) NOT NULL DEFAULT '',
			pdf_size      bigint NOT NULL DEFAULT 0,
			error         text NOT NULL DEFAULT '',
			job_id        bigint NOT NULL DEFAULT 0,
			model         varchar(160) NOT NULL DEFAULT '',
			created_by    integer NOT NULL DEFAULT 0,
			created_at    timestamptz NOT NULL DEFAULT now(),
			updated_at    timestamptz NOT NULL DEFAULT now(),
			CONSTRAINT nj_670_reports_status_chk CHECK (status IN ('queued', 'done', 'error'))
		)");

		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_reports_process_idx
			ON nj_670_reports (process_id, created_at DESC)");

		// 10. Журнал действий модуля (кто и что менял; не заменяет общий лог).
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_670_events
		(
			id         bigserial PRIMARY KEY,
			process_id bigint NULL REFERENCES nj_670_processes (id) ON DELETE CASCADE,
			user_id    integer NOT NULL DEFAULT 0,
			kind       varchar(40) NOT NULL,
			message    text NOT NULL DEFAULT '',
			payload    jsonb NOT NULL DEFAULT '{}'::jsonb,
			created_at timestamptz NOT NULL DEFAULT now()
		)");

		$this->run_query("CREATE INDEX IF NOT EXISTS nj_670_events_process_idx
			ON nj_670_events (process_id, created_at DESC)");
	}

	public function down()
	{
		$this->run_query("DROP TABLE IF EXISTS nj_670_events");
		$this->run_query("DROP TABLE IF EXISTS nj_670_reports");
		$this->run_query("DROP TABLE IF EXISTS nj_670_audits");
		$this->run_query("DROP TABLE IF EXISTS nj_670_imports");
		$this->run_query("DROP TABLE IF EXISTS nj_670_edges");
		$this->run_query("DROP TABLE IF EXISTS nj_670_nodes");
		$this->run_query("DROP TABLE IF EXISTS nj_670_versions");
		$this->run_query("DROP TABLE IF EXISTS nj_670_processes");
		$this->run_query("DROP TABLE IF EXISTS nj_670_entities");
		$this->run_query("DROP TABLE IF EXISTS nj_670_workspaces");
	}
}
