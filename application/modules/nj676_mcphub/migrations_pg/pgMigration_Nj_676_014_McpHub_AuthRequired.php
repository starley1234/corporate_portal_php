<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Отметка «сервер требует авторизацию».
 *
 * Зачем. Витрина «Отвечают прямо сейчас» обещала вызов, а сервер отвечал
 * AUTH_REQUIRED: карточка знала только `auth_mode = 'none'`, потому что при
 * сборе никто не проверял, пускает ли сервер анонимно (temporal-mcp,
 * 24.10.2026). Обещание, которое не выполняется, дороже отсутствия обещания:
 * агент тратит вызов и делает вывод «шлюз врёт».
 *
 * Решение. Когда живой вызов вернул AUTH_REQUIRED, ставим `auth_required_at`.
 * Пока отметка свежая, сервер не считается готовым к вызову и не попадает в
 * «Отвечают прямо сейчас». Успешный вызов (или ключ в окружении сайта)
 * отметку снимает.
 *
 * Миграция идемпотентна: ADD COLUMN IF NOT EXISTS.
 */
class pgMigration_Nj_676_014_McpHub_AuthRequired extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS auth_required_at TIMESTAMPTZ');

        // «Кто сейчас умеет только здороваться 401» — редкий и маленький запрос.
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_676_servers_auth_required_idx
            ON nj_676_servers (auth_required_at)
            WHERE auth_required_at IS NOT NULL");
    }

    public function down()
    {
        // Отметка — сигнал, а не данные: при откате индекс уходит, колонка
        // остаётся безвредной.
        $this->run_query('DROP INDEX IF EXISTS nj_676_servers_auth_required_idx');
    }
}
