<?php defined('SYSPATH') or die('No direct script access.');

/** Продолжение рабочего места: карточки-снимки, контрольные точки и помощники. */
class pgMigration_Nj_669_002_Workspace extends pgMigration
{
	public function up()
	{
		$this->run_query("ALTER TABLE nj_669_solid3d_projects ADD COLUMN IF NOT EXISTS tags VARCHAR(300) NOT NULL DEFAULT ''");
		$this->run_query("ALTER TABLE nj_669_solid3d_projects ADD COLUMN IF NOT EXISTS published_meta JSONB NOT NULL DEFAULT '{}'");
		$this->run_query("ALTER TABLE nj_669_solid3d_projects ADD COLUMN IF NOT EXISTS review_meta JSONB NOT NULL DEFAULT '{}'");
		$this->run_query("UPDATE nj_669_solid3d_projects SET published_meta=jsonb_build_object('title',title,'summary',summary,'tags',tags) WHERE published_revision_id>0 AND published_meta='{}'::jsonb");
		$this->run_query("UPDATE nj_669_solid3d_projects SET review_meta=jsonb_build_object('title',title,'summary',summary,'tags',tags) WHERE review_revision_id>0 AND review_meta='{}'::jsonb");
		$this->run_query("ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS label VARCHAR(160) NOT NULL DEFAULT ''");
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS milestone BOOLEAN NOT NULL DEFAULT false');
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_669_solid3d_tasks (
			id BIGSERIAL PRIMARY KEY, kind VARCHAR(16) NOT NULL CHECK (kind IN ('ideas','caption')),
			project_id BIGINT NULL REFERENCES nj_669_solid3d_projects(id) ON DELETE CASCADE,
			user_id INTEGER NOT NULL, job_id BIGINT NOT NULL DEFAULT 0, run_token VARCHAR(48) NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','running','done','error')),
			model VARCHAR(200) NOT NULL DEFAULT '', context JSONB NOT NULL DEFAULT '{}',
			result JSONB NOT NULL DEFAULT '{}', error VARCHAR(1000) NOT NULL DEFAULT '',
			created_at TIMESTAMPTZ NOT NULL DEFAULT now(), finished_at TIMESTAMPTZ NULL)");
		$this->run_query("CREATE INDEX IF NOT EXISTS nj_669_solid3d_tasks_pending_idx ON nj_669_solid3d_tasks (created_at) WHERE status IN ('queued','running')");
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_669_solid3d_tasks_project_idx ON nj_669_solid3d_tasks (project_id,id DESC)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_669_solid3d_tasks_usage_idx ON nj_669_solid3d_tasks (user_id,created_at)');
		$this->run_query("CREATE TABLE IF NOT EXISTS nj_669_solid3d_ideas (
			id BIGSERIAL PRIMARY KEY, task_id BIGINT NULL REFERENCES nj_669_solid3d_tasks(id) ON DELETE SET NULL,
			theme VARCHAR(200) NOT NULL DEFAULT '', body VARCHAR(600) NOT NULL,
			created_at TIMESTAMPTZ NOT NULL DEFAULT now())");
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS nj_669_solid3d_ideas');
		$this->run_query('DROP TABLE IF EXISTS nj_669_solid3d_tasks');
		foreach (['label','milestone'] as $column) { $this->run_query('ALTER TABLE nj_669_solid3d_revisions DROP COLUMN IF EXISTS '.$column); }
		foreach (['tags','published_meta','review_meta'] as $column) { $this->run_query('ALTER TABLE nj_669_solid3d_projects DROP COLUMN IF EXISTS '.$column); }
	}
}
