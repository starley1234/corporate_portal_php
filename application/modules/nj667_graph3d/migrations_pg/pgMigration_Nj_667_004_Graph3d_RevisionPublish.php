<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 004: модерация и каталог — по ревизии, не по проекту.
 *
 * Повод (11.10.2026, владелец): отправили на модерацию снимок изделия.
 * Дальше человек правит рабочую копию — это уже другое. Опубликовать можно
 * и другую ревизию. status/title/summary/tags живут на ревизии; проект
 * остаётся мастерской.
 *
 * Уже опубликованные проекты получают milestone со status=published, чтобы
 * каталог не опустел в ночь накатки.
 */
class pgMigration_Nj_667_004_Graph3d_RevisionPublish extends pgMigration
{
	public function up()
	{
		$this->run_query('ALTER TABLE nj_667_revisions
			ADD COLUMN IF NOT EXISTS status VARCHAR(16) NOT NULL DEFAULT \'\'');
		$this->run_query('ALTER TABLE nj_667_revisions
			ADD COLUMN IF NOT EXISTS title VARCHAR(200) NOT NULL DEFAULT \'\'');
		$this->run_query('ALTER TABLE nj_667_revisions
			ADD COLUMN IF NOT EXISTS summary VARCHAR(1000) NOT NULL DEFAULT \'\'');
		$this->run_query('ALTER TABLE nj_667_revisions
			ADD COLUMN IF NOT EXISTS tags VARCHAR(300) NOT NULL DEFAULT \'\'');
		$this->run_query('ALTER TABLE nj_667_revisions
			ADD COLUMN IF NOT EXISTS moderation_note VARCHAR(500) NOT NULL DEFAULT \'\'');
		$this->run_query('ALTER TABLE nj_667_revisions
			ADD COLUMN IF NOT EXISTS reviewed_by INTEGER NOT NULL DEFAULT 0');
		$this->run_query('ALTER TABLE nj_667_revisions
			ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMPTZ NULL');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_revisions_status_idx
			ON nj_667_revisions (status, created_at DESC)
			WHERE status <> \'\'');
		$this->run_query('CREATE UNIQUE INDEX IF NOT EXISTS nj_667_revisions_one_review_idx
			ON nj_667_revisions (project_id)
			WHERE status = \'review\' AND deleted_at IS NULL');
		$this->run_query('COMMENT ON COLUMN nj_667_revisions.status IS
			\'пусто — обычная правка; review|published|rejected — заявка в каталог\'');

		// Снимок уже опубликованных: каталог читает ревизию, не живой graph_json.
		$this->run_query('INSERT INTO nj_667_revisions
			(project_id, no, kind, label, graph_json, author_kind, status, title, summary, tags)
			SELECT p.id,
				COALESCE((SELECT max(r.no) FROM nj_667_revisions r WHERE r.project_id = p.id), 0) + 1,
				\'milestone\',
				\'опубликовано\',
				p.graph_json,
				\'user\',
				\'published\',
				p.title, p.summary, p.tags
			FROM nj_667_projects p
			WHERE p.status = \'published\'
				AND p.deleted_at IS NULL
				AND NOT EXISTS (
					SELECT 1 FROM nj_667_revisions r
					WHERE r.project_id = p.id AND r.status = \'published\')');

		$this->run_query('INSERT INTO nj_667_revisions
			(project_id, no, kind, label, graph_json, author_kind, status, title, summary, tags)
			SELECT p.id,
				COALESCE((SELECT max(r.no) FROM nj_667_revisions r WHERE r.project_id = p.id), 0) + 1,
				\'milestone\',
				\'на модерации\',
				p.graph_json,
				\'user\',
				\'review\',
				p.title, p.summary, p.tags
			FROM nj_667_projects p
			WHERE p.status = \'review\'
				AND p.deleted_at IS NULL
				AND NOT EXISTS (
					SELECT 1 FROM nj_667_revisions r
					WHERE r.project_id = p.id AND r.status = \'review\')');
	}

	public function down()
	{
		$this->run_query('DROP INDEX IF EXISTS nj_667_revisions_one_review_idx');
		$this->run_query('DROP INDEX IF EXISTS nj_667_revisions_status_idx');
		$this->run_query('ALTER TABLE nj_667_revisions DROP COLUMN IF EXISTS reviewed_at');
		$this->run_query('ALTER TABLE nj_667_revisions DROP COLUMN IF EXISTS reviewed_by');
		$this->run_query('ALTER TABLE nj_667_revisions DROP COLUMN IF EXISTS moderation_note');
		$this->run_query('ALTER TABLE nj_667_revisions DROP COLUMN IF EXISTS tags');
		$this->run_query('ALTER TABLE nj_667_revisions DROP COLUMN IF EXISTS summary');
		$this->run_query('ALTER TABLE nj_667_revisions DROP COLUMN IF EXISTS title');
		$this->run_query('ALTER TABLE nj_667_revisions DROP COLUMN IF EXISTS status');
	}
}
