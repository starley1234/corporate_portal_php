<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Расход токенов языковой модели по запускам: видно, на что уходит ресурс.
 *
 * revisions.tokens_* — генерация версии (описание фото, код, повторы сборки);
 * revisions.vlm_tokens_* — ИИ-проверка ракурсов (только по запросу администратора);
 * tasks.tokens_* — помощники: идеи и карточка. Существующие строки получают 0:
 * до этой миграции расход не считался, задним числом его не восстановить.
 */
class pgMigration_Nj_669_007_Tokens extends pgMigration
{
	public function up()
	{
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS tokens_in INTEGER NOT NULL DEFAULT 0');
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS tokens_out INTEGER NOT NULL DEFAULT 0');
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS vlm_tokens_in INTEGER NOT NULL DEFAULT 0');
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS vlm_tokens_out INTEGER NOT NULL DEFAULT 0');
		$this->run_query('ALTER TABLE nj_669_solid3d_tasks ADD COLUMN IF NOT EXISTS tokens_in INTEGER NOT NULL DEFAULT 0');
		$this->run_query('ALTER TABLE nj_669_solid3d_tasks ADD COLUMN IF NOT EXISTS tokens_out INTEGER NOT NULL DEFAULT 0');
	}

	public function down()
	{
		$this->run_query('ALTER TABLE nj_669_solid3d_tasks DROP COLUMN IF EXISTS tokens_out');
		$this->run_query('ALTER TABLE nj_669_solid3d_tasks DROP COLUMN IF EXISTS tokens_in');
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions DROP COLUMN IF EXISTS vlm_tokens_out');
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions DROP COLUMN IF EXISTS vlm_tokens_in');
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions DROP COLUMN IF EXISTS tokens_out');
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions DROP COLUMN IF EXISTS tokens_in');
	}
}
