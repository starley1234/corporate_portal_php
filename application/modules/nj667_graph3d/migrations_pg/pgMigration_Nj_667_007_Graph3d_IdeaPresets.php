<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj667_graph3d 007: пресеты идей для страницы «Создать».
 *
 * Повод (11.10.2026, владелец): на сборке три кнопки-идеи. Админ задаёт тему,
 * модель придумывает 30 описаний, лишние можно удалить. Это не данные
 * пользователя, а копилка подсказок сервиса — отдельная таблица, не settings
 * (там значение обрезается до 160 символов).
 */
class pgMigration_Nj_667_007_Graph3d_IdeaPresets extends pgMigration
{
	public function up()
	{
		$this->run_query('CREATE TABLE IF NOT EXISTS nj_667_idea_presets (
			id         BIGSERIAL    PRIMARY KEY,
			theme      VARCHAR(200) NOT NULL DEFAULT \'\',
			body       VARCHAR(280) NOT NULL,
			job_id     BIGINT       NOT NULL DEFAULT 0,
			created_at TIMESTAMPTZ  NOT NULL DEFAULT now()
		)');

		$this->run_query('CREATE INDEX IF NOT EXISTS nj_667_idea_presets_job_idx
			ON nj_667_idea_presets (job_id)');

		$this->run_query('COMMENT ON TABLE nj_667_idea_presets IS \'NJ-667 пресеты идей для страницы Создать: тема пачки, текст, задача воркера\'');
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS nj_667_idea_presets');
	}
}
