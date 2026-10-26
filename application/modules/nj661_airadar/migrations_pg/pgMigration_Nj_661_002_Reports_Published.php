<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-661/002: публикация отчётов «ИИ-радара».
 *
 * Владелец (08.10.2026): «каталог опубликованных отчётов — как в трендах:
 * читаемый список, свежие сверху, пагинация, поиск; отчёт публикуется по
 * умолчанию, но я могу снять его с публикации».
 *
 *   published = TRUE  — отчёт виден в каталоге /airadar/catalog (DEFAULT TRUE:
 *                       так его и создаёт конвейер);
 *   published = FALSE — отчёт остаётся на своём месте (/airadar/report/<id>
 *                       работает), но в каталоге его нет: пробные прогоны не
 *                       мешают смотреть содержательные.
 *
 * Идемпотентно: ALTER … IF NOT EXISTS и CREATE INDEX IF NOT EXISTS — повторный
 * накат на прод безвреден (правило проекта, см. php_migration_lint).
 */
class pgMigration_Nj_661_002_Reports_Published extends pgMigration
{
	public function up()
	{
		// run_script режет сценарий по «;» ВНЕ строковых литералов (в тексте
		// COMMENT точка с запятой недопустима по-настоящему, но правило общее).
		$this->run_script(<<<SQL
ALTER TABLE "nj_661_reports" ADD COLUMN IF NOT EXISTS "published" BOOLEAN NOT NULL DEFAULT TRUE;
COMMENT ON COLUMN "nj_661_reports"."published" IS 'Отчёт опубликован: виден в каталоге /airadar/catalog (снять и вернуть может владелец)';
CREATE INDEX IF NOT EXISTS "nj_661_reports_published_idx" ON "nj_661_reports" ("published", "id" DESC);
SQL
		);
	}

	public function down()
	{
		$this->run_script(<<<SQL
DROP INDEX IF EXISTS "nj_661_reports_published_idx";
ALTER TABLE "nj_661_reports" DROP COLUMN IF EXISTS "published";
SQL
		);
	}
}
