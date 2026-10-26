<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-660/001: таблицы «Конструктора архитектуры ИИ» (nj660_arch).
 *
 *   nj_660_designs — спроектированные архитектуры (DSL + посчитанные метрики);
 *   nj_660_runs     — прогоны LLM: экспертизы и симуляции поведения
 *                    (какой движок отвечал, что ответил, за сколько мс).
 *
 * DSL хранится как текст (jsonb не нужен: читаем целиком, не ищем внутри).
 * Владение — user_id или visitor ('u:<id>'/'g:<ubid>', как nj645/nj598):
 * гость собирает черновики в своём браузере, после входа видит свои.
 */
class pgMigration_Nj_660_001_Arch_CreateTables extends pgMigration
{
	public function up()
	{
		$sql = <<<SQL

CREATE TABLE IF NOT EXISTS "nj_660_designs" (
    "id"         BIGSERIAL PRIMARY KEY,
    "code"       VARCHAR(16)  NOT NULL,
    "user_id"    INTEGER      NULL,
    "visitor"    VARCHAR(64)  NOT NULL DEFAULT '',
    "title"      VARCHAR(120) NOT NULL DEFAULT '',
    "family"     VARCHAR(16)  NOT NULL DEFAULT 'decoder',
    "dsl"        TEXT         NOT NULL,
    "metrics"    TEXT         NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_660_designs_code_uniq" UNIQUE ("code")
);

CREATE INDEX IF NOT EXISTS "nj_660_designs_owner_idx"
    ON "nj_660_designs" ("user_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_660_designs_visitor_idx"
    ON "nj_660_designs" ("visitor", "id" DESC);

COMMENT ON TABLE "nj_660_designs" IS 'NJ-660 спроектированные архитектуры: DSL стека блоков + метрики (параметры, VRAM, FLOPs)';
COMMENT ON COLUMN "nj_660_designs"."visitor" IS 'обезличенная метка браузера (Visitor::uid), для гостей';

CREATE TABLE IF NOT EXISTS "nj_660_runs" (
    "id"         BIGSERIAL PRIMARY KEY,
    "design_id"  BIGINT       NOT NULL,
    "kind"       VARCHAR(12)  NOT NULL,
    "user_id"    INTEGER      NULL,
    "visitor"    VARCHAR(64)  NOT NULL DEFAULT '',
    "prompt"     TEXT         NOT NULL DEFAULT '',
    "model"      VARCHAR(64)  NOT NULL DEFAULT '',
    "ms"         INTEGER      NOT NULL DEFAULT 0,
    "output"     TEXT         NOT NULL DEFAULT '',
    "error"      TEXT         NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_660_runs_design_fk" FOREIGN KEY ("design_id")
        REFERENCES "nj_660_designs" ("id") ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS "nj_660_runs_design_idx"
    ON "nj_660_runs" ("design_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_660_runs_visitor_idx"
    ON "nj_660_runs" ("visitor", "created_at" DESC);

COMMENT ON TABLE "nj_660_runs" IS 'NJ-660 прогоны LLM: экспертизы архитектур и симуляции поведения спроектированных моделей';
COMMENT ON COLUMN "nj_660_runs"."kind" IS 'review | simulate';
COMMENT ON COLUMN "nj_660_runs"."model" IS 'движок, который реально отвечал (фасад Llm)';

SQL;

		// run_script, а не explode(';'): «;» может стоять внутри литерала
		// COMMENT (см. nj661/001 — первый накат упал именно на этом).
		$this->run_script($sql);
	}

	public function down()
	{
		$this->run_query('DROP TABLE IF EXISTS "nj_660_runs" CASCADE;');
		$this->run_query('DROP TABLE IF EXISTS "nj_660_designs" CASCADE;');
	}
}
