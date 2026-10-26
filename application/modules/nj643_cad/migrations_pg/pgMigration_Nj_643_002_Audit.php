<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Модуль «CAD-детали» (nj643_cad): журнал удалений.
 *
 * Зачем (владелец, 28.09.2026): «Кроме того, еще надо чтобы детали не удалялись
 * просто так (для этого надо сделать архив и запись или что-то в этом духе)».
 * Сама деталь удаляется мягко (`deleted_at`), но её файлы при этом стираются с
 * диска — поэтому единственная память о ней и есть эта строка: кто, что и когда
 * убрал. Журнал показывает /cad/admin в разделе «Удаления».
 *
 * Кто пишет: Service_Nj643_Cad::audit() из delete_part() и delete_revision().
 * Таблица создаётся и самим сервисом (ensure_tables) — модуль обязан подниматься
 * одним git pull, даже если миграции ещё не накатывали.
 *
 * ВАЖНО: базовый класс — `pgMigration` (модуль nj111_migrations), а не
 * `Migration`: так работает Migrator (/migrate_pg).
 */
class pgMigration_Nj_643_002_Audit extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_643_audit" (
    "id"         BIGSERIAL PRIMARY KEY,
    "part_id"    BIGINT       NOT NULL DEFAULT 0,
    "code"       VARCHAR(16)  NOT NULL DEFAULT '',
    "user_id"    BIGINT       NOT NULL DEFAULT 0,
    "action"     VARCHAR(30)  NOT NULL DEFAULT '',
    "title"      VARCHAR(200) NOT NULL DEFAULT '',
    "detail"     TEXT         NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL
        );

        $this->run_query(
            'CREATE INDEX IF NOT EXISTS "nj_643_audit_idx"'
            .' ON "nj_643_audit" ("id" DESC)');

        $this->run_query(
            'CREATE INDEX IF NOT EXISTS "nj_643_audit_part_idx"'
            .' ON "nj_643_audit" ("part_id", "id" DESC)');

        $this->run_query("COMMENT ON TABLE \"nj_643_audit\" IS 'nj643_cad: следы удалений — кто, что и когда убрал'");
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_643_audit"');
    }
}
