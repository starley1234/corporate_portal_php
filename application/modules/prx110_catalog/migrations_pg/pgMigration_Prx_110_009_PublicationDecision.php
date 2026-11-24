<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_Prx_110_009_PublicationDecision extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
DO $$
DECLARE
    constraint_name text;
BEGIN
    IF EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = 'prx_110_publications'::regclass
               AND attname = 'approved_by' AND NOT attisdropped) THEN
        ALTER TABLE prx_110_publications RENAME COLUMN approved_by TO decided_by;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = 'prx_110_publications'::regclass
               AND attname = 'approved_at' AND NOT attisdropped) THEN
        ALTER TABLE prx_110_publications RENAME COLUMN approved_at TO decided_at;
    END IF;
    FOR constraint_name IN
        SELECT conname FROM pg_constraint
        WHERE conrelid = 'prx_110_publications'::regclass AND contype = 'c'
          AND pg_get_constraintdef(oid) ~ '\mstate\M'
    LOOP
        EXECUTE format('ALTER TABLE prx_110_publications DROP CONSTRAINT %I', constraint_name);
    END LOOP;
    ALTER TABLE prx_110_publications
        ALTER COLUMN state TYPE VARCHAR(16),
        ALTER COLUMN state DROP DEFAULT,
        ADD CONSTRAINT prx_110_publications_state_check CHECK (state IN ('approved', 'wip', 'unpublished', 'archived')),
        ADD COLUMN IF NOT EXISTS note TEXT NOT NULL DEFAULT '';
END $$
SQL);
    }

    public function down()
    {
        $this->run_query(<<<'SQL'
DO $$
BEGIN
    ALTER TABLE prx_110_publications DROP CONSTRAINT IF EXISTS prx_110_publications_state_check;
    UPDATE prx_110_publications SET state = 'wip' WHERE state = 'unpublished';
    ALTER TABLE prx_110_publications
        ALTER COLUMN state TYPE VARCHAR(8),
        ALTER COLUMN state SET DEFAULT 'approved',
        ADD CONSTRAINT prx_110_publications_state_check CHECK (state IN ('approved', 'wip', 'archived')),
        DROP COLUMN IF EXISTS note;
    IF EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = 'prx_110_publications'::regclass
               AND attname = 'decided_by' AND NOT attisdropped) THEN
        ALTER TABLE prx_110_publications RENAME COLUMN decided_by TO approved_by;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = 'prx_110_publications'::regclass
               AND attname = 'decided_at' AND NOT attisdropped) THEN
        ALTER TABLE prx_110_publications RENAME COLUMN decided_at TO approved_at;
    END IF;
END $$
SQL);
    }
}
