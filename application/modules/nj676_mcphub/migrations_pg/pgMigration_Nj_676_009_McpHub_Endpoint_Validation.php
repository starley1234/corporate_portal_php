<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Проверка удалённого адреса на приёмке в каталог.
 *
 * Зачем. Реальный тест (24.10.2026) нашёл сервер `ai-bittlebits-bittlebits`,
 * который в выдаче выглядел как «без авторизации, вызов сразу», а на первом же
 * вызове отвечал `UPSTREAM_ERROR: Адрес сервера не прошёл проверку безопасности`.
 * Причина: адрес проверялся только в момент вызова, а карточка уже была
 * опубликована как готовая. Агент тратил вызов и терял доверие к каталогу.
 *
 * Теперь адрес проверяется ДО публикации (импорт, реестр, ручная правка) и при
 * каждой живой проверке:
 *   * `url_valid` — TRUE/FALSE/NULL (NULL = удалённого адреса нет, локальный);
 *   * `url_note`  — человеческая причина отказа («нужен https и публичный хост»).
 *
 * `readiness()` считает такую карточку `broken` и не обещает вызов.
 *
 * Миграция идемпотентна: ADD COLUMN IF NOT EXISTS.
 */
class pgMigration_Nj_676_009_McpHub_Endpoint_Validation extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS url_valid BOOLEAN');
        $this->run_query("ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS url_note VARCHAR(200) NOT NULL DEFAULT ''");

        // Раздача «что можно вызвать» фильтрует по health_status и адресу.
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_servers_callable_idx
            ON nj_676_servers (is_published, health_status) WHERE remote_url <> \'\';');
    }

    public function down()
    {
        // url_valid — постоянный сигнал качества карточки; откат обнулил бы
        // знание о том, что адрес не проходит проверку.
    }
}
