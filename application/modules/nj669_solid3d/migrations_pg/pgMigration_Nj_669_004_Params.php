<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Ползунки параметров без новой ревизии.
 *
 * params — JSON с размерами, которыми собрана ТЕКУЩАЯ геометрия версии. Меняется
 * только вместе с files при успешной пересборке той же строки ревизии.
 * pending_source / pending_params — код и значения, ожидающие сборки. Пока они
 * не NULL у активной задачи, она пересчитывает ту же версию; старые source,
 * params и files до приёмки не трогаются, поэтому при неудаче откатывать нечего.
 * queued_at — момент постановки задачи. Для пересчёта created_at не сдвигаем:
 * он нужен истории и квоте, а срок задачи считаем от queued_at.
 * build_log — технический вывод сборщика, его видит только модератор.
 */
class pgMigration_Nj_669_004_Params extends pgMigration
{
	public function up()
	{
		$this->run_query("ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS params JSONB NOT NULL DEFAULT '{}'");
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS pending_source TEXT NULL');
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS pending_params JSONB NULL');
		$this->run_query('ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS queued_at TIMESTAMPTZ NOT NULL DEFAULT now()');
		$this->run_query("ALTER TABLE nj_669_solid3d_revisions ADD COLUMN IF NOT EXISTS build_log VARCHAR(1500) NOT NULL DEFAULT ''");
		$this->run_query("UPDATE nj_669_solid3d_revisions SET queued_at=created_at WHERE status IN ('queued','running')");
	}

	public function down()
	{
		foreach (['build_log', 'queued_at', 'pending_params', 'pending_source', 'params'] as $column)
		{
			$this->run_query('ALTER TABLE nj_669_solid3d_revisions DROP COLUMN IF EXISTS '.$column);
		}
	}
}
