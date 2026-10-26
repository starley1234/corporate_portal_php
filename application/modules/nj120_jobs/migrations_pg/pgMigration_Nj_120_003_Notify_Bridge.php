<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-120/003: мост событий очереди в центр уведомлений.
 *
 * Подписчики erp208 (is_notification = TRUE) проксируют событие в erp217,
 * а там пользователь включает каналы в «Настройках уведомлений».
 * Сами типы событий добавляет миграция erp217/005.
 */
class pgMigration_Nj_120_003_Notify_Bridge extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
INSERT INTO erp_208_event_subscribers (event_name, module_id, callback_method, is_notification, is_active, description)
SELECT 'nj120.job_done', 'nj120', NULL, TRUE, TRUE, 'Задача выполнена — уведомление владельцу через erp217'
WHERE NOT EXISTS (
    SELECT 1 FROM erp_208_event_subscribers
    WHERE event_name = 'nj120.job_done' AND module_id = 'nj120' AND callback_method IS NULL
);

INSERT INTO erp_208_event_subscribers (event_name, module_id, callback_method, is_notification, is_active, description)
SELECT 'nj120.job_failed', 'nj120', NULL, TRUE, TRUE, 'Задача упала — уведомление владельцу через erp217'
WHERE NOT EXISTS (
    SELECT 1 FROM erp_208_event_subscribers
    WHERE event_name = 'nj120.job_failed' AND module_id = 'nj120' AND callback_method IS NULL
);
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $this->run_query("DELETE FROM erp_208_event_subscribers WHERE event_name IN ('nj120.job_done', 'nj120.job_failed') AND module_id = 'nj120';");
    }
}
