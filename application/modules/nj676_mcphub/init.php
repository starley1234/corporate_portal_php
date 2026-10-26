<?php defined('SYSPATH') or die('No direct script access.');

// --- MCP-эндпоинт для агентов (Streamable HTTP) ---------------------------
//
// /mcphub/mcp — то, что подключают клиенты (Claude, Cursor, Codex, n8n).
// /mcphub/api/rpc — тот же обработчик по старому адресу: у кого-то он уже
// прописан в конфиге, ломать такие конфиги нельзя.
Route::set('nj676_mcphub_mcp', 'mcphub/mcp')
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'mcp']);

// Специфичные маршруты до общего /mcphub/<slug>.

Route::set('nj676_mcphub_api_search', 'mcphub/api/search')
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_search']);

Route::set('nj676_mcphub_api_rpc', 'mcphub/api/rpc')
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_rpc']);

// Самопроверка шлюза. Публичная и без параметров, кроме одного флага:
// GET /mcphub/api/selfcheck        — проверки внутри процесса;
// GET /mcphub/api/selfcheck?http=1 — плюс настоящий POST на свой же эндпоинт.
// Нужна и человеку («а он точно работает?»), и после деплоя: одна ссылка
// показывает, живой ли шлюз, вместо «подключите клиент и посмотрите».
Route::set('nj676_mcphub_api_selfcheck', 'mcphub/api/selfcheck')
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_selfcheck']);

// Живые инструменты одного сервера (карточка + проверка «а он работает?»).
Route::set('nj676_mcphub_api_tools', 'mcphub/api/tools/<slug>', ['slug' => '[a-z0-9-]+'])
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_tools']);

// Отзывы агентов и карточка сервера в JSON (чтение доверия, тегов и комментариев).
Route::set('nj676_mcphub_api_feedback', 'mcphub/api/feedback(/<slug>)', ['slug' => '[a-z0-9-]+'])
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_feedback']);

Route::set('nj676_mcphub_api_reviews', 'mcphub/api/reviews(/<slug>)', ['slug' => '[a-z0-9-]+'])
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_feedback']);

Route::set('nj676_mcphub_api_server_feedback', 'mcphub/api/server/<slug>/feedback', ['slug' => '[a-z0-9-]+'])
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_feedback']);

Route::set('nj676_mcphub_api_server', 'mcphub/api/server(/<slug>)', ['slug' => '[a-z0-9-]+'])
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_server']);

Route::set('nj676_mcphub_api_gems', 'mcphub/api/gems')
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_gems']);

// Живой статус прогона автосборки (поллинг админской панели).
Route::set('nj676_mcphub_api_status', 'mcphub/api/status(/<id>)', ['id' => '[0-9]+'])
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_status']);

// Карточки без русского перевода — порция для воркера (X-Worker-Token).
Route::set('nj676_mcphub_api_translate_candidates', 'mcphub/api/translate-candidates')
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_translate_candidates']);

// ИИ-анализ каталога: снимок данных для модели (воркер) и приём отчёта.
// Оба адреса — под X-Worker-Token: снимок содержит внутренние числа, а отчёт
// принимается только от воркера.
Route::set('nj676_mcphub_api_analysis_get', 'mcphub/api/analysis-get')
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_analysis_get']);

Route::set('nj676_mcphub_api_analysis_finish', 'mcphub/api/analysis-finish')
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_analysis_finish']);

// Пинок по расписанию (nj603) — автосборка только при NJ676_AUTO_DISCOVER=1.
Route::set('nj676_mcphub_api_tick', 'mcphub/api/tick')
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_tick']);

/**
 * Приёмка от воркера (X-Worker-Token): воркер сам проверяет токен в действии.
 *
 * Дефис в адресе = подчёркивание, а сегмент адреса НЕ подменяет действие:
 * имя действия задаёт маршрут (api_worker), а конкретную приёмку контроллер
 * читает из параметра `what`. Иначе Kohana ищет action_discover-item и отдаёт
 * 404 (урок 08.10.2026, §24.5).
 */
Route::set('nj676_mcphub_api_worker', 'mcphub/api/<what>',
    ['what' => 'discover-state|discover-candidates|discover-item|discover-progress|discover-finish|registry-batch|registry-finish|probe|translate-item|translate-finish|audit-item'])
    ->filter(function ($route, $params, $request)
    {
        if ( ! empty($params['what']))
        {
            $params['what'] = str_replace('-', '_', (string) $params['what']);
        }

        $params['action'] = 'api_worker';

        return $params;
    })
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'api_worker']);

Route::set('nj676_mcphub_admin', 'mcphub/admin(/<action>)', ['action' => '[a-z0-9_-]+'])
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'admin']);

Route::set('nj676_mcphub_export', 'mcphub/export/<slug>', ['slug' => '[a-z0-9-]+'])
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'export']);

Route::set('nj676_mcphub_server', 'mcphub/<slug>', ['slug' => '[a-z0-9-]+'])
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'server']);

Route::set('nj676_mcphub_index', 'mcphub')
    ->defaults(['controller' => 'Nj676_McpHub', 'action' => 'index']);
