# nj570_models — 3D-модель по тексту и фото (Sketch 2 Solid)

- Создание: `/3d` → страница модели `/3d/<code>` (доступ по коду-капабилити)
- Конвейер: LLM пишет OpenSCAD (BOSL2) → воркер рендерит локально → STL+PNG на сайт
- Вьюер: CPU-рендер на Canvas 2D (без WebGL), PNG воркера — подложка; настройки в куках, ползунки в IndexedDB
- Ревизии: правки текстом/фото, уточняющие вопросы, пересборка STL ползунками
- Файлы: `/3d/<code>/file/<id>`; API воркера: `/3d/api/{status,upload,storage}`
- Таблицы: `nj_570_models`, `nj_570_revisions`, `nj_570_files`, `nj_570_ratings`, `nj_570_usage`
- Воркер: каналы `nj570.generate | nj570.clarify | nj570.render` (handlers/nj570_build.py)

Инструкции для агента — см. `AGENTS.md` рядом.
