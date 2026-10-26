<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 005: заявок на модерацию может быть несколько.
 *
 * Повод (11.10.2026): unique «одна review на проект» запирал всю модель.
 * Откат к другой ревизии должен снова давать «Отправить на модерацию».
 */
class pgMigration_Nj_667_005_Graph3d_ManyReviews extends pgMigration
{
	public function up()
	{
		$this->run_query('DROP INDEX IF EXISTS nj_667_revisions_one_review_idx');
	}

	public function down()
	{
		// Unique не возвращаем: на проекте уже могут висеть две заявки.
	}
}
