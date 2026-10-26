# nj580_orgdocs — Документы организации

- Создание: `/docs` → страница пакета `/docs/<code>` (доступ по коду-капабилити)
- Конвейер воркера: план набора → DuckDuckGo → черновики LLM (JSON-структура) → проход-редактор → сборка файлов кодом
- Форматы: DOCX + PDF по ГОСТ Р 7.0.97-2016 (поля 30/15/20/20 мм, TNR 14, интервал 1,5), таблицы → XLSX, всё — ZIP
- Сборщики файлов: `workers/n2j_workers/docgen/` (python-docx, reportlab, openpyxl)
- Файлы: `/docs/<code>/file/<id>`; API воркера: `/docs/api/{status,doc,upload,storage}`
- Таблицы: `nj_580_packages`, `nj_580_documents`, `nj_580_files`, `nj_580_usage` (DDL дублируется в `Service_Nj580_Orgdocs::ensure_tables()`)
- Воркер: канал `nj580.generate` (handlers/nj580_docs.py), типы документов — `config/nj580_orgdocs.php` = `DOC_TYPES` в хендлере

Инструкции для агента — см. `AGENTS.md` рядом.
