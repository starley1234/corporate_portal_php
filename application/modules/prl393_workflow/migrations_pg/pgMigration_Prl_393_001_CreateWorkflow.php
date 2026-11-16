<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prl393_workflow — маршруты, календарь и сроки.
 *
 * Срок считается здесь и только здесь. prl391 хранит снимок `deadline_at` для
 * списков, но источник истины — prl_393_deadlines.
 */
class pgMigration_Prl_393_001_CreateWorkflow extends pgMigration
{
    public function up()
    {
        $this->run_query("
            CREATE TABLE IF NOT EXISTS prl_393_rules (
                id              bigserial PRIMARY KEY,
                code            varchar(60) NOT NULL UNIQUE,
                title           varchar(190) NOT NULL,
                priority        int NOT NULL DEFAULT 100,
                is_active       smallint NOT NULL DEFAULT 1,
                match_types     text[] NULL,
                match_domains   text[] NULL,
                match_inns      text[] NULL,
                match_keywords  text[] NULL,
                assign_department varchar(80) NULL,
                assign_role     varchar(40) NULL,
                priority_set    varchar(12) NOT NULL DEFAULT 'normal',
                sla_hours       int NULL,
                business_days   boolean NOT NULL DEFAULT true,
                stop            boolean NOT NULL DEFAULT false,
                note            text NULL,
                created_at      timestamp NOT NULL DEFAULT now(),
                updated_at      timestamp NOT NULL DEFAULT now()
            )
        ");

        $rules = [
            ['court', 'Судебные извещения — юрист, 24 часа', 10, ['court'], ['sudrf.ru', 'mos-gorsud.ru', 'arbitr.ru'], [], ['суд', 'извещение', 'дело'], 'Юридический отдел', 'docflow_lawyer', 'high', 24, true, true],
            ['fas', 'ФАС — юрист, 48 часов', 20, ['fas'], ['fas.gov.ru'], [], ['ФАС', 'антимонопол'], 'Юридический отдел', 'docflow_lawyer', 'high', 48, true, true],
            ['tax', 'Налоговая — бухгалтерия, 5 рабочих дней', 30, ['tax'], ['nalog.gov.ru', 'nalog.ru'], [], ['требование', 'налог'], 'Бухгалтерия', 'docflow_clerk', 'high', 120, true, false],
            ['claim', 'Претензия — юрист, 5 рабочих дней', 40, ['claim'], [], [], ['претензи'], 'Юридический отдел', 'docflow_lawyer', 'high', 120, true, false],
            ['contract', 'Договор — договорной отдел', 50, ['contract'], [], [], ['договор'], 'Договорной отдел', 'docflow_clerk', 'normal', 240, true, false],
            ['invoice', 'Счёт — бухгалтерия, 3 дня', 60, ['invoice'], [], [], ['счёт', 'счет', 'УПД'], 'Бухгалтерия', 'docflow_clerk', 'normal', 72, false, false],
        ];

        foreach ($rules as $r)
        {
            $this->run_query("
                INSERT INTO prl_393_rules
                    (code, title, priority, match_types, match_domains, match_inns, match_keywords,
                     assign_department, assign_role, priority_set, sla_hours, business_days, stop)
                VALUES (
                    ".$this->_q($r[0]).", ".$this->_q($r[1]).", ".(int)$r[2].",
                    ".$this->_arr($r[3]).", ".$this->_arr($r[4]).", ".$this->_arr($r[5]).", ".$this->_arr($r[6]).",
                    ".$this->_q($r[7]).", ".$this->_q($r[8]).", ".$this->_q($r[9]).",
                    ".(int)$r[10].", ".($r[11] ? 'true' : 'false').", ".($r[12] ? 'true' : 'false')."
                )
                ON CONFLICT (code) DO NOTHING
            ");
        }

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prl_393_calendars (
                id              bigserial PRIMARY KEY,
                code            varchar(40) NOT NULL UNIQUE,
                title           varchar(190) NOT NULL,
                workdays        smallint[] NOT NULL DEFAULT '{1,2,3,4,5}',
                holidays        date[] NOT NULL DEFAULT '{}',
                workday_start   time NOT NULL DEFAULT '09:00',
                workday_end     time NOT NULL DEFAULT '18:00',
                is_active       smallint NOT NULL DEFAULT 1,
                created_at      timestamp NOT NULL DEFAULT now()
            )
        ");
        $this->run_query("
            INSERT INTO prl_393_calendars (code, title, holidays)
            VALUES ('ru', 'Производственный календарь РФ', ARRAY[
                DATE '2026-01-01', DATE '2026-01-02', DATE '2026-01-07',
                DATE '2026-02-23', DATE '2026-03-08', DATE '2026-05-01',
                DATE '2026-05-09', DATE '2026-06-12', DATE '2026-11-04'
            ]::date[])
            ON CONFLICT (code) DO NOTHING
        ");

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prl_393_assignments (
                id              bigserial PRIMARY KEY,
                document_id     bigint NOT NULL UNIQUE,
                reg_no          varchar(32) NULL,
                subject         varchar(500) NULL,
                department      varchar(80) NOT NULL,
                role_code       varchar(40) NULL,
                assignee_id     bigint NULL,
                rule_id         bigint NULL REFERENCES prl_393_rules(id) ON DELETE SET NULL,
                priority        varchar(12) NOT NULL DEFAULT 'normal',
                status          varchar(20) NOT NULL DEFAULT 'assigned',
                note            text NULL,
                created_at      timestamp NOT NULL DEFAULT now(),
                updated_at      timestamp NOT NULL DEFAULT now()
            )
        ");

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prl_393_deadlines (
                id              bigserial PRIMARY KEY,
                document_id     bigint NOT NULL UNIQUE,
                reg_no          varchar(32) NULL,
                subject         varchar(500) NULL,
                department      varchar(80) NULL,
                kind            varchar(24) NOT NULL DEFAULT 'sla',
                source          varchar(20) NOT NULL DEFAULT 'calculated',
                due_at          timestamp NOT NULL,
                started_at      timestamp NOT NULL DEFAULT now(),
                status          varchar(16) NOT NULL DEFAULT 'open',
                business_days   boolean NOT NULL DEFAULT true,
                note            text NULL,
                created_at      timestamp NOT NULL DEFAULT now(),
                updated_at      timestamp NOT NULL DEFAULT now()
            )
        ");
        $this->run_query("CREATE INDEX IF NOT EXISTS prl_393_deadlines_due_idx ON prl_393_deadlines (due_at) WHERE status = 'open'");

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prl_393_escalations (
                id              bigserial PRIMARY KEY,
                deadline_id     bigint NOT NULL REFERENCES prl_393_deadlines(id) ON DELETE CASCADE,
                document_id     bigint NOT NULL,
                level           varchar(20) NOT NULL,
                sent_at         timestamp NOT NULL DEFAULT now(),
                UNIQUE (deadline_id, level)
            )
        ");

        $this->run_query("
            CREATE OR REPLACE FUNCTION prl_393_touch_updated_at()
            RETURNS trigger AS $$
            BEGIN NEW.updated_at = now(); RETURN NEW; END;
            $$ LANGUAGE plpgsql
        ");
        foreach (['prl_393_rules', 'prl_393_assignments', 'prl_393_deadlines'] as $table)
        {
            $this->run_query("DROP TRIGGER IF EXISTS {$table}_touch ON {$table}");
            $this->run_query("CREATE TRIGGER {$table}_touch BEFORE UPDATE ON {$table}
                              FOR EACH ROW EXECUTE FUNCTION prl_393_touch_updated_at()");
        }
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS prl_393_escalations");
        $this->run_query("DROP TABLE IF EXISTS prl_393_deadlines");
        $this->run_query("DROP TABLE IF EXISTS prl_393_assignments");
        $this->run_query("DROP TABLE IF EXISTS prl_393_calendars");
        $this->run_query("DROP TABLE IF EXISTS prl_393_rules");
        $this->run_query("DROP FUNCTION IF EXISTS prl_393_touch_updated_at()");
    }

    protected function _q($value)
    {
        return "'".str_replace("'", "''", (string) $value)."'";
    }

    protected function _arr(array $values)
    {
        $out = [];
        foreach ($values as $v)
        {
            $out[] = $this->_q($v);
        }

        return $out ? 'ARRAY['.implode(',', $out).']::text[]' : 'NULL';
    }
}
