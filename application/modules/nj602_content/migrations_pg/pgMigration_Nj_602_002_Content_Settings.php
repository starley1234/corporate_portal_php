<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-602/002: редакционные настройки конвейера (правится из админки).
 *
 * nj_602_settings — плоская таблица ключ-значение (JSONB):
 *   enabled  — bool, «Конвейер работает» (выключен — расписание и кнопка
 *              не создают прогоны, воркер по брифингу отказывается работать);
 *   topics   — JSON array активных тем (пусто = весь предопределённый каталог);
 *   custom   — JSON array своих тем администратора (дополняют каталог).
 */
class pgMigration_Nj_602_002_Content_Settings extends pgMigration {

	public function up()
	{
		$this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_602_settings" (
    "key"        VARCHAR(60) PRIMARY KEY,
    "value"      JSONB       NOT NULL,
    "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "nj_602_settings" IS 'NJ-602 настройки конвейера: enabled|topics|custom';

INSERT INTO "nj_602_settings" ("key", "value") VALUES
    ('enabled', 'true'::jsonb),
    ('topics',  '[]'::jsonb),
    ('custom',  '[]'::jsonb)
ON CONFLICT ("key") DO NOTHING;
SQL);
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS "nj_602_settings"');
	}
}
