<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-160 «MCP-хаб»: единая точка Model Context Protocol для ИИ-агентов.
 *
 * Модули регистрируют свои инструменты декларацией config/mcp.php:
 *
 *   return ['tools' => [
 *       [
 *           'name'        => 'nj550_recent_searches',
 *           'description' => 'Последние поиски вакансий',
 *           'inputSchema' => ['type' => 'object', 'properties' => [...], 'required' => []],
 *           'handler'     => 'Service_Nj550_Jobs::mcp_recent',   // static call
 *       ],
 *   ]];
 *
 * Хаб собирает их автоматически (каскад по модулям) и отдаёт по JSON-RPC 2.0:
 *   POST /mcp/api/rpc   {jsonrpc, id, method: initialize|tools/list|tools/call|ping}
 * Аутентификация: заголовок Authorization: Bearer <MCP_TOKEN из .env сайта>.
 * Пустой токен = API закрыт (кроме KOHANA_ENV=development).
 *
 * Каталог инструментов для человека: /mcp (только управление).
 */

return [
    // Имя сервера в ответе initialize
    'server_name'    => 'n2j-mcp',
    'server_version' => '1.0.0',

    // Максимальная длина текстового результата (защита от гигантских ответов)
    'max_result_chars' => 60000,
];
