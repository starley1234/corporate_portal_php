<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRX-120/003: мост событий очереди в центр уведомлений.
 *
 * Подписчики prx208 (is_notification = TRUE) проксируют событие в prx217,
 * а там пользователь включает каналы в «Настройках уведомлений».
 * Сами типы событий добавляет миграция prx217/005.
 */
class pgMigration_Prx_120_003_Notify_Bridge extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
INSERT INTO prx_208_event_subscribers (event_name, module_id, callback_method, is_notification, is_active, description)
SELECT 'prx120.job_done', 'prx120', NULL, TRUE, TRUE, 'Задача выполнена — уведомление владельцу через prx217'
WHERE NOT EXISTS (
    SELECT 1 FROM prx_208_event_subscribers
    WHERE event_name = 'prx120.job_done' AND module_id = 'prx120' AND callback_method IS NULL
);

INSERT INTO prx_208_event_subscribers (event_name, module_id, callback_method, is_notification, is_active, description)
SELECT 'prx120.job_failed', 'prx120', NULL, TRUE, TRUE, 'Задача упала — уведомление владельцу через prx217'
WHERE NOT EXISTS (
    SELECT 1 FROM prx_208_event_subscribers
    WHERE event_name = 'prx120.job_failed' AND module_id = 'prx120' AND callback_method IS NULL
);
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $this->run_query("DELETE FROM prx_208_event_subscribers WHERE event_name IN ('prx120.job_done', 'prx120.job_failed') AND module_id = 'prx120';");
    }
}
