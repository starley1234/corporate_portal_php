<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Файл инициализации модуля prx208_events.
 * Регистрирует маршруты, привязывает обработчики событий и выполняет другие необходимые действия
 * при загрузке модуля.
 *
 * @package Prx208
 * @version 1.0.0
 * @author AI
 */

// --- 1. Регистрация роутов ---
// Используем ID модуля (208) в именах роутов для уникальности.

/**
 * Роут для API модуля prx208_events.
 * Обрабатывает AJAX-запросы и предоставляет JSON-ответы.
 * Пример: /prx208_events/api/publish_event, /prx208_events/api/get_events
 */
Route::set('prx208_events_api', 'prx208_events/api(/<action>(/<id>))')
    ->defaults(array(
        'controller' => 'Prx208_Api',
        'action'     => 'index', // Можно установить 'get_events' как действие по умолчанию, если это логично
    ));

/**
 * Роут для виджетов модуля prx208_events.
 * Используется для HMVC-вызовов, например, для встраивания виджетов на дашборд.
 * Пример: /prx208_events/widget/latest_events
 */
Route::set('prx208_events_widget', 'prx208_events/widget(/<action>(/<id>))')
    ->defaults(array(
        'controller' => 'Prx208_Widget',
        'action'     => 'latest_events', // Действие по умолчанию для виджета
    ));

/**
 * Основной роут для модуля prx208_events.
 * Обрабатывает запросы к пользовательскому интерфейсу модуля.
 * Пример: /prx208_events, /prx208_events/list_events, /prx208_events/view_event/123
 */
Route::set('prx208_events', 'prx208_events(/<action>(/<id>))')
    ->defaults(array(
        'controller' => 'Prx208_Events',
        'action'     => 'index', // Действие по умолчанию для основного контроллера
    ));

// --- 2. Регистрация событий Event::listen ---
// Модуль prx208_events сам по себе является шиной событий,
// поэтому он не "слушает" события напрямую в init.php, а предоставляет Service_Prx208_EventBus
// для других модулей, чтобы они могли слушать события.
// Однако, если бы prx208_events должен был реагировать на какие-то события из других модулей
// для своей внутренней логики (например, для логирования или статистики),
// то это делалось бы здесь.

// Пример: Если бы модуль prx208_events должен был логировать все события,
// которые публикуются через его шину, он мог бы подписаться на "все" события
// или на специфические события, если бы EventBus имел такую функциональность.
// В текущей реализации EventBus сам сохраняет события в БД при публикации.

// Если бы требовалась реакция на событие из другого модуля, например,
// когда пользователь авторизуется (гипотетическое событие 'auth.user_logged_in'),
// и prx208_events должен был бы что-то сделать:
/*
Event::listen('auth.user_logged_in', function($user_data) {
    // Логика обработки события авторизации пользователя
    Kohana::$log->add(Log::INFO, 'User :username logged in. User ID: :id', array(
        ':username' => $user_data['username'],
        ':id'       => $user_data['user_id']
    ));
    // Возможно, обновить статус пользователя в какой-то внутренней таблице prx208_events
});
*/

// В данном случае, основная логика "слушания" событий будет происходить
// через Service_Prx208_EventBus, который будет загружать подписчиков из БД
// (таблица `prx_208_event_subscribers`) и вызывать их callback-методы.
// Поэтому здесь нет прямых Event::listen, которые бы регистрировали слушателей
// для самого модуля prx208_events, так как он является "ядром" событийной системы.

// --- 3. Другие необходимые действия ---

// Загрузка конфигурации модуля
Kohana::$config->load('prx208_events');

// Инициализация EventBus (если требуется ранняя инициализация или загрузка подписчиков)
// Service_Prx208_EventBus::instance();
// EventBus обычно инициализируется при первом обращении к нему,
// но если есть необходимость загрузить всех активных подписчиков из БД
// сразу при старте приложения, это можно сделать здесь.
// Например, EventBus может иметь метод `load_subscribers_from_db()`.
/*
try {
    Service_Prx208_EventBus::instance()->load_subscribers_from_db();
} catch (Exception $e) {
    Kohana::$log->add(Log::ERROR, 'Failed to load event subscribers from DB: :error', array(':error' => $e->getMessage()));
}
*/

// Добавление переводов для модуля
I18n::load('prx208_events', APPPATH . 'modules/prx208_events/i18n/ru.php');

// Проверка и применение миграций (если используется система миграций)
// Это обычно делается через отдельный скрипт или в процессе установки/обновления,
// но для автоматического применения при загрузке модуля можно добавить вызов.
/*
if (Kohana::$environment === Kohana::DEVELOPMENT) {
    try {
        // Пример вызова миграций, если у вас есть класс для их управления
        // Migration_Manager::run_migrations('prx208_events');
    } catch (Exception $e) {
        Kohana::$log->add(Log::ERROR, 'Error running migrations for prx208_events: :error', array(':error' => $e->getMessage()));
    }
}
*/