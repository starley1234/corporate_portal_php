<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция для создания таблицы шаблонов промптов
 */
class Migration_002_llm_create_tables extends Migration {

    private $tables = [
        'llm_prompts',
    ];

    public function up()
    {
        // 1. llm_prompts
        DB::query(Database::UPDATE, "
            CREATE TABLE IF NOT EXISTS `llm_prompts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL COMMENT 'Уникальное имя шаблона для вызова в коде',
            `description` VARCHAR(255) NULL COMMENT 'Описание назначения шаблона',
            `system` TEXT NULL COMMENT 'Системный промпт (инструкция роли)',
            `prompt` TEXT NOT NULL COMMENT 'Текст основного промпта с переменными {{var}}',
            `provider_name` VARCHAR(50) NULL COMMENT 'Принудительный провайдер для этого шаблона',
            `model_name` VARCHAR(100) NULL COMMENT 'Принудительная модель для этого шаблона',
            `options` JSON NULL COMMENT 'Дополнительные параметры (temperature, response_format и т.д.)',
            `developer_notes` TEXT NULL COMMENT 'Технические примечания разработчика',
            `created_at` INT NOT NULL COMMENT 'Дата создания (timestamp)',
            `updated_at` INT NOT NULL COMMENT 'Дата обновления (timestamp)',
            UNIQUE INDEX `idx_llm_prompt_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Справочник шаблонов промптов для LLM';
        ")->execute();

        // 2. Вставка базовых шаблонов
        $now = time();
        $default_prompts = [
            [
                'name' => 'email_notify',
                'description' => 'Приветственное письмо после активации',
                'system' => 'Вы — профессиональный менеджер по работе с клиентами. Пишите вежливо и лаконично.',
                'prompt' => 'Напиши приветственное письмо для пользователя по имени {{user}}. Сообщи ему, что его аккаунт успешно активирован и он может приступать к работе в системе.',
                'developer_notes' => 'Используется в модуле регистрации. Переменные: {{user}}',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'text_summarize',
                'description' => 'Саммари текста',
                'system' => 'Ты помощник, который делает краткие выжимки из длинных текстов.',
                'prompt' => 'Выдели основные тезисы из следующего текста: {{text}}',
                'developer_notes' => 'Общий шаблон для сокращения контента. Переменные: {{text}}',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        ];

        foreach ($default_prompts as $data) {
            DB::insert('llm_prompts', array_keys($data))
                ->values(array_values($data))
                ->execute();
        }
    }

    public function down()
    {
        foreach ($this->tables as $table)
        {
            DB::query(Database::UPDATE, "DROP TABLE IF EXISTS `{$table}`;")->execute();
        }
    }
}