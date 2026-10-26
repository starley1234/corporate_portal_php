<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj668_draw2d 002: ревизии сцены, копилка идей, настройки.
 *
 * Повод (11.10.2026, владелец): сохранение ревизии, страница пресетов как
 * у /graph3d, модерация каталога. Сцена снимком (не патч): вектор маленький,
 * откат — подставить JSON. Идеи — отдельная таблица, не settings
 * (там значение короткое).
 */
class pgMigration_Nj_668_002_Draw2d_RevisionsIdeas extends pgMigration
{
	public function up()
	{
		$this->run_query('CREATE TABLE IF NOT EXISTS nj_668_revisions (
			id           BIGSERIAL     PRIMARY KEY,
			project_id   BIGINT        NOT NULL,
			no           INTEGER       NOT NULL DEFAULT 0,
			kind         VARCHAR(16)   NOT NULL DEFAULT \'commit\',
			label        VARCHAR(200)  NOT NULL DEFAULT \'\',
			scene_json   JSONB         NULL,
			author_kind  VARCHAR(16)   NOT NULL DEFAULT \'user\',
			created_at   TIMESTAMPTZ   NOT NULL DEFAULT now(),
			deleted_at   TIMESTAMPTZ   NULL
		)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_668_revisions_project_idx
			ON nj_668_revisions (project_id, no DESC)');
		$this->run_query('COMMENT ON TABLE nj_668_revisions IS
			\'NJ-668 ревизии иллюстрации: снимок scene_json, мягкое удаление\'');

		$this->run_query('CREATE TABLE IF NOT EXISTS nj_668_idea_presets (
			id         BIGSERIAL    PRIMARY KEY,
			theme      VARCHAR(200) NOT NULL DEFAULT \'\',
			body       VARCHAR(280) NOT NULL,
			job_id     BIGINT       NOT NULL DEFAULT 0,
			created_at TIMESTAMPTZ  NOT NULL DEFAULT now()
		)');
		$this->run_query('CREATE INDEX IF NOT EXISTS nj_668_idea_presets_job_idx
			ON nj_668_idea_presets (job_id)');
		$this->run_query('COMMENT ON TABLE nj_668_idea_presets IS
			\'NJ-668 пресеты идей для страницы Создать: тема пачки, текст, задача воркера\'');

		$this->run_query('CREATE TABLE IF NOT EXISTS nj_668_settings (
			key        VARCHAR(64)  PRIMARY KEY,
			value      TEXT         NOT NULL DEFAULT \'\',
			updated_at TIMESTAMPTZ  NOT NULL DEFAULT now()
		)');
		$this->run_query('COMMENT ON TABLE nj_668_settings IS
			\'NJ-668 настройки модуля: очередь идей и прочие ключи админки\'');

		$this->run_query('ALTER TABLE nj_668_projects
			ADD COLUMN IF NOT EXISTS moderation_note VARCHAR(500) NOT NULL DEFAULT \'\'');
		$this->run_query('ALTER TABLE nj_668_projects
			ADD COLUMN IF NOT EXISTS reviewed_by INTEGER NOT NULL DEFAULT 0');
		$this->run_query('ALTER TABLE nj_668_projects
			ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMPTZ NULL');
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS nj_668_revisions');
		$this->run_query('DROP TABLE IF EXISTS nj_668_idea_presets');
		$this->run_query('DROP TABLE IF EXISTS nj_668_settings');
	}
}
