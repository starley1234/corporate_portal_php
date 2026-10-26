<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj666_multivoice 001: книги, персонажи и блоки многоголосой озвучки.
 *
 * nj_666_books — одна книга (или просто большой текст): исходник, режим
 * разбора (auto — персонажей ищет модель по ходу; search — сначала «утка го»
 * по названию книги), модель, диапазон длины блока в минутах, голос по
 * умолчанию и живой этап (status/stage/progress) — страница поллит его.
 *
 * nj_666_characters — персонажи книги. Их ведёт модель по ходу разбора и
 * держит список, чтобы не плодить двойников; владелец правит вручную и
 * ставит locked — тогда модель не трогает ни голос, ни описание. kind= narrator
 * — рассказчик (читает авторский текст), он создаётся вместе с книгой.
 *
 * nj_666_blocks — блок озвучки: реплики с голосами (turns_json), оценка
 * длительности и готовый файл. Блок озвучивается ОДНИМ запросом к OmniVoice
 * (input — массив реплик, у каждой свой голос), поэтому переозвучить можно
 * блок целиком, а не отдельную реплику.
 */
class pgMigration_Nj_666_001_Multivoice_CreateTables extends pgMigration
{
	public function up()
	{
		DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_666_books" (
    "id"             BIGSERIAL    PRIMARY KEY,
    "code"           VARCHAR(24)  NOT NULL UNIQUE,
    "user_id"        INTEGER      NOT NULL DEFAULT 0,
    "visitor_uid"    VARCHAR(40)  NOT NULL DEFAULT '',
    "title"          VARCHAR(300) NOT NULL DEFAULT '',
    "author"         VARCHAR(200) NOT NULL DEFAULT '',
    "source_kind"    VARCHAR(16)  NOT NULL DEFAULT 'text',
    "chars"          INTEGER      NOT NULL DEFAULT 0,
    "mode"           VARCHAR(16)  NOT NULL DEFAULT 'auto',
    "model"          VARCHAR(160) NOT NULL DEFAULT '',
    "default_voice"  VARCHAR(80)  NOT NULL DEFAULT '',
    "language"       VARCHAR(16)  NOT NULL DEFAULT '',
    "format"         VARCHAR(8)   NOT NULL DEFAULT 'mp3',
    "speed"          NUMERIC(4,2) NOT NULL DEFAULT 1.0,
    "block_min_min"  NUMERIC(5,2) NOT NULL DEFAULT 7.0,
    "block_max_min"  NUMERIC(5,2) NOT NULL DEFAULT 15.0,
    "single_block"   SMALLINT     NOT NULL DEFAULT 0,
    "status"         VARCHAR(16)  NOT NULL DEFAULT 'new',
    "stage"          VARCHAR(120) NOT NULL DEFAULT '',
    "stage_note"     VARCHAR(300) NOT NULL DEFAULT '',
    "progress"       SMALLINT     NOT NULL DEFAULT 0,
    "error"          VARCHAR(1000) NOT NULL DEFAULT '',
    "job_id"         BIGINT       NOT NULL DEFAULT 0,
    "settings_json"  JSONB        NULL,
    "source_path"    VARCHAR(200) NOT NULL DEFAULT '',
    "created_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "deleted_at"     TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_666_books_code_idx"  ON "nj_666_books" ("code");
CREATE INDEX IF NOT EXISTS "nj_666_books_user_idx"  ON "nj_666_books" ("user_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_666_books_uid_idx"   ON "nj_666_books" ("visitor_uid", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_666_books_status_idx" ON "nj_666_books" ("status", "updated_at");
COMMENT ON TABLE "nj_666_books" IS 'NJ-666 книги на озвучку: status new|planning|planned|voicing|done|error, mode auto|search';

CREATE TABLE IF NOT EXISTS "nj_666_characters" (
    "id"           BIGSERIAL    PRIMARY KEY,
    "book_id"      BIGINT       NOT NULL,
    "key"          VARCHAR(40)  NOT NULL DEFAULT '',
    "name"         VARCHAR(120) NOT NULL DEFAULT '',
    "kind"         VARCHAR(16)  NOT NULL DEFAULT 'character',
    "gender"       VARCHAR(16)  NOT NULL DEFAULT '',
    "age"          VARCHAR(16)  NOT NULL DEFAULT '',
    "voice"        VARCHAR(80)  NOT NULL DEFAULT '',
    "description"  VARCHAR(1000) NOT NULL DEFAULT '',
    "instructions" VARCHAR(500) NOT NULL DEFAULT '',
    "locked"       SMALLINT     NOT NULL DEFAULT 0,
    "mentions"     INTEGER      NOT NULL DEFAULT 0,
    "sort"         INTEGER      NOT NULL DEFAULT 0,
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_666_characters_book_idx"  ON "nj_666_characters" ("book_id", "sort", "id");
CREATE INDEX IF NOT EXISTS "nj_666_characters_key_idx"   ON "nj_666_characters" ("book_id", "key");
COMMENT ON TABLE "nj_666_characters" IS 'NJ-666 персонажи книги: locked — модель не трогает голос и описание; kind character|narrator';

CREATE TABLE IF NOT EXISTS "nj_666_blocks" (
    "id"           BIGSERIAL    PRIMARY KEY,
    "book_id"      BIGINT       NOT NULL,
    "no"           INTEGER      NOT NULL DEFAULT 0,
    "title"        VARCHAR(300) NOT NULL DEFAULT '',
    "chars"        INTEGER      NOT NULL DEFAULT 0,
    "seconds_est"  INTEGER      NOT NULL DEFAULT 0,
    "turns_json"   JSONB        NULL,
    "status"       VARCHAR(16)  NOT NULL DEFAULT 'new',
    "file"         VARCHAR(200) NOT NULL DEFAULT '',
    "bytes"        INTEGER      NOT NULL DEFAULT 0,
    "error"        VARCHAR(500) NOT NULL DEFAULT '',
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_666_blocks_book_idx" ON "nj_666_blocks" ("book_id", "no");
CREATE INDEX IF NOT EXISTS "nj_666_blocks_status_idx" ON "nj_666_blocks" ("book_id", "status");
COMMENT ON TABLE "nj_666_blocks" IS 'NJ-666 блоки озвучки: turns_json — реплики с говорящим, статус new|voiced|error';
SQL
		)->execute();
	}

	public function down()
	{
		DB::query(NULL, 'DROP TABLE IF EXISTS "nj_666_blocks"')->execute();
		DB::query(NULL, 'DROP TABLE IF EXISTS "nj_666_characters"')->execute();
		DB::query(NULL, 'DROP TABLE IF EXISTS "nj_666_books"')->execute();
	}
}
