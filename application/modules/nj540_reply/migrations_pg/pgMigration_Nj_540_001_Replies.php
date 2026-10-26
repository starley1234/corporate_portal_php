<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-540/001: отзывы и сгенерированные ответы («Отзыв 2 Ответ»).
 *
 * Статусы: new → done | error. Воркер (канал nj540.reply) пишет reply_text;
 * PHP дозаписывает результат из задачи очереди (reconcile), если воркер работал
 * в http-режиме без доступа к БД.
 */
class pgMigration_Nj_540_001_Replies extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_540_replies" (
    "id"          SERIAL PRIMARY KEY,
    "code"        VARCHAR(16) NOT NULL UNIQUE,
    "shop_name"   VARCHAR(120) NULL,
    "review_text" TEXT NOT NULL,
    "tone"        VARCHAR(20) NOT NULL DEFAULT 'friendly',
    "status"      VARCHAR(12) NOT NULL DEFAULT 'new',
    "reply_text"  TEXT NULL,
    "analysis"    JSONB NULL,
    "meta"        JSONB NOT NULL DEFAULT '{}'::jsonb,
    "error"       TEXT NULL,
    "client_ip"   VARCHAR(45) NOT NULL DEFAULT '',
    "job_id"      BIGINT NULL,
    "created_at"  TIMESTAMPTZ NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ NULL,
    CONSTRAINT "nj_540_status_chk" CHECK ("status" IN ('new','done','error'))
);

CREATE INDEX IF NOT EXISTS "nj_540_ip_time_idx" ON "nj_540_replies" ("client_ip", "created_at");
CREATE INDEX IF NOT EXISTS "nj_540_status_idx"  ON "nj_540_replies" ("status");

COMMENT ON TABLE  "nj_540_replies" IS 'NJ-540 «Отзыв 2 Ответ»: отзывы клиентов и ответы LLM-воркера';
COMMENT ON COLUMN "nj_540_replies"."analysis" IS 'Разбор отзыва из LangChain-цепи: sentiment, topics, escalation, language';
COMMENT ON COLUMN "nj_540_replies"."meta"     IS 'Служебное: model, engine (langchain|plain), history — прошлые версии ответа';
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_540_replies" CASCADE;');
    }
}
