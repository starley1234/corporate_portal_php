<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 011: сборка можно опубликовать (владелец, 14.10.2026).
 *
 * published_at — когда администратор опубликовал сборку; NULL — не опубликована.
 * Опубликованная сборка показывается в каталоге, публикует граф и защищена от
 * порционной очистки файлов на /graph3d/settings. Одна опубликованная сборка на граф.
 */
class pgMigration_Nj_667_011_BuildPublish extends pgMigration
{
	public function up()
	{
		$this->run_query('ALTER TABLE nj_667_builds
			ADD COLUMN IF NOT EXISTS published_at TIMESTAMPTZ NULL,
			ADD COLUMN IF NOT EXISTS published_by INTEGER NOT NULL DEFAULT 0');

		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_builds_published_idx
			ON nj_667_builds (project_id, published_at DESC)
			WHERE published_at IS NOT NULL');

		$this->run_query("COMMENT ON COLUMN nj_667_builds.published_at IS
			'когда администратор опубликовал сборку; NULL — не опубликована'");

		// Статус «published» — сборка готова и опубликована (published_at задан).
		$this->run_query("COMMENT ON COLUMN nj_667_builds.status IS
			'spec — есть спецификация; queued — ждёт рендера; done — модель готова; published — модель готова и опубликована; error — рендер не удался'");
	}

	public function down()
	{
		$this->run_query('DROP INDEX IF EXISTS nj_667_builds_published_idx');
		$this->run_query('ALTER TABLE nj_667_builds
			DROP COLUMN IF EXISTS published_by,
			DROP COLUMN IF EXISTS published_at');
	}
}
