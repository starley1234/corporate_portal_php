<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-560/001: база знаний (комбинированный RAG по наработкам воркеров).
 *
 * Сюда модули складывают результаты фоновых обработок (анализ отзыва,
 * резюме поиска вакансий, …). Дедупликация — точная по entity_key
 * (sha256 нормализованного текста задачи) + счётчик повторов.
 * Векторный поиск — поверх nj115_semantic (коллекция из config).
 */
class pgMigration_Nj_560_001_Knowledge_Docs extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_560_docs" (
    "id"           BIGSERIAL PRIMARY KEY,
    "source_module" VARCHAR(32)  NOT NULL,
    "kind"         VARCHAR(40)   NOT NULL,
    "entity_key"   VARCHAR(64)   NOT NULL,
    "title"        VARCHAR(300)  NOT NULL DEFAULT '',
    "content"      TEXT          NOT NULL DEFAULT '',
    "summary"      TEXT          NULL,
    "meta"         JSONB         NOT NULL DEFAULT '{}'::jsonb,
    "hits"         INTEGER       NOT NULL DEFAULT 1,
    "created_at"   TIMESTAMPTZ   NOT NULL DEFAULT now(),
    "last_seen_at" TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CONSTRAINT "nj_560_docs_uniq" UNIQUE ("source_module", "entity_key")
);
CREATE INDEX IF NOT EXISTS "nj_560_docs_kind_idx"    ON "nj_560_docs" ("source_module", "kind", "last_seen_at" DESC);
CREATE INDEX IF NOT EXISTS "nj_560_docs_seen_idx"    ON "nj_560_docs" ("last_seen_at");

COMMENT ON TABLE  "nj_560_docs" IS 'NJ-560 база знаний: наработки воркеров всех модулей (дедуп + векторный поиск через nj115)';
COMMENT ON COLUMN "nj_560_docs"."entity_key" IS 'sha256 нормализованного ключа задачи — дедупликация повторных работ';
COMMENT ON COLUMN "nj_560_docs"."hits"       IS 'Сколько раз встретилась та же/похожая задача';
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_560_docs" CASCADE;');
    }
}
