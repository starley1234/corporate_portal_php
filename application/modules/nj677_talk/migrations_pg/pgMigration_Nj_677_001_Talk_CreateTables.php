<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj677_talk 001: разговоры голосом и их реплики.
 *
 * nj_677_talks    — один разговор (страница /talk): код для браузера, владелец,
 *                   голос, модель, число ходов. Разговор начинает сам сервер
 *                   при первом ответе: пользователь не должен ничего
 *                   «создавать», он просто говорит.
 *
 * nj_677_messages — реплики по порядку. Роль user|assistant, текст и
 *                   миллисекунды этапа: у реплики пользователя — распознавание
 *                   речи, у ответа ассистента — вызов модели. Числа нужны, чтобы
 *                   задержку разговора можно было разбирать по факту, а не на
 *                   глазок.
 *
 * Хранение ограничено: чистка объявлена в манифесте (config/nj_module.php),
 * 30 дней после последнего хода; реплики уходят каскадом.
 */
class pgMigration_Nj_677_001_Talk_CreateTables extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_677_talks" (
    "id"         BIGSERIAL    PRIMARY KEY,
    "code"       VARCHAR(16)  NOT NULL UNIQUE,
    "user_id"    INTEGER      NOT NULL DEFAULT 0,
    "title"      VARCHAR(160) NOT NULL DEFAULT '',
    "model"      VARCHAR(120) NOT NULL DEFAULT '',
    "voice"      VARCHAR(80)  NOT NULL DEFAULT '',
    "turns"      INTEGER      NOT NULL DEFAULT 0,
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_677_talks_user_idx"    ON "nj_677_talks" ("user_id", "updated_at" DESC);
CREATE INDEX IF NOT EXISTS "nj_677_talks_updated_idx" ON "nj_677_talks" ("updated_at");
COMMENT ON TABLE "nj_677_talks" IS 'NJ-677 разговоры голосом: код для браузера, владелец, голос, модель, число ходов';

CREATE TABLE IF NOT EXISTS "nj_677_messages" (
    "id"         BIGSERIAL   PRIMARY KEY,
    "talk_id"    BIGINT      NOT NULL REFERENCES "nj_677_talks" ("id") ON DELETE CASCADE,
    "role"       VARCHAR(16) NOT NULL DEFAULT 'user',
    "text"       TEXT        NOT NULL DEFAULT '',
    "chars"      INTEGER     NOT NULL DEFAULT 0,
    "ms"         INTEGER     NOT NULL DEFAULT 0,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_677_messages_talk_idx"    ON "nj_677_messages" ("talk_id", "id");
CREATE INDEX IF NOT EXISTS "nj_677_messages_created_idx" ON "nj_677_messages" ("created_at");
COMMENT ON TABLE "nj_677_messages" IS 'NJ-677 реплики разговора: role user|assistant, ms — распознавание речи (user) или вызов модели (assistant)';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_677_messages"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_677_talks"')->execute();
    }
}
