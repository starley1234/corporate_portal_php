<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx391_documents — реестр входящих: карточка, типы, корреспонденты,
 * провенанс полей и журнал действий.
 *
 * `deadline_at` — денормализованный снимок для списков и поиска. Источник
 * истины по сроку — prx393_workflow; модуль 391 получает значение только
 * через публичный метод prx393.
 */
class pgMigration_Prx_391_001_CreateDocuments extends pgMigration
{
    public function up()
    {
        $this->run_query("
            CREATE SEQUENCE IF NOT EXISTS prx_391_reg_no_seq START WITH 1
        ");

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prx_391_types (
                id                  bigserial PRIMARY KEY,
                code                varchar(40) NOT NULL UNIQUE,
                title               varchar(190) NOT NULL,
                icon                varchar(64) NOT NULL DEFAULT 'fas fa-file-alt',
                default_sla_hours   int NOT NULL DEFAULT 120,
                default_business_days boolean NOT NULL DEFAULT false,
                is_active           smallint NOT NULL DEFAULT 1,
                sort                int NOT NULL DEFAULT 100,
                created_at          timestamp NOT NULL DEFAULT now()
            )
        ");

        $types = [
            ['court', 'Судебное извещение', 'fas fa-gavel', 24, true, 10],
            ['fas', 'ФАС / антимонопольное', 'fas fa-scale-balanced', 48, true, 20],
            ['tax', 'Требование налоговой', 'fas fa-building-columns', 120, true, 30],
            ['claim', 'Претензия', 'fas fa-file-signature', 120, true, 40],
            ['contract', 'Договор / допсоглашение', 'fas fa-file-contract', 240, true, 50],
            ['invoice', 'Счёт / УПД', 'fas fa-receipt', 72, false, 60],
            ['letter', 'Письмо / обращение', 'fas fa-envelope-open-text', 120, true, 70],
            ['other', 'Прочее', 'fas fa-file', 120, true, 100],
        ];
        foreach ($types as $t)
        {
            $this->run_query("
                INSERT INTO prx_391_types (code, title, icon, default_sla_hours, default_business_days, sort)
                VALUES (".$this->_q($t[0]).", ".$this->_q($t[1]).", ".$this->_q($t[2]).", ".(int)$t[3].", ".($t[4] ? 'true' : 'false').", ".(int)$t[5].")
                ON CONFLICT (code) DO NOTHING
            ");
        }

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prx_391_correspondents (
                id              bigserial PRIMARY KEY,
                name            varchar(255) NOT NULL,
                inn             varchar(12) NULL,
                kpp             varchar(9) NULL,
                email           varchar(255) NULL,
                phone           varchar(64) NULL,
                address         text NULL,
                kind            varchar(24) NOT NULL DEFAULT 'org',
                organization_id bigint NULL,
                created_at      timestamp NOT NULL DEFAULT now(),
                updated_at      timestamp NOT NULL DEFAULT now()
            )
        ");
        $this->run_query("CREATE UNIQUE INDEX IF NOT EXISTS prx_391_corr_inn_uniq ON prx_391_correspondents (inn) WHERE inn IS NOT NULL AND inn <> ''");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_391_corr_name_idx ON prx_391_correspondents (lower(name))");

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prx_391_documents (
                id                  bigserial PRIMARY KEY,
                reg_no              varchar(32) NOT NULL UNIQUE,
                source_message_id   bigint NULL UNIQUE,
                subject             varchar(500) NOT NULL DEFAULT '',
                type_id             bigint NULL REFERENCES prx_391_types(id) ON DELETE SET NULL,
                correspondent_id    bigint NULL REFERENCES prx_391_correspondents(id) ON DELETE SET NULL,
                sender_name         varchar(255) NULL,
                sender_email        varchar(255) NULL,
                sender_inn          varchar(12) NULL,
                outbound_no         varchar(120) NULL,
                outbound_date       date NULL,
                contract_no         varchar(120) NULL,
                amount              numeric(15,2) NULL,
                currency            char(3) NOT NULL DEFAULT 'RUB',
                received_at         timestamp NOT NULL DEFAULT now(),
                deadline_at         timestamp NULL,
                deadline_source     varchar(24) NULL,
                deadline_hint_days  int NULL,
                deadline_hint_unit  varchar(12) NULL,
                deadline_quote      text NULL,
                summary             text NULL,
                risk_level          varchar(12) NULL,
                status              varchar(24) NOT NULL DEFAULT 'new',
                priority            varchar(12) NOT NULL DEFAULT 'normal',
                sensitivity         varchar(16) NOT NULL DEFAULT 'internal',
                assignee_id         bigint NULL,
                assignee_role       varchar(40) NULL,
                department          varchar(80) NULL,
                organization_id     bigint NULL,
                created_by          bigint NULL,
                created_at          timestamp NOT NULL DEFAULT now(),
                updated_at          timestamp NOT NULL DEFAULT now(),
                closed_at           timestamp NULL
            )
        ");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_391_docs_status_idx ON prx_391_documents (status, received_at DESC)");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_391_docs_deadline_idx ON prx_391_documents (deadline_at) WHERE status NOT IN ('closed', 'archived')");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_391_docs_type_idx ON prx_391_documents (type_id)");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_391_docs_corr_idx ON prx_391_documents (correspondent_id)");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_391_docs_subject_trgm_idx ON prx_391_documents (lower(subject))");

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prx_391_field_events (
                id              bigserial PRIMARY KEY,
                document_id     bigint NOT NULL REFERENCES prx_391_documents(id) ON DELETE CASCADE,
                field           varchar(64) NOT NULL,
                old_value       text NULL,
                new_value       text NULL,
                source          varchar(16) NOT NULL DEFAULT 'manual',
                suggestion_id   bigint NULL,
                actor_id        bigint NULL,
                created_at      timestamp NOT NULL DEFAULT now()
            )
        ");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_391_field_events_doc_idx ON prx_391_field_events (document_id, created_at DESC)");

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prx_391_history (
                id              bigserial PRIMARY KEY,
                document_id     bigint NOT NULL REFERENCES prx_391_documents(id) ON DELETE CASCADE,
                event           varchar(60) NOT NULL,
                note            text NULL,
                actor_id        bigint NULL,
                payload         jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at      timestamp NOT NULL DEFAULT now()
            )
        ");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_391_history_doc_idx ON prx_391_history (document_id, created_at DESC)");

        $this->run_query("
            CREATE OR REPLACE FUNCTION prx_391_touch_updated_at()
            RETURNS trigger AS $$
            BEGIN NEW.updated_at = now(); RETURN NEW; END;
            $$ LANGUAGE plpgsql
        ");
        foreach (['prx_391_correspondents', 'prx_391_documents'] as $table)
        {
            $this->run_query("DROP TRIGGER IF EXISTS {$table}_touch ON {$table}");
            $this->run_query("CREATE TRIGGER {$table}_touch BEFORE UPDATE ON {$table}
                              FOR EACH ROW EXECUTE FUNCTION prx_391_touch_updated_at()");
        }
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS prx_391_history");
        $this->run_query("DROP TABLE IF EXISTS prx_391_field_events");
        $this->run_query("DROP TABLE IF EXISTS prx_391_documents");
        $this->run_query("DROP TABLE IF EXISTS prx_391_correspondents");
        $this->run_query("DROP TABLE IF EXISTS prx_391_types");
        $this->run_query("DROP SEQUENCE IF EXISTS prx_391_reg_no_seq");
        $this->run_query("DROP FUNCTION IF EXISTS prx_391_touch_updated_at()");
    }

    protected function _q($value)
    {
        return "'".str_replace("'", "''", (string) $value)."'";
    }
}
