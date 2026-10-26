<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-594/002: «Журнал запросов к ФНС» (nj594_orgregistry).
 *
 * nj_594_fns_calls — каждый выход в egrul.nalog.ru (поиск/карточка): кто спросил
 * (IP), что спросили, чем закончилось. Нужен для двух вещей:
 *   * мягкий лимит запросов к реестру (окна «минута/час» по IP + общий кап за
 *     час, чтобы сервис ФНС не «злился» и не начал отвечать отказом);
 *   * телеметрия в /orgs/admin: сколько спросили, сколько нашлось, сколько раз
 *     упирались в лимит.
 *
 * DDL полностью повторяет Service_Nj594_Orgs::ensure_tables().
 */
class pgMigration_Nj_594_002_Fns_Calls extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_594_fns_calls" (
    "id"         BIGSERIAL    PRIMARY KEY,
    "client_ip"  VARCHAR(64)  NOT NULL DEFAULT '',
    "query"      VARCHAR(64)  NOT NULL DEFAULT '',
    "kind"       VARCHAR(12)  NOT NULL DEFAULT 'search',
    "status"     VARCHAR(12)  NOT NULL DEFAULT 'ok',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_594_fns_calls_ip_idx"    ON "nj_594_fns_calls" ("client_ip", "created_at" DESC);
CREATE INDEX IF NOT EXISTS "nj_594_fns_calls_time_idx"  ON "nj_594_fns_calls" ("created_at" DESC);
CREATE INDEX IF NOT EXISTS "nj_594_fns_calls_query_idx" ON "nj_594_fns_calls" ("query", "created_at" DESC);
COMMENT ON TABLE  "nj_594_fns_calls" IS 'NJ-594 журнал запросов к ФНС: лимит (окна по IP) и телеметрия';
COMMENT ON COLUMN "nj_594_fns_calls"."kind"   IS 'search | card | refresh | blocked (отказ по лимиту)';
COMMENT ON COLUMN "nj_594_fns_calls"."status" IS 'ok | empty | error | limited (отказ по лимиту)';
SQL;

        DB::query(NULL, $sql)->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_594_fns_calls"')->execute();
    }
}
