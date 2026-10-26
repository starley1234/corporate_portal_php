<?php defined('SYSPATH') or die('No direct script access.');

/**
 *
 */
class Migration_001_llm_create_tables extends Migration {

    private $tables = [
        'llm_requests',
    ];

    public function up()
    {
        // 1. lib_502_llm_providers
        DB::query(Database::UPDATE, "
            CREATE TABLE IF NOT EXISTS `llm_requests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `provider_name` VARCHAR(50) NOT NULL COMMENT 'Имя провайдера LLM (e.g., openai, openrouter)',
            `model_name` VARCHAR(100) NOT NULL COMMENT 'Имя модели LLM (e.g., gpt-4o, gpt-5-mini)',
            `request_messages` JSON NOT NULL COMMENT 'Отправленные сообщения в формате JSON',
            `request_options` JSON NOT NULL COMMENT 'Дополнительные опции запроса в формате JSON (temperature, max_tokens, tools, tool_choice и т.д.)',
            `response_data` JSON NULL COMMENT 'Полученные данные от LLM в формате JSON',
            `prompt_tokens` INT NULL COMMENT 'Количество токенов в запросе',
            `completion_tokens` INT NULL COMMENT 'Количество токенов в ответе',
            `total_tokens` INT NULL COMMENT 'Общее количество токенов (prompt + completion)',
            `request_timestamp` INT NOT NULL COMMENT 'UNIX-метка времени отправки запроса',
            `response_timestamp` INT NULL COMMENT 'UNIX-метка времени получения ответа',
            `duration_ms` INT NULL COMMENT 'Время выполнения запроса в миллисекундах',
            `status` ENUM('success', 'error') NOT NULL DEFAULT 'success' COMMENT 'Статус выполнения запроса',
            `error_message` TEXT NULL COMMENT 'Сообщение об ошибке, если запрос завершился неудачей',
            `user_id` INT NULL COMMENT 'ID пользователя, если применимо',
            `session_id` VARCHAR(255) NULL COMMENT 'ID сессии, если применимо',
            INDEX `idx_llm_provider_model` (`provider_name`, `model_name`),
            INDEX `idx_llm_request_timestamp` (`request_timestamp`),
            INDEX `idx_llm_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Логирование запросов и ответов к LLM';
                ")->execute();
    }

    public function down()
    {
        // Удаление таблиц в обратном порядке для соблюдения зависимостей (хотя FK не используются, это хорошая практика)
        $reversed_tables = array_reverse($this->tables);

        foreach ($reversed_tables as $table)
        {
            DB::query(Database::UPDATE, "DROP TABLE IF EXISTS `{$table}`;")->execute();
        }
    }
}