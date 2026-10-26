<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Индексы для горячих путей авторизации и администрирования пользователей.
 * Не меняет данные и безопасно выполняется повторно.
 */
class pgMigration_Erp_110_003_Performance_Indexes extends pgMigration
{
    public function up()
    {
        $this->run_query("CREATE INDEX IF NOT EXISTS users_confirmation_token_idx ON users (confirmation_token) WHERE confirmation_token IS NOT NULL;");
        $this->run_query("CREATE INDEX IF NOT EXISTS users_reset_token_idx ON users (reset_token) WHERE reset_token IS NOT NULL;");
        $this->run_query("CREATE INDEX IF NOT EXISTS roles_users_role_user_idx ON roles_users (role_id, user_id);");
        $this->run_query("CREATE INDEX IF NOT EXISTS users_oauth_user_idx ON users_oauth (user_id);");
    }

    public function down()
    {
        $this->run_query("DROP INDEX IF EXISTS users_confirmation_token_idx;");
        $this->run_query("DROP INDEX IF EXISTS users_reset_token_idx;");
        $this->run_query("DROP INDEX IF EXISTS roles_users_role_user_idx;");
        $this->run_query("DROP INDEX IF EXISTS users_oauth_user_idx;");
    }
}
