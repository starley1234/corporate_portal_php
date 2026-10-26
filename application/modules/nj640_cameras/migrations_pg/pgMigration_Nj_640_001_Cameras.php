<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj640_cameras 001: таблицы сервиса видеонаблюдения цеха.
 *
 * nj_640_scenes        — сценарии контроля (проверка по кадру + JSON-схема);
 * nj_640_cameras       — камеры/источники кадров (snapshot/rtsp/onvif/http),
 *                        триггер, интервал, часы работы, cooldown;
 * nj_640_runs          — прогоны анализа (verdict JSONB, токены, статус; rate
 *                        limit по client_ip);
 * nj_640_events        — журнал событий (дедупликация, подтверждение);
 * nj_640_confirmations — подтверждения/ложные тревоги журнала.
 */
class pgMigration_Nj_640_001_Cameras extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_640_scenes" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "name"        VARCHAR(140) NOT NULL DEFAULT '',
    "topic"       VARCHAR(20)  NOT NULL DEFAULT 'discipline',
    "rule_text"   TEXT         NOT NULL DEFAULT '',
    "variants"    JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "schema"      JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_640_scenes_uid_idx" ON "nj_640_scenes" ("visitor_uid", "id" DESC);
COMMENT ON TABLE "nj_640_scenes" IS 'NJ-640 сценарии контроля: трудовая дисциплина / СИЗ / загрузка оборудования + схема ответа';

CREATE TABLE IF NOT EXISTS "nj_640_cameras" (
    "id"             BIGSERIAL    PRIMARY KEY,
    "visitor_uid"    VARCHAR(40)  NOT NULL DEFAULT '',
    "code"           VARCHAR(40)  NOT NULL DEFAULT '',
    "name"           VARCHAR(140) NOT NULL DEFAULT '',
    "kind"           VARCHAR(16)  NOT NULL DEFAULT 'snapshot',
    "location"       VARCHAR(120) NOT NULL DEFAULT '',
    "scene_id"       BIGINT       NOT NULL DEFAULT 0,
    "config"         JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "trigger"        VARCHAR(12)  NOT NULL DEFAULT 'timer',
    "interval_sec"   INTEGER      NOT NULL DEFAULT 60,
    "work_hours"     JSONB        NULL,
    "cooldown_sec"   INTEGER      NOT NULL DEFAULT 300,
    "enabled"        BOOLEAN      NOT NULL DEFAULT TRUE,
    "last_run_at"    TIMESTAMPTZ  NULL,
    "next_run_at"    TIMESTAMPTZ  NULL,
    "created_at"     TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_640_cameras_uid_idx"   ON "nj_640_cameras" ("visitor_uid", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_640_cameras_code_idx"  ON "nj_640_cameras" ("code");
COMMENT ON TABLE "nj_640_cameras" IS 'NJ-640 камеры цеха/офиса: источник кадра, сценарий, расписание, часы работы, cooldown';

CREATE TABLE IF NOT EXISTS "nj_640_runs" (
    "id"                BIGSERIAL   PRIMARY KEY,
    "visitor_uid"       VARCHAR(40) NOT NULL DEFAULT '',
    "client_ip"         VARCHAR(64) NOT NULL DEFAULT '',
    "camera_id"         BIGINT      NULL,
    "scene_id"          BIGINT      NOT NULL DEFAULT 0,
    "topic"             VARCHAR(20) NOT NULL DEFAULT 'discipline',
    "mode"              VARCHAR(12) NOT NULL DEFAULT 'manual',
    "trigger"           VARCHAR(12) NOT NULL DEFAULT 'manual',
    "status"            VARCHAR(12) NOT NULL DEFAULT 'queued',
    "verdict"           JSONB       NULL,
    "schema_ok"         BOOLEAN     NULL,
    "model"             VARCHAR(160) NULL,
    "prompt_tokens"     INTEGER     NOT NULL DEFAULT 0,
    "completion_tokens" INTEGER     NOT NULL DEFAULT 0,
    "duration_ms"       INTEGER     NOT NULL DEFAULT 0,
    "error"             TEXT        NULL,
    "job_id"            BIGINT      NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ NOT NULL DEFAULT now(),
    "finished_at"       TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS "nj_640_runs_uid_idx" ON "nj_640_runs" ("visitor_uid", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_640_runs_ip_idx"  ON "nj_640_runs" ("client_ip", "id" DESC);
COMMENT ON TABLE "nj_640_runs" IS 'NJ-640 журнал прогонов анализа кадров; verdict JSONB — ответ VLM по схеме сценария';

CREATE TABLE IF NOT EXISTS "nj_640_events" (
    "id"             BIGSERIAL    PRIMARY KEY,
    "visitor_uid"    VARCHAR(40)  NOT NULL DEFAULT '',
    "camera_id"      BIGINT       NOT NULL DEFAULT 0,
    "scene_id"       BIGINT       NOT NULL DEFAULT 0,
    "run_id"         BIGINT       NOT NULL DEFAULT 0,
    "key"            VARCHAR(120) NOT NULL DEFAULT '',
    "severity"       VARCHAR(12)  NOT NULL DEFAULT 'info',
    "title"          VARCHAR(160) NOT NULL DEFAULT '',
    "detail"         TEXT         NOT NULL DEFAULT '',
    "dismissed"      BOOLEAN      NOT NULL DEFAULT FALSE,
    "first_seen_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "last_seen_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "created_at"     TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_640_events_uid_idx"   ON "nj_640_events" ("visitor_uid", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_640_events_key_idx"   ON "nj_640_events" ("visitor_uid", "key", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_640_events_cam_idx"   ON "nj_640_events" ("camera_id", "id" DESC);
COMMENT ON TABLE "nj_640_events" IS 'NJ-640 журнал событий видеонаблюдения: дедупликация по (visitor_uid, camera_id, key)';

CREATE TABLE IF NOT EXISTS "nj_640_confirmations" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "event_id"    BIGINT       NOT NULL DEFAULT 0,
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "verdict"     VARCHAR(24)  NOT NULL DEFAULT 'false_alarm',
    "note"        VARCHAR(400) NOT NULL DEFAULT '',
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_640_conf_event_idx" ON "nj_640_confirmations" ("event_id");
COMMENT ON TABLE "nj_640_confirmations" IS 'NJ-640 подтверждения событий оператором: подтверждено / ложная тревога';

SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_640_confirmations"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_640_events"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_640_runs"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_640_cameras"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_640_scenes"')->execute();
    }
}
