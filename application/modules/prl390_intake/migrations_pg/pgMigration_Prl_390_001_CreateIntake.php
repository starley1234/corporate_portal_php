<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prl390_intake — каналы приёма, конверты документов, вложения и распознанный
 * текст. Модуль владеет только этими таблицами; карточка документа живёт в
 * prl391, файлы — в prl212.
 */
class pgMigration_Prl_390_001_CreateIntake extends pgMigration
{
    public function up()
    {
        // Роли документооборота. Создаются здесь, потому что prl390 — первый
        // модуль контура; остальные модули ссылаются на те же коды.
        $this->run_query("
            INSERT INTO roles (name, description)
            VALUES
                ('docflow_clerk',   'Делопроизводитель: приём и разбор входящих'),
                ('docflow_lawyer',  'Юрист: проверка ИИ-разметки и ответы'),
                ('docflow_manager', 'Руководитель документооборота: маршруты и сроки')
            ON CONFLICT (name) DO NOTHING
        ");

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prl_390_channels (
                id              bigserial PRIMARY KEY,
                code            varchar(32) NOT NULL UNIQUE,
                title           varchar(190) NOT NULL,
                kind            varchar(24) NOT NULL DEFAULT 'folder',
                config          jsonb NOT NULL DEFAULT '{}'::jsonb,
                is_active       smallint NOT NULL DEFAULT 1,
                last_scan_at    timestamp NULL,
                last_ok_at      timestamp NULL,
                last_error      text NULL,
                created_at      timestamp NOT NULL DEFAULT now(),
                updated_at      timestamp NOT NULL DEFAULT now()
            )
        ");

        $this->run_query("
            INSERT INTO prl_390_channels (code, title, kind, config)
            VALUES ('folder', 'Папка на сервере', 'folder', '{}'::jsonb)
            ON CONFLICT (code) DO NOTHING
        ");

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prl_390_messages (
                id                  bigserial PRIMARY KEY,
                channel_id          bigint NULL REFERENCES prl_390_channels(id) ON DELETE SET NULL,
                source              varchar(24) NOT NULL DEFAULT 'folder',
                source_ref          varchar(512) NULL,
                message_id          varchar(255) NULL,
                sha256              char(64) NOT NULL,
                subject             varchar(500) NULL,
                sender_name         varchar(255) NULL,
                sender_email        varchar(255) NULL,
                sender_inn          varchar(12) NULL,
                received_at         timestamp NOT NULL DEFAULT now(),
                status              varchar(24) NOT NULL DEFAULT 'received',
                document_id         bigint NULL,
                error               text NULL,
                created_by          bigint NULL,
                created_at          timestamp NOT NULL DEFAULT now(),
                updated_at          timestamp NOT NULL DEFAULT now()
            )
        ");
        $this->run_query("CREATE UNIQUE INDEX IF NOT EXISTS prl_390_messages_sha256_uniq ON prl_390_messages (sha256)");
        $this->run_query("CREATE INDEX IF NOT EXISTS prl_390_messages_status_idx ON prl_390_messages (status, received_at DESC)");
        $this->run_query("CREATE INDEX IF NOT EXISTS prl_390_messages_document_idx ON prl_390_messages (document_id)");

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prl_390_files (
                id              bigserial PRIMARY KEY,
                message_id      bigint NOT NULL REFERENCES prl_390_messages(id) ON DELETE CASCADE,
                file_id         bigint NOT NULL,
                filename        varchar(255) NOT NULL,
                mime            varchar(127) NULL,
                size_bytes      bigint NOT NULL DEFAULT 0,
                sha256          char(64) NOT NULL,
                pages           int NULL,
                created_at      timestamp NOT NULL DEFAULT now()
            )
        ");
        $this->run_query("CREATE INDEX IF NOT EXISTS prl_390_files_message_idx ON prl_390_files (message_id)");
        $this->run_query("CREATE INDEX IF NOT EXISTS prl_390_files_sha_idx ON prl_390_files (sha256)");

        $this->run_query("
            CREATE TABLE IF NOT EXISTS prl_390_texts (
                id              bigserial PRIMARY KEY,
                message_id      bigint NOT NULL REFERENCES prl_390_messages(id) ON DELETE CASCADE,
                file_id         bigint NOT NULL DEFAULT 0,
                text            text NOT NULL,
                chars           int NOT NULL DEFAULT 0,
                pages           int NULL,
                engine          varchar(32) NOT NULL DEFAULT 'pypdf',
                status          varchar(24) NOT NULL DEFAULT 'text_ready',
                confidence      numeric(4,3) NULL,
                created_at      timestamp NOT NULL DEFAULT now(),
                updated_at      timestamp NOT NULL DEFAULT now(),
                UNIQUE (message_id, file_id)
            )
        ");
        $this->run_query("CREATE INDEX IF NOT EXISTS prl_390_texts_message_idx ON prl_390_texts (message_id)");

        $this->run_query("
            CREATE OR REPLACE FUNCTION prl_390_touch_updated_at()
            RETURNS trigger AS $$
            BEGIN NEW.updated_at = now(); RETURN NEW; END;
            $$ LANGUAGE plpgsql
        ");
        foreach (['prl_390_channels', 'prl_390_messages', 'prl_390_texts'] as $table)
        {
            $this->run_query("DROP TRIGGER IF EXISTS {$table}_touch ON {$table}");
            $this->run_query("CREATE TRIGGER {$table}_touch BEFORE UPDATE ON {$table}
                        FOR EACH ROW EXECUTE FUNCTION prl_390_touch_updated_at()");
        }
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS prl_390_texts");
        $this->run_query("DROP TABLE IF EXISTS prl_390_files");
        $this->run_query("DROP TABLE IF EXISTS prl_390_messages");
        $this->run_query("DROP TABLE IF EXISTS prl_390_channels");
        $this->run_query("DROP FUNCTION IF EXISTS prl_390_touch_updated_at()");
    }
}
