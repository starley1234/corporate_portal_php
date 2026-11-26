<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx400_specflow: реестр шаблонов разбора (страница «Шаблоны»).
 *
 * Встроенные шаблоны лежат в config/templates/*.json — здесь только
 * строка-оверлей (enabled FALSE для отключённых). Пользовательские шаблоны
 * из UI хранят полное содержимое в колонке json (source = custom).
 */
class pgMigration_Prx_400_002_Templates extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS prx_400_templates (
    code VARCHAR(64) PRIMARY KEY,
    title VARCHAR(255) NOT NULL DEFAULT '',
    json JSONB NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    source VARCHAR(16) NOT NULL CHECK (source IN ('builtin','custom')),
    created_by BIGINT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT now(),
    updated_at TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS prx_400_templates_source_idx ON prx_400_templates (source);
SQL
        );
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS prx_400_templates CASCADE;");
    }
}
