<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 014: индивидуальные квоты и учёт запросов файлов.
 *
 * `render_kind` сохраняет ожидаемый результат задачи сборки: новые задачи — GLB,
 * STL всего изделия — только по отдельному запросу модератора. Старые строки
 * остаются `legacy`, чтобы завершить уже поставленные задачи по старому правилу.
 */
class pgMigration_Nj_667_014_Graph3d_QuotaAndFiles extends pgMigration
{
	public function up()
	{
		$this->run_query('ALTER TABLE nj_667_builds
			ADD COLUMN IF NOT EXISTS render_kind VARCHAR(8) NOT NULL DEFAULT \'legacy\'');

		$this->run_query('ALTER TABLE nj_667_builds
			ADD CONSTRAINT nj_667_builds_render_kind_check
			CHECK (render_kind IN (\'glb\', \'stl\', \'legacy\'))');

		$this->run_query('ALTER TABLE nj_667_builds ALTER COLUMN render_kind SET DEFAULT \'glb\'');
		$this->run_query('COMMENT ON COLUMN nj_667_builds.render_kind IS
			\'ожидаемый формат текущего рендера: glb — обычная сборка, stl — отдельный запрос модератора, legacy — задача до миграции 014\'');

		$this->run_query('CREATE TABLE IF NOT EXISTS nj_667_quota_overrides (
			user_id                 INTEGER     PRIMARY KEY,
			active_projects         INTEGER     NULL CHECK (active_projects IS NULL OR active_projects >= 0),
			llm_operations_per_day  INTEGER     NULL CHECK (llm_operations_per_day IS NULL OR llm_operations_per_day >= 0),
			updated_by              INTEGER     NOT NULL DEFAULT 0,
			updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
		)');

		$this->run_query('COMMENT ON TABLE nj_667_quota_overrides IS
			\'NJ-667: индивидуальные overrides квот; NULL наследует лимит плана, 0 отключает операцию\'');

		$this->run_query('CREATE TABLE IF NOT EXISTS nj_667_build_file_requests (
			id                BIGSERIAL   PRIMARY KEY,
			user_id           INTEGER     NOT NULL,
			project_id        BIGINT      NOT NULL,
			source_build_id   BIGINT      NOT NULL DEFAULT 0,
			build_id          BIGINT      NOT NULL,
			file_kind         VARCHAR(8)  NOT NULL CHECK (file_kind IN (\'glb\', \'stl\')),
			job_id            BIGINT      NOT NULL,
			requested_at      TIMESTAMPTZ NOT NULL DEFAULT now()
		)');

		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_build_file_requests_user_idx
			ON nj_667_build_file_requests (user_id, requested_at DESC)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_build_file_requests_project_idx
			ON nj_667_build_file_requests (project_id, requested_at DESC)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_build_file_requests_job_idx
			ON nj_667_build_file_requests (job_id)');
		$this->run_query('COMMENT ON TABLE nj_667_build_file_requests IS
			\'NJ-667: принятые в очередь запросы пользователей на GLB/STL; build_id указывает на снимок графа и его параметры\'');
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS nj_667_build_file_requests');
		$this->run_query('DROP TABLE IF EXISTS nj_667_quota_overrides');
		$this->run_query('ALTER TABLE nj_667_builds DROP CONSTRAINT IF EXISTS nj_667_builds_render_kind_check');
		$this->run_query('ALTER TABLE nj_667_builds DROP COLUMN IF EXISTS render_kind');
	}
}
