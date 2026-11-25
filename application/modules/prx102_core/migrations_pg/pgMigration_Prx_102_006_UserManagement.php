<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx102: деактивация пользователя и управление подтверждением почты.
 *
 * - users.active: мягкое отключение — роли сохраняются, вход закрыт,
 *   в обычных списках не показывается (prx595 показывает отдельной вкладкой).
 * - AUTH_REQUIRE_EMAIL_CONFIRMATION=0 в .env отключает обязательное
 *   подтверждение почты при регистрации; пользователь, созданный
 *   администратором через prx595, подтверждения не требует вовсе.
 */
class pgMigration_Prx_102_006_UserManagement extends pgMigration {

    public function up()
    {
        $this->run_query("ALTER TABLE users ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT TRUE");
        $this->run_query("CREATE INDEX IF NOT EXISTS idx_users_active ON users (active)");
    }

    public function down()
    {
        $this->run_query("DROP INDEX IF EXISTS idx_users_active");
        $this->run_query("ALTER TABLE users DROP COLUMN IF EXISTS active");
    }
}
