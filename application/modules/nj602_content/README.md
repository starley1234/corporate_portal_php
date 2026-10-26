# nj602_content — «Контент-конвейер»

Автостатьи: воркер собирает свежий контекст из интернета, LLM пишет статью
(тело Markdown-лайт, промпт для будущей иллюстрации, mermaid-схема, граф
сущностей для сетевой диаграммы, источники). Публикация — после модерации
человеком на `/nj602/admin` (авто-режим: `NJ602_AUTO_PUBLISH=1`, пока выключен).

- `/content` — лента, `/content/<slug>` — статья (SEO: canonical, og, sitemap.xml).
- API воркера: `/content/api/{create,progress/<code>,item/<code>,fail/<code>}` (X-Worker-Token).
- Канал очереди: `nj602.article` (max_attempts=1, timeout 900); зависший сбор
  старше 2 ч автоматически переводится в error (конвейер не встаёт).
- Расписание — «Пиналка» (nj603), темы — `config/nj602_content.php`.
- Env воркера: `NJ602_CONTENT_API_BASE`, `NJ602_CONTENT_SITE_TOKEN` (или общий
  `WORKER_SITE_TOKEN` → `WORKER_API_TOKEN`), `NJ602_CONTENT_MODEL`.
