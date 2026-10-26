<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj160_mcp (MCP-хаб для ИИ-агентов).
 */

// JSON-RPC 2.0 эндпоинт для MCP-клиентов (агентов)
// Каталог инструментов простым текстом (для слабых агентов/сетей): GET с токеном
Route::set('nj160_mcp_catalog', 'mcp/api/catalog')
    ->defaults([
        'controller' => 'Nj160_Catalog',
        'action'     => 'index',
    ]);

Route::set('nj160_mcp_rpc', 'mcp/api/rpc')
    ->defaults([
        'controller' => 'Nj160_Rpc',
        'action'     => 'index',
    ]);

// Каталог инструментов и инструкция подключения (только управление)
Route::set('nj160_mcp_hub', 'mcp')
    ->defaults([
        'controller' => 'Nj160_Hub',
        'action'     => 'index',
    ]);
