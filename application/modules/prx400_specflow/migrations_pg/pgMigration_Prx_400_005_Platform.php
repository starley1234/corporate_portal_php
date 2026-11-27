<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx400_specflow: выравнивание с платформой (спринт 27.11.2026).
 *
 *  - file_id — явная ссылка на артефакт prx212 вместо маркера в storage_path
 *    («prx212:<id>»); легаси-пути ФС до prx212 не переносятся (file_id NULL →
 *    карточка предлагает перезалить файл);
 *  - подписанный владелец (FOUNDATION): сотрудник UI < 0, API-ключ prx150 > 0.
 *    Старые UI-записи хранили положительный user_id — переворачиваем ОДИН раз
 *    (вместе с добавлением file_id: повтор миграции владельцев не трогает);
 *  - индекс job_id (протокол воркера ищет запись по задаче), индексы уборки;
 *  - FK prx_400_edits.spec_id и TIMESTAMPTZ во всех таблицах модуля;
 *  - pg_trgm для предотбора кандидатов НСИ (необязательно: без расширения
 *    матчинг работает полным перебором, см. Model_Prx400_Catalog).
 */
class pgMigration_Prx_400_005_Platform extends pgMigration
{
    public function up()
    {
        // По одному оператору на вызов: DO-блоки с $-кавычками run_script не режет.
        foreach ($this->statements_up() as $sql)
        {
            $this->run_query($sql);
        }
    }

    public function down()
    {
        foreach ([
            'DROP INDEX IF EXISTS prx_400_catalog_trgm',
            'ALTER TABLE prx_400_edits DROP CONSTRAINT IF EXISTS prx_400_edits_spec_fk',
            'DROP INDEX IF EXISTS prx_400_specs_status',
            'DROP INDEX IF EXISTS prx_400_specs_file',
            'DROP INDEX IF EXISTS prx_400_specs_job',
            <<<'SQL'
DO $prx400$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_name = 'prx_400_specs' AND column_name = 'file_id'
    ) THEN
        UPDATE prx_400_specs SET owner_id = -owner_id WHERE owner_id < 0;
        ALTER TABLE prx_400_specs DROP COLUMN file_id;
    END IF;
END $prx400$
SQL,
        ] as $sql)
        {
            $this->run_query($sql);
        }
    }

    /** @return string[] */
    private function statements_up(): array
    {
        return [
            <<<'SQL'
DO $prx400$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_name = 'prx_400_specs' AND column_name = 'file_id'
    ) THEN
        ALTER TABLE prx_400_specs ADD COLUMN file_id BIGINT NULL;
        UPDATE prx_400_specs SET owner_id = -owner_id WHERE owner_id > 0;
        UPDATE prx_400_specs
           SET file_id = substr(storage_path, 8)::bigint
         WHERE storage_path ~ '^prx212:[1-9][0-9]{0,17}$';
    END IF;
END $prx400$
SQL,
            'CREATE INDEX IF NOT EXISTS prx_400_specs_job ON prx_400_specs (job_id)',
            'CREATE INDEX IF NOT EXISTS prx_400_specs_file ON prx_400_specs (file_id)',
            'CREATE INDEX IF NOT EXISTS prx_400_specs_status ON prx_400_specs (status, updated_at)',
            'DELETE FROM prx_400_edits e WHERE NOT EXISTS (SELECT 1 FROM prx_400_specs s WHERE s.id = e.spec_id)',
            <<<'SQL'
DO $prx400$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'prx_400_edits_spec_fk') THEN
        ALTER TABLE prx_400_edits
            ADD CONSTRAINT prx_400_edits_spec_fk FOREIGN KEY (spec_id)
            REFERENCES prx_400_specs(id) ON DELETE CASCADE;
    END IF;
END $prx400$
SQL,
            'ALTER TABLE prx_400_edits ALTER COLUMN created_at TYPE TIMESTAMPTZ',
            'ALTER TABLE prx_400_templates ALTER COLUMN created_at TYPE TIMESTAMPTZ',
            'ALTER TABLE prx_400_templates ALTER COLUMN updated_at TYPE TIMESTAMPTZ',
            <<<'SQL'
DO $trgm$
BEGIN
    BEGIN
        IF EXISTS (SELECT 1 FROM pg_available_extensions WHERE name = 'pg_trgm') THEN
            CREATE EXTENSION IF NOT EXISTS pg_trgm;
            CREATE INDEX IF NOT EXISTS prx_400_catalog_trgm
                ON prx_400_catalog USING gin ((lower(name || ' ' || keywords)) gin_trgm_ops);
        END IF;
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'pg_trgm недоступен (%), матчинг НСИ — полным перебором', SQLERRM;
    END;
END $trgm$
SQL,
        ];
    }
}
