<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Суточная квота списывается только за запуски, где вызывается языковая модель.
 *
 * llm_run = FALSE у ревизий без модели: ручной пересчёт кода модератором и пересчёт
 * размеров (code_only). Существующие строки получают TRUE: до этой миграции любой
 * запуск считался как запуск с моделью, и менять историю квоты задним числом не нужно.
 */
class pgMigration_Nj_669_005_LlmRun extends pgMigration
{
	public function up()
	{
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS llm_run BOOLEAN NOT NULL DEFAULT TRUE');
	}

	public function down()
	{
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions DROP COLUMN IF EXISTS llm_run');
	}
}
