<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ИИ-проверка ракурсов — отдельный процесс по запросу администратора.
 *
 * Раньше VLM-проверка шла внутри каждой генерации и управляла починками. Теперь
 * генерация её не запускает, а у версии появляется собственное состояние
 * проверки: vlm_status (none → queued → running → done|error), токен и job_id
 * отдельного канала nj669.verify. Результат по-прежнему пишется в visual_ok и
 * review, поэтому уже выполненные автоматические проверки сохраняются как done.
 */
class pgMigration_Nj_669_003_VlmCheck extends pgMigration
{
	public function up()
	{
		$this->run_query("ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS vlm_status VARCHAR(16) NOT NULL DEFAULT 'none' CHECK (vlm_status IN ('none','queued','running','done','error'))");
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS vlm_job_id BIGINT NOT NULL DEFAULT 0');
		$this->run_query("ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS vlm_run_token VARCHAR(48) NOT NULL DEFAULT ''");
		$this->run_query("ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS vlm_error VARCHAR(1000) NOT NULL DEFAULT ''");
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS vlm_started_at TIMESTAMPTZ NULL');
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS vlm_checked_at TIMESTAMPTZ NULL');
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS vlm_by INTEGER NOT NULL DEFAULT 0');
		// Прежние автоматические проверки уже выполнены и записаны в review: помечаем их как проверенные.
		$this->run_query("UPDATE nj_669_solid3d_revisions SET vlm_status='done', vlm_checked_at=COALESCE(finished_at, created_at)
			WHERE status='done' AND review<>'' AND vlm_status='none'");
	}

	public function down()
	{
		foreach (['vlm_by', 'vlm_checked_at', 'vlm_started_at', 'vlm_error', 'vlm_run_token', 'vlm_job_id', 'vlm_status'] as $column)
		{
			$this->run_query('ALTER TABLE nj_669_solid3d_revisions DROP COLUMN IF EXISTS '.$column);
		}
	}
}
