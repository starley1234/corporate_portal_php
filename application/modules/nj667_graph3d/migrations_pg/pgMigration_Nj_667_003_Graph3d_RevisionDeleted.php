<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 003: мягкое удаление ревизии.
 *
 * Повод (10.10.2026, владелец): ревизию можно убрать из списка маленькой
 * иконкой, не ломая накатку истории. deleted_at прячет строку с экрана,
 * replay по-прежнему видит все коммиты — иначе дыра в цепочке патчей.
 */
class pgMigration_Nj_667_003_Graph3d_RevisionDeleted extends pgMigration
{
	public function up()
	{
		$this->run_query('ALTER TABLE nj_667_revisions
			ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_revisions_live_idx
			ON nj_667_revisions (project_id, no DESC)
			WHERE deleted_at IS NULL');
		$this->run_query('COMMENT ON COLUMN nj_667_revisions.deleted_at IS
			\'мягкое удаление: NULL — видна в списке, иначе спрятана; накатка истории её всё равно берёт\'');
	}

	public function down()
	{
		$this->run_query('DROP INDEX IF EXISTS nj_667_revisions_live_idx');
		$this->run_query('ALTER TABLE nj_667_revisions DROP COLUMN IF EXISTS deleted_at');
	}
}
