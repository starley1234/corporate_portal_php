# AGENTS.md — nj674_mcpguard

- `/mcpguard`: статический анализ MCP `tools/list`, не вызывает MCP-сервер.
- Только admin; POST защищён CSRF; JSON ≤64 КБ, ≤100 tools; исходный JSON не хранится.
- `Service_Nj674_McpGuard::analyze()` — чистый анализ, без сети/БД/LLM.
- Отчёт эвристический; не называть его сертификатом безопасности.
- Проверка: `.tools/nj674_mcpguard_stand.php` через `phpwasm_stand.mjs`.
- Следующий шаг динамики требует отдельного sandbox/allowlist и обязательного подтверждения владельца MCP-сервера.
