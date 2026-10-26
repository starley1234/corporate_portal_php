<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj665_voice 002: мягкое удаление озвучек.
 *
 * Повод (09.10.2026, владелец): в «Последних озвучках» не хватало удаления.
 * Удаляем в два слоя — строку помечаем (`deleted_at`), а аудиофайл стираем с
 * диска сразу:
 *
 *   * помеченная строка пропадает из истории и перестаёт открываться по коду
 *     (/voice/f/<code> и /voice/h/<code> дают 404), но остаётся в журнале —
 *     считаем расход и видим, что человек озвучивал (так же делает nj643 при
 *     удалении детали);
 *   * файл нечего держать на диске: места он занимает больше строки, а
 *     восстановить озвучку без внешнего API всё равно нельзя.
 *
 * `ADD COLUMN IF NOT EXISTS` — чтобы повторный накат не падал; индекс
 * частичный (только живые строки): выборка истории ходит именно по нему.
 */
class pgMigration_Nj_665_002_Voice_SoftDelete extends pgMigration
{
	public function up()
	{
		DB::query(NULL, <<<SQL
ALTER TABLE "nj_665_speeches" ADD COLUMN IF NOT EXISTS "deleted_at" TIMESTAMPTZ NULL;
CREATE INDEX IF NOT EXISTS "nj_665_speeches_live_idx" ON "nj_665_speeches" ("visitor_uid", "id" DESC) WHERE "deleted_at" IS NULL;
COMMENT ON COLUMN "nj_665_speeches"."deleted_at" IS 'NJ-665 мягкое удаление: строка скрыта из истории и не открывается по коду, файл с диска стёрт';
SQL
		)->execute();
	}

	public function down()
	{
		DB::query(NULL, 'DROP INDEX IF EXISTS "nj_665_speeches_live_idx"')->execute();
		DB::query(NULL, 'ALTER TABLE "nj_665_speeches" DROP COLUMN IF EXISTS "deleted_at"')->execute();
	}
}
