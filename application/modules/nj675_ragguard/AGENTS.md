# AGENTS.md — nj675_ragguard

- `/ragguard`: локальный preflight извлечённого RAG-контекста, без внешней сети.
- Только admin; POST защищён CSRF; JSON ≤128 КБ, ≤100 документов, текст каждого ≤20 КБ.
- `Service_Nj675_RagGuard::analyze()` не сохраняет/не возвращает исходный текст и не зовёт модель.
- `expected_tenant` сравнивается с tenant каждого документа; `trusted` не отключает проверку instruction-like текста.
- Эвристики могут давать ложные срабатывания и пропуски; не выдавать результат за динамический аудит ACL.
- Проверка: `.tools/nj675_ragguard_stand.php` через `phpwasm_stand.mjs`.
- Следующий этап динамики требует sandbox и явного allowlist целей/корпусов.
