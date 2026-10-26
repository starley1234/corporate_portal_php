<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 013: фиксированное окно одной подготовки деталей.
 *
 * Одно нажатие может породить несколько worker-задач из-за короткого бюджета
 * рендера. Храним номер запуска на сборке и на каждой выбранной детали, чтобы
 * автопродолжение повторяло только его набор и не расширяло лимит 30.
 */
class pgMigration_Nj_667_013_Graph3d_PartsRun extends pgMigration
{
	public function up()
	{
		$this->run_query('ALTER TABLE nj_667_builds
			ADD COLUMN IF NOT EXISTS parts_run_id BIGINT NOT NULL DEFAULT 0');

		$this->run_query('ALTER TABLE nj_667_build_parts
			ADD COLUMN IF NOT EXISTS parts_run_id BIGINT NOT NULL DEFAULT 0');

		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_build_parts_run_status_idx
			ON nj_667_build_parts (build_id, parts_run_id, status)');

		$this->run_query('COMMENT ON COLUMN nj_667_builds.parts_run_id IS
			\'последний номер запуска подготовки деталей; автопродолжение сохраняет номер\'');
		$this->run_query('COMMENT ON COLUMN nj_667_build_parts.parts_run_id IS
			\'номер запуска, в набор которого входит эта деталь; ограничивает автопродолжение\'');
	}

	public function down()
	{
		$this->run_query('DROP INDEX IF EXISTS nj_667_build_parts_run_status_idx');
		$this->run_query('ALTER TABLE nj_667_build_parts DROP COLUMN IF EXISTS parts_run_id');
		$this->run_query('ALTER TABLE nj_667_builds DROP COLUMN IF EXISTS parts_run_id');
	}
}
