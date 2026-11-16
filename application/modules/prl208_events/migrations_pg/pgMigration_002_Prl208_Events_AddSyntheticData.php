<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_002_Prl208_Events_AddSyntheticData extends pgMigration {

    // protected $_db_group = 'default';

    public function up()
    {
        // Добавление синтетических данных в таблицу prl_208_events
        $this->run_query("
            INSERT INTO prl_208_events (event_name, payload) VALUES
            ('prl502.task_done', '{\"task_id\":1,\"user_id\":101,\"status\":\"completed\",\"description\":\"Initial task completion\"}'),
            ('prl502.task_created', '{\"task_id\":2,\"user_id\":102,\"status\":\"pending\",\"title\":\"New task for John Doe\"}'),
            ('prl301.order_placed', '{\"order_id\":1001,\"customer_id\":201,\"amount\":150.75,\"currency\":\"USD\"}'),
            ('prl301.order_shipped', '{\"order_id\":1001,\"tracking_number\":\"TRK123456\",\"carrier\":\"UPS\"}')
            ON CONFLICT DO NOTHING;
        ");

        // Добавление синтетических данных в таблицу prl_208_event_subscribers
        $this->run_query("
            INSERT INTO prl_208_event_subscribers (event_name, module_id, callback_method, is_active) VALUES
            ('prl502.task_done', 'notifications', 'Model_Notifications_Service::sendTaskCompletionNotification', TRUE),
            ('prl502.task_created', 'notifications', 'Model_Notifications_Service::sendNewTaskNotification', TRUE),
            ('prl301.order_placed', 'crm', 'Model_CRM_Service::processNewOrder', TRUE),
            ('prl301.order_shipped', 'crm', 'Model_CRM_Service::updateOrderStatus', TRUE),
            ('prl502.task_done', 'reporting', 'Model_Reporting_Service::logTaskEvent', TRUE)
            ON CONFLICT DO NOTHING;
        ");

        // Добавление ролей
        $this->run_query("
            INSERT INTO roles (name, description) VALUES
            ('208_event_publisher', 'Пользователь, который может инициировать события модуля 208'),
            ('208_event_listener', 'Пользователь, который может просматривать или обрабатывать события модуля 208')
            ON CONFLICT (name) DO NOTHING;
        ");
    }

    public function down()
    {
        // Удаление связей между ролями и пользователями
        $this->run_query("
            DELETE FROM roles_users
            WHERE role_id IN (SELECT id FROM roles WHERE name IN ('208_event_publisher', '208_event_listener'));
        ");

        // Удаление ролей
        $this->run_query("
            DELETE FROM roles WHERE name IN ('208_event_publisher', '208_event_listener');
        ");

        // Удаление синтетических данных из таблицы prl_208_event_subscribers
        $this->run_query("
            DELETE FROM prl_208_event_subscribers WHERE module_id IN ('notifications', 'crm', 'reporting');
        ");

        // Удаление синтетических данных из таблицы prl_208_events
        $this->run_query("
            DELETE FROM prl_208_events WHERE event_name IN ('prl502.task_done', 'prl502.task_created', 'prl301.order_placed', 'prl301.order_shipped');
        ");
    }
}