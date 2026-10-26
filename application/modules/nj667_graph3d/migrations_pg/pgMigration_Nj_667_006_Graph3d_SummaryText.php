<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 006: саммари — TEXT, не VARCHAR(1000).
 *
 * Повод (11.10.2026): модель пишет описание длиннее тысячи знаков, и оно
 * обрезалось и в базе, и при приёмке. Поиск и карточка каталога должны
 * видеть полный текст.
 */
class pgMigration_Nj_667_006_Graph3d_SummaryText extends pgMigration
{
	public function up()
	{
		$this->run_query('ALTER TABLE nj_667_projects
			ALTER COLUMN summary TYPE TEXT');
		$this->run_query('ALTER TABLE nj_667_revisions
			ALTER COLUMN summary TYPE TEXT');
		$this->run_query('COMMENT ON COLUMN nj_667_projects.summary IS
			\'текстовое описание графа для поиска; длина не режется VARCHAR\'');
	}

	public function down()
	{
		// Обратно в VARCHAR(1000) не возвращаем: обрежет уже сохранённые тексты.
	}
}
