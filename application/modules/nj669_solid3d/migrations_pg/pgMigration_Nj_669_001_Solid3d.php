<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_Nj_669_001_Solid3d extends pgMigration
{
	public function up()
	{
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_669_solid3d_projects (
			id BIGSERIAL PRIMARY KEY, code VARCHAR(20) UNIQUE NOT NULL,
			user_id INTEGER NOT NULL, title VARCHAR(200) NOT NULL,
			summary VARCHAR(2000) NOT NULL DEFAULT '', photo VARCHAR(8) NOT NULL DEFAULT '',
			current_revision_id BIGINT NOT NULL DEFAULT 0,
			published_revision_id BIGINT NOT NULL DEFAULT 0,
			review_revision_id BIGINT NOT NULL DEFAULT 0,
			publication VARCHAR(16) NOT NULL DEFAULT 'draft'
				CHECK (publication IN ('draft','review','published','rejected')),
			moderation_note VARCHAR(1000) NOT NULL DEFAULT '', remix_of BIGINT NOT NULL DEFAULT 0,
			created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
			deleted_at TIMESTAMPTZ NULL
		)");
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_669_solid3d_revisions (
			id BIGSERIAL PRIMARY KEY, project_id BIGINT NOT NULL REFERENCES nj_669_solid3d_projects(id) ON DELETE CASCADE,
			user_id INTEGER NOT NULL, parent_id BIGINT NOT NULL DEFAULT 0,
			prompt VARCHAR(6000) NOT NULL, source TEXT NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','running','done','error')),
			job_id BIGINT NOT NULL DEFAULT 0, run_token VARCHAR(48) NOT NULL,
			model VARCHAR(200) NOT NULL DEFAULT '', vision_model VARCHAR(200) NOT NULL DEFAULT '',
			visual_ok BOOLEAN NOT NULL DEFAULT false, review VARCHAR(2000) NOT NULL DEFAULT '',
			error VARCHAR(1000) NOT NULL DEFAULT '', attempts SMALLINT NOT NULL DEFAULT 0,
			files JSONB NOT NULL DEFAULT '{}', created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
			finished_at TIMESTAMPTZ NULL
		)");
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_669_solid3d_owner_idx ON nj_669_solid3d_projects (user_id, id DESC) WHERE deleted_at IS NULL');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_669_solid3d_revision_idx ON nj_669_solid3d_revisions (project_id, id DESC)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_669_solid3d_usage_idx ON nj_669_solid3d_revisions (user_id, created_at)');
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_669_solid3d_settings (key VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL)");
		$this->run_query('CREATE TABLE IF NOT EXISTS nj_669_solid3d_quotas (user_id INTEGER PRIMARY KEY, projects INTEGER NULL CHECK (projects >= 0), daily_runs INTEGER NULL CHECK (daily_runs >= 0))');
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_669_solid3d_events (id BIGSERIAL PRIMARY KEY,
			project_id BIGINT NOT NULL REFERENCES nj_669_solid3d_projects(id) ON DELETE CASCADE,
			user_id INTEGER NOT NULL, kind VARCHAR(24) NOT NULL, note VARCHAR(1000) NOT NULL DEFAULT '',
			created_at TIMESTAMPTZ NOT NULL DEFAULT now())");
	}

	public function down()
	{
		foreach (['events', 'quotas', 'settings', 'revisions', 'projects'] as $table)
		{
			$this->run_query('DROP TABLE IF EXISTS nj_669_solid3d_'.$table);
		}
	}
}
