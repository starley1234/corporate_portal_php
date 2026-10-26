<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-600/002: LLM-колонки компактного профиля.
 *
 * Service_Nj600_Prefs::summary_llm_*() читает/пишет llm_summary, llm_at,
 * llm_model, но миграция 001 эти колонки не создавала (раньше их добавлял
 * ensure_tables(), автосоздание которого выключено). На свежей БД
 * /nj600/admin падал с «column llm_summary does not exist».
 */
class pgMigration_Nj_600_002_Prefs_Profile_Llm_Columns extends pgMigration {

	public function up()
	{
		$this->run_query(<<<'SQL'
ALTER TABLE "nj_600_profile" ADD COLUMN IF NOT EXISTS "llm_summary" TEXT NULL;
ALTER TABLE "nj_600_profile" ADD COLUMN IF NOT EXISTS "llm_at" TIMESTAMPTZ NULL;
ALTER TABLE "nj_600_profile" ADD COLUMN IF NOT EXISTS "llm_model" VARCHAR(120) NOT NULL DEFAULT '';
SQL);
	}

	public function down()
	{
		$this->run_query(<<<'SQL'
ALTER TABLE "nj_600_profile" DROP COLUMN IF EXISTS "llm_model";
ALTER TABLE "nj_600_profile" DROP COLUMN IF EXISTS "llm_at";
ALTER TABLE "nj_600_profile" DROP COLUMN IF EXISTS "llm_summary";
SQL);
	}
}
