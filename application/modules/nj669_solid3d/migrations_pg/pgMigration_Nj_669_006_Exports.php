<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Временные STL под свои размеры (ползунки без записи в версию).
 *
 * Отдельная таблица, а не строки ревизий: сборка не попадает в историю версий, не меняет
 * каталог и не списывает квоту. Файл лежит час после готовности (expires_at), затем удаляется
 * вместе со строкой. code хранится здесь же, чтобы чистка не зависела от состояния проекта.
 */
class pgMigration_Nj_669_006_Exports extends pgMigration
{
	public function up()
	{
		$this->run_query('CREATE TABLE IF NOT EXISTS nj_669_solid3d_exports (
			id BIGSERIAL PRIMARY KEY,
			code VARCHAR(20) NOT NULL,
			project_id BIGINT NOT NULL,
			revision_id BIGINT NOT NULL,
			user_id BIGINT NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT \'queued\' CHECK (status IN (\'queued\',\'running\',\'done\',\'error\')),
			run_token VARCHAR(64) NOT NULL,
			job_id BIGINT NOT NULL DEFAULT 0,
			params JSONB NOT NULL DEFAULT \'{}\'::jsonb,
			source TEXT NOT NULL DEFAULT \'\',
			error TEXT NOT NULL DEFAULT \'\',
			detail TEXT NOT NULL DEFAULT \'\',
			size_bytes BIGINT NOT NULL DEFAULT 0,
			created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
			finished_at TIMESTAMPTZ NULL,
			expires_at TIMESTAMPTZ NOT NULL
		)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_669_solid3d_exports_user ON nj_669_solid3d_exports (project_id, user_id, id)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_669_solid3d_exports_expires ON nj_669_solid3d_exports (expires_at)');
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS nj_669_solid3d_exports');
	}
}
