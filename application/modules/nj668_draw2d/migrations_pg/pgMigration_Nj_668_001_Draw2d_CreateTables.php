<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj668_draw2d 001: проекты плоских иллюстраций.
 *
 * Сцена целиком в jsonb (shapes: rect/circle/ellipse/poly/line/cubic/path).
 * SVG считается на лету, файлов на диске нет.
 */
class pgMigration_Nj_668_001_Draw2d_CreateTables extends pgMigration
{
	public function up()
	{
		$this->run_query('CREATE TABLE IF NOT EXISTS nj_668_projects (
			id              BIGSERIAL     PRIMARY KEY,
			code            VARCHAR(24)   NOT NULL UNIQUE,
			user_id         INTEGER       NOT NULL DEFAULT 0,
			visitor_uid     VARCHAR(40)   NOT NULL DEFAULT \'\',
			title           VARCHAR(200)  NOT NULL DEFAULT \'\',
			summary         VARCHAR(1000) NOT NULL DEFAULT \'\',
			tags            VARCHAR(300)  NOT NULL DEFAULT \'\',
			kind            VARCHAR(16)   NOT NULL DEFAULT \'private\',
			status          VARCHAR(16)   NOT NULL DEFAULT \'draft\',
			featured        SMALLINT      NOT NULL DEFAULT 0,
			scene_json      JSONB         NULL,
			prompt          VARCHAR(800)  NOT NULL DEFAULT \'\',
			shapes_count    INTEGER       NOT NULL DEFAULT 0,
			model           VARCHAR(160)  NOT NULL DEFAULT \'\',
			error           VARCHAR(500)  NOT NULL DEFAULT \'\',
			remix_of        BIGINT        NOT NULL DEFAULT 0,
			remixes         INTEGER       NOT NULL DEFAULT 0,
			views           INTEGER       NOT NULL DEFAULT 0,
			job_id          BIGINT        NOT NULL DEFAULT 0,
			created_at      TIMESTAMPTZ   NOT NULL DEFAULT now(),
			updated_at      TIMESTAMPTZ   NOT NULL DEFAULT now(),
			deleted_at      TIMESTAMPTZ   NULL
		)');

		$this->run_query('CREATE INDEX IF NOT EXISTS nj_668_projects_code_idx
			ON nj_668_projects (code)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_668_projects_user_idx
			ON nj_668_projects (user_id, id DESC)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_668_projects_uid_idx
			ON nj_668_projects (visitor_uid, id DESC)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_668_projects_status_idx
			ON nj_668_projects (status, updated_at DESC)');
		$this->run_query('COMMENT ON TABLE nj_668_projects IS
			\'NJ-668 плоские иллюстрации: векторная сцена в jsonb, status draft|published\'');
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS nj_668_projects');
	}
}
