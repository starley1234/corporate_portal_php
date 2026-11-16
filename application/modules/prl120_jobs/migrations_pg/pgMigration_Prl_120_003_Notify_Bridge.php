<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRL-120/003: мост событий очереди в центр уведомлений.
 *
 * Подписчики prl208 (is_notification = TRUE) проксируют событие в prl217,
 * а там пользователь включает каналы в «Настройках уведомлений».
 * Сами типы событий добавляет миграция prl217/005.
 */
class pgMigration_Prl_120_003_Notify_Bridge extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
INSERT INTO prl_208_event_subscribers (event_name, module_id, callback_method, is_notification, is_active, description)
SELECT 'prl120.job_done', 'prl120', NULL, TRUE, TRUE, 'Задача выполнена — уведомление владельцу через prl217'
WHERE NOT EXISTS (
    SELECT 1 FROM prl_208_event_subscribers
    WHERE event_name = 'prl120.job_done' AND module_id = 'prl120' AND callback_method IS NULL
);

INSERT INTO prl_208_event_subscribers (event_name, module_id, callback_method, is_notification, is_active, description)
SELECT 'prl120.job_failed', 'prl120', NULL, TRUE, TRUE, 'Задача упала — уведомление владельцу через prl217'
WHERE NOT EXISTS (
    SELECT 1 FROM prl_208_event_subscribers
    WHERE event_name = 'prl120.job_failed' AND module_id = 'prl120' AND callback_method IS NULL
);
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $this->run_query("DELETE FROM prl_208_event_subscribers WHERE event_name IN ('prl120.job_done', 'prl120.job_failed') AND module_id = 'prl120';");
    }
}
