<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj665_voice 001: журнал озвучек и кеш каталога моделей.
 *
 * nj_665_speeches — одна строка на одну озвучку: код (он же имя файла в
 * storage/nj665), чем озвучивали (модель, голос, скорость, формат, язык,
 * инструкция), сколько символов ушло и сколько байт пришло. По коду файл
 * открывается повторно (/voice/f/<code>) и скачивается заново — пересинтез
 * не нужен. Срок жизни — ttl_days из конфига; выметает
 * Service_Nj665_Voice::sweep() и nj100/cleanup по контракту манифеста.
 *
 * nj_665_catalog — кеш ответа GET /api/v1/models: модели, голоса и форматы
 * живут на стороне внешнего API и меняются редко, а спрашивать их на каждый
 * запрос страницы — лишний круг до сети (и лишний повод упасть).
 */
class pgMigration_Nj_665_001_Voice_CreateTables extends pgMigration
{
	public function up()
	{
		DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_665_speeches" (
    "id"             BIGSERIAL    PRIMARY KEY,
    "code"           VARCHAR(24)  NOT NULL UNIQUE,
    "user_id"        INTEGER      NOT NULL DEFAULT 0,
    "visitor_uid"    VARCHAR(40)  NOT NULL DEFAULT '',
    "client_ip"      VARCHAR(64)  NOT NULL DEFAULT '',
    "model"          VARCHAR(120) NOT NULL DEFAULT '',
    "voice"          VARCHAR(80)  NOT NULL DEFAULT '',
    "format"         VARCHAR(8)   NOT NULL DEFAULT 'mp3',
    "speed"          NUMERIC(4,2) NOT NULL DEFAULT 1.0,
    "language"       VARCHAR(16)  NOT NULL DEFAULT '',
    "instructions"   VARCHAR(500) NOT NULL DEFAULT '',
    "chars"          INTEGER      NOT NULL DEFAULT 0,
    "bytes"          INTEGER      NOT NULL DEFAULT 0,
    "title"          VARCHAR(200) NOT NULL DEFAULT '',
    "excerpt"        VARCHAR(400) NOT NULL DEFAULT '',
    "status"         VARCHAR(16)  NOT NULL DEFAULT 'done',
    "error"          VARCHAR(500) NOT NULL DEFAULT '',
    "plays"          INTEGER      NOT NULL DEFAULT 0,
    "downloads"      INTEGER      NOT NULL DEFAULT 0,
    "created_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "last_access_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_665_speeches_code_idx"      ON "nj_665_speeches" ("code");
CREATE INDEX IF NOT EXISTS "nj_665_speeches_uid_idx"       ON "nj_665_speeches" ("visitor_uid", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_665_speeches_user_idx"      ON "nj_665_speeches" ("user_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_665_speeches_ip_idx"        ON "nj_665_speeches" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_665_speeches_created_idx"   ON "nj_665_speeches" ("created_at");
COMMENT ON TABLE "nj_665_speeches" IS 'NJ-665 озвучки текста: код = имя файла в storage/nj665, excerpt — начало текста, status done|error';

CREATE TABLE IF NOT EXISTS "nj_665_catalog" (
    "key"        VARCHAR(32)  PRIMARY KEY,
    "payload"    JSONB        NULL,
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
COMMENT ON TABLE "nj_665_catalog" IS 'NJ-665 кеш ответов внешнего API: key=models — каталог моделей, голосов и форматов OmniVoice v1';
SQL
		)->execute();
	}

	public function down()
	{
		DB::query(NULL, 'DROP TABLE IF EXISTS "nj_665_catalog"')->execute();
		DB::query(NULL, 'DROP TABLE IF EXISTS "nj_665_speeches"')->execute();
	}
}
