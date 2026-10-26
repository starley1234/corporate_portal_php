# nj160_mcp — MCP-хаб для ИИ-агентов

- `POST /mcp/api/rpc` — JSON-RPC 2.0: `initialize`, `tools/list`, `tools/call`, `ping` (Bearer `MCP_TOKEN`)
- `GET /mcp` — страница-хаб со списком инструментов
- Инструменты модулей — `config/mcp.php` каждого модуля (каскад), реестр: `Service_Nj160_Registry`
- Обработчики — статические методы сервисов модулей (`'handler' => 'Service_NjXXX_Y::mcp_z'`)

Инструкции для агента — см. `AGENTS.md` рядом.
