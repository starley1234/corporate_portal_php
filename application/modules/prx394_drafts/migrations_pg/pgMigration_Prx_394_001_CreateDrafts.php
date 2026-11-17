<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx394_drafts — шаблоны ответов и черновики.
 *
 * Отправка — отдельное право `prx394.send` и только явное действие человека.
 * Автоматической отправки нет: модуль не имеет такого кода вообще.
 */
class pgMigration_Prx_394_001_CreateDrafts extends pgMigration
{
    public function up()
    {
        $this->run_query("
            CREATE TABLE IF NOT EXISTS prx_394_templates (
                id              bigserial PRIMARY KEY,
                code            varchar(60) NOT NULL UNIQUE,
                title           varchar(190) NOT NULL,
                doc_type        varchar(40) NULL,
                body            text NOT NULL,
                is_active       smallint NOT NULL DEFAULT 1,
                created_at      timestamp NOT NULL DEFAULT now(),
                updated_at      timestamp NOT NULL DEFAULT now()
            )
        ");

        $templates = [
            ['claim_reply', 'Ответ на претензию', 'claim', "Уважаемый(ая) {{correspondent}}!\n\nРассмотрев Вашу претензию {{outbound_no}} от {{outbound_date}} по договору {{contract_no}}, сообщаем следующее.\n\n{{summary}}\n\nОтвет будет направлен в установленный законом срок.\n\nС уважением,\n{{organization}}"],
            ['court_reply', 'Ответ на судебное извещение', 'court', "В ответ на судебное извещение по делу {{outbound_no}} сообщаем, что документы приняты к рассмотрению.\n\n{{summary}}\n\nС уважением,\n{{organization}}"],
            ['info_reply', 'Ответ на обращение', 'letter', "Уважаемый(ая) {{correspondent}}!\n\nНа Ваше обращение {{outbound_no}} от {{outbound_date}} сообщаем.\n\n{{summary}}\n\nС уважением,\n{{organization}}"],
            ['tax_reply', 'Ответ на требование налоговой', 'tax', "На требование {{outbound_no}} от {{outbound_date}} сообщаем, что документы будут представлены в установленный срок.\n\n{{summary}}\n\nС уважением,\n{{organization}}"],
        ];
        foreach ($templates as $t)
        {
            $this->run_query("
                INSERT INTO prx_394_templates (code, title, doc_type, body)
                VALUES (".$this->_q($t[0]).", ".$this->_q($t[1]).", ".$this->_q($t[2]).", ".$this->_q($t[3]).")
                ON CONFLICT (code) DO NOTHING
            ");
        }

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prx_394_drafts (
                id              bigserial PRIMARY KEY,
                document_id     bigint NOT NULL,
                reg_no          varchar(32) NULL,
                subject         varchar(500) NULL,
                template_id     bigint NULL REFERENCES prx_394_templates(id) ON DELETE SET NULL,
                profile         varchar(40) NOT NULL DEFAULT 'reply_v1',
                model           varchar(160) NULL,
                prompt_version  varchar(16) NULL,
                body            text NOT NULL DEFAULT '',
                citations       jsonb NOT NULL DEFAULT '[]'::jsonb,
                status          varchar(20) NOT NULL DEFAULT 'draft',
                error           text NULL,
                created_by      bigint NULL,
                approved_by     bigint NULL,
                approved_at     timestamp NULL,
                sent_at         timestamp NULL,
                created_at      timestamp NOT NULL DEFAULT now(),
                updated_at      timestamp NOT NULL DEFAULT now()
            )
        ");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_394_drafts_doc_idx ON prx_394_drafts (document_id, created_at DESC)");
        $this->run_query("CREATE INDEX IF NOT EXISTS prx_394_drafts_status_idx ON prx_394_drafts (status, updated_at DESC)");

        $this->run_query("
            CREATE OR REPLACE FUNCTION prx_394_touch_updated_at()
            RETURNS trigger AS $$
            BEGIN NEW.updated_at = now(); RETURN NEW; END;
            $$ LANGUAGE plpgsql
        ");
        foreach (['prx_394_templates', 'prx_394_drafts'] as $table)
        {
            $this->run_query("DROP TRIGGER IF EXISTS {$table}_touch ON {$table}");
            $this->run_query("CREATE TRIGGER {$table}_touch BEFORE UPDATE ON {$table}
                              FOR EACH ROW EXECUTE FUNCTION prx_394_touch_updated_at()");
        }
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS prx_394_drafts");
        $this->run_query("DROP TABLE IF EXISTS prx_394_templates");
        $this->run_query("DROP FUNCTION IF EXISTS prx_394_touch_updated_at()");
    }

    protected function _q($value)
    {
        return "'".str_replace("'", "''", (string) $value)."'";
    }
}
