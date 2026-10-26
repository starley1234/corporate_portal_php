<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция ERP-222 «Кузница систем»: миры, роли, артефакты, связи, циклы, лента.
 *
 * Что перенесено из наработок source_data/erp222_aeterna_forge и что изменено:
 *   · Universal Store (erp_222_entities с типом в поле) разложен на понятные
 *     таблицы: мир, его роли-агенты, его артефакты и связи между артефактами.
 *     Причина: «сущность любого типа в одной таблице» прячет схему артефакта, а
 *     ценность сервиса — именно в артефактах, у которых есть заголовок, сводка,
 *     тело, метаданные, оценки и публикация.
 *   · Вектора (pgvector 1024) в схеме НЕТ. Наработки требовали расширения
 *     vector в public-схеме и падали «current transaction is aborted» на
 *     несовпадении размерности (разбор — в source_data/…/manual.md). Новизна и
 *     близость артефактов считаются триграммами (pg_trgm, если доступен) и
 *     сравнением слов — без внешней зависимости и без падений.
 *   · Evolution Log заменён полем scores у артефакта + сохранённой критикой:
 *     история оценок видна прямо на странице артефакта.
 *
 * Идемпотентность: все CREATE с IF NOT EXISTS — повторный накат безопасен.
 */
class pgMigration_Erp222_001_Forge_CreateTables extends pgMigration {

	public function up()
	{
		// 1. Миры: что синтезируем (бывшие erp_222_systems).
		$this->run_query("CREATE TABLE IF NOT EXISTS erp_222_worlds (
			id            bigserial PRIMARY KEY,
			code          varchar(64) NOT NULL,
			title         varchar(200) NOT NULL DEFAULT '',
			brief         text NOT NULL DEFAULT '',
			protocol      text NOT NULL DEFAULT '',
			goal          text NOT NULL DEFAULT '',
			domain        varchar(64) NOT NULL DEFAULT '',
			status        varchar(20) NOT NULL DEFAULT 'active',
			model         varchar(128) NOT NULL DEFAULT '',
			steps_done    integer NOT NULL DEFAULT 0,
			artifacts_done integer NOT NULL DEFAULT 0,
			value_total   integer NOT NULL DEFAULT 0,
			owner_id      integer NOT NULL DEFAULT 1,
			created_at    timestamptz NOT NULL DEFAULT now(),
			updated_at    timestamptz NOT NULL DEFAULT now(),
			CONSTRAINT erp_222_worlds_code_key UNIQUE (code)
		)");

		$this->run_script("
			COMMENT ON TABLE erp_222_worlds IS 'Миры синтеза: что именно проектируем (бывшие erp_222_systems)';
			COMMENT ON COLUMN erp_222_worlds.protocol IS 'Правила мира: какие артефакты выпускаем, что считаем ценным';
			COMMENT ON COLUMN erp_222_worlds.value_total IS 'Суммарная ценность выпущенных артефактов (для сортировки миров)';
			CREATE INDEX IF NOT EXISTS erp_222_worlds_status_idx ON erp_222_worlds (status, updated_at DESC);
			CREATE INDEX IF NOT EXISTS erp_222_worlds_owner_idx ON erp_222_worlds (owner_id);
		");

		// 2. Роли мира: кто выпускает артефакты (в наработках — AGENT-сущности).
		$this->run_query("CREATE TABLE IF NOT EXISTS erp_222_roles (
			id         bigserial PRIMARY KEY,
			world_id   bigint NOT NULL,
			code       varchar(40) NOT NULL,
			title      varchar(80) NOT NULL DEFAULT '',
			focus      text NOT NULL DEFAULT '',
			types      varchar(200) NOT NULL DEFAULT '',
			model      varchar(128) NOT NULL DEFAULT '',
			sort       integer NOT NULL DEFAULT 0,
			enabled    boolean NOT NULL DEFAULT TRUE,
			created_at timestamptz NOT NULL DEFAULT now(),
			CONSTRAINT erp_222_roles_world_code_key UNIQUE (world_id, code)
		)");

		$this->run_script("
			COMMENT ON COLUMN erp_222_roles.types IS 'Какие типы артефактов выпускает роль, через запятую';
			CREATE INDEX IF NOT EXISTS erp_222_roles_world_idx ON erp_222_roles (world_id, sort);
		");

		// 3. Артефакты — то, ради чего сервис существует.
		$this->run_query("CREATE TABLE IF NOT EXISTS erp_222_artifacts (
			id           bigserial PRIMARY KEY,
			world_id     bigint NOT NULL,
			run_id       bigint NOT NULL DEFAULT 0,
			role_code    varchar(40) NOT NULL DEFAULT '',
			type         varchar(20) NOT NULL,
			title        varchar(240) NOT NULL DEFAULT '',
			summary      text NOT NULL DEFAULT '',
			body         text NOT NULL DEFAULT '',
			metadata     text NOT NULL DEFAULT '{}',
			depends_on   text NOT NULL DEFAULT '[]',
			sources      text NOT NULL DEFAULT '[]',
			scores       text NOT NULL DEFAULT '{}',
			value_score  integer NOT NULL DEFAULT 0,
			status       varchar(20) NOT NULL DEFAULT 'draft',
			critique     text NOT NULL DEFAULT '',
			novelty_hash varchar(64) NOT NULL DEFAULT '',
			published    boolean NOT NULL DEFAULT TRUE,
			created_at   timestamptz NOT NULL DEFAULT now()
		)");

		$this->run_script("
			COMMENT ON TABLE erp_222_artifacts IS 'Артефакты синтеза: спецификации, схемы, API, промпты, риски, оценки…';
			COMMENT ON COLUMN erp_222_artifacts.metadata IS 'JSON: входы, выходы, допущения, открытые вопросы';
			COMMENT ON COLUMN erp_222_artifacts.scores IS 'JSON: оценки ролей (usefulness, concreteness, novelty, feasibility)';
			COMMENT ON COLUMN erp_222_artifacts.value_score IS 'Ценность 0..100: свёртка оценок по весам конфига';
			COMMENT ON COLUMN erp_222_artifacts.novelty_hash IS 'Отпечаток заголовка+сводки: ловит повтор шага';
			COMMENT ON COLUMN erp_222_artifacts.published IS 'Опубликован в каталоге по умолчанию; владелец может снять';
			CREATE INDEX IF NOT EXISTS erp_222_artifacts_world_idx ON erp_222_artifacts (world_id, id DESC);
			CREATE INDEX IF NOT EXISTS erp_222_artifacts_type_idx ON erp_222_artifacts (type);
			CREATE INDEX IF NOT EXISTS erp_222_artifacts_catalog_idx ON erp_222_artifacts (published, value_score DESC, id DESC);
			CREATE INDEX IF NOT EXISTS erp_222_artifacts_hash_idx ON erp_222_artifacts (novelty_hash);
		");

		// 4. Связи артефактов: опора одного на другой (бывшие semantic_links).
		$this->run_query("CREATE TABLE IF NOT EXISTS erp_222_links (
			id         bigserial PRIMARY KEY,
			world_id   bigint NOT NULL,
			source_id  bigint NOT NULL,
			target_id  bigint NOT NULL,
			link_type  varchar(40) NOT NULL DEFAULT 'refines',
			note       varchar(240) NOT NULL DEFAULT '',
			created_at timestamptz NOT NULL DEFAULT now(),
			CONSTRAINT erp_222_links_pair_key UNIQUE (source_id, target_id, link_type)
		)");

		$this->run_script("
			COMMENT ON COLUMN erp_222_links.link_type IS 'refines, depends_on, contradicts, implements';
			CREATE INDEX IF NOT EXISTS erp_222_links_source_idx ON erp_222_links (source_id);
			CREATE INDEX IF NOT EXISTS erp_222_links_target_idx ON erp_222_links (target_id);
		");

		// 5. Циклы синтеза (прогоны) — по образцу nj_661_runs: сайт ставит задачу
		// в очередь nj120, воркер ведёт шаги, страница поллит прогресс.
		$this->run_query("CREATE TABLE IF NOT EXISTS erp_222_runs (
			id            bigserial PRIMARY KEY,
			world_id      bigint NOT NULL,
			job_id        bigint NOT NULL DEFAULT 0,
			trigger       varchar(20) NOT NULL DEFAULT 'manual',
			status        varchar(20) NOT NULL DEFAULT 'new',
			stage         varchar(60) NOT NULL DEFAULT '',
			stage_note    varchar(400) NOT NULL DEFAULT '',
			step_index    integer NOT NULL DEFAULT 0,
			steps_planned integer NOT NULL DEFAULT 0,
			steps_done    integer NOT NULL DEFAULT 0,
			role_code     varchar(40) NOT NULL DEFAULT '',
			artifacts_new integer NOT NULL DEFAULT 0,
			stats         text NOT NULL DEFAULT '{}',
			error         text NOT NULL DEFAULT '',
			created_at    timestamptz NOT NULL DEFAULT now(),
			finished_at   timestamptz DEFAULT NULL
		)");

		$this->run_script("
			COMMENT ON TABLE erp_222_runs IS 'Циклы синтеза: один цикл = N шагов LLM, каждый шаг рождает артефакт';
			COMMENT ON COLUMN erp_222_runs.stage IS 'Этап для живого хода: Роли → Синтез → Критика → Сводка';
			CREATE INDEX IF NOT EXISTS erp_222_runs_world_idx ON erp_222_runs (world_id, id DESC);
			CREATE INDEX IF NOT EXISTS erp_222_runs_live_idx ON erp_222_runs (status, id DESC);
		");

		// 6. Лента событий цикла: «что происходит» — этапы, шаги, критика, ошибки.
		$this->run_query("CREATE TABLE IF NOT EXISTS erp_222_events (
			id         bigserial PRIMARY KEY,
			world_id   bigint NOT NULL DEFAULT 0,
			run_id     bigint NOT NULL DEFAULT 0,
			role_code  varchar(40) NOT NULL DEFAULT '',
			kind       varchar(30) NOT NULL DEFAULT 'step',
			message    varchar(500) NOT NULL DEFAULT '',
			payload    text NOT NULL DEFAULT '{}',
			created_at timestamptz NOT NULL DEFAULT now()
		)");

		$this->run_script("
			COMMENT ON COLUMN erp_222_events.kind IS 'step, artifact, critique, note, error, run';
			CREATE INDEX IF NOT EXISTS erp_222_events_run_idx ON erp_222_events (run_id, id DESC);
			CREATE INDEX IF NOT EXISTS erp_222_events_world_idx ON erp_222_events (world_id, id DESC);
		");
	}

	public function down()
	{
		// Обратный ход: сначала лента и циклы, потом артефакты и миры — как в накате.
		$this->run_script("
			DROP TABLE IF EXISTS erp_222_events CASCADE;
			DROP TABLE IF EXISTS erp_222_runs CASCADE;
			DROP TABLE IF EXISTS erp_222_links CASCADE;
			DROP TABLE IF EXISTS erp_222_artifacts CASCADE;
			DROP TABLE IF EXISTS erp_222_roles CASCADE;
			DROP TABLE IF EXISTS erp_222_worlds CASCADE;
		");
	}
}
