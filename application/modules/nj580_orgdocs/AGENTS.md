# AGENTS.md — nj580_orgdocs

> Памятка агенту. Правила платформы — в корневом AGENTS.md; здесь — только
> специфика модуля. Обновлять при каждом существенном изменении.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/nj580_orgdocs/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Назначение | 19 | 0,3 КБ |
|   Состояние | 23 | 0,8 КБ |
|   Ключевые классы | 32 | 0,7 КБ |
|   Таблицы (полное владение) | 42 | 0,1 КБ |
|   Маршруты и API | 45 | 0,3 КБ |
|   Контент документа (JSON в nj_580_documents.content) | 51 | 0,4 КБ |
|   Env | 57 | 0,4 КБ |
|   Нюансы (не ломать) | 64 | 1,0 КБ |
<!-- context-index:end -->
## Назначение
Пакет административных/кадровых документов организации (Word + PDF, таблицы
в Excel, ZIP-архив) по сведениям об организации. Оформление — ГОСТ Р 7.0.97-2016.

## Состояние
- Готовность: рабочий (нужен воркер с env NJ580_* и шрифтами для PDF).
- Владелец: владелец проекта.
- **08.10.2026 — гостевой экран переведён на общую плашку входа** (решение владельца): вместо
  строки «Сервис для зарегистрированных — войдите» гость видит `Gate::wall('docs')` — общий
  partial `application/views/partials/auth_wall.php` (`application/classes/Gate.php`): что даёт
  сервис, зачем аккаунт и ОДНА кнопка входа с `return_url=docs`. Состав полей и поведение для
  вошедших не менялись.

## Ключевые классы
| Класс | Роль |
|---|---|
| `Controller_Nj580_Orgdocs` | страницы /docs, /docs/<code>, regen, скачивание |
| `Controller_Nj580_Api` | статус; приём документов/файлов от воркера (токен) |
| `Controller_Nj580_Admin` | пакеты и затраты LLM |
| `Service_Nj580_Orgdocs` | логика: пакеты, документы, файлы, `ensure_tables()` |
| handlers/nj580_docs.py | канал nj580.generate: LangGraph (план→поиск→тексты→проверка→файлы) |
| workers/n2j_workers/docgen/ | билдеры DOCX/PDF/XLSX/ZIP (код, не LLM) |

## Таблицы (полное владение)
`nj_580_packages`, `nj_580_documents`, `nj_580_files`, `nj_580_usage`.

## Маршруты и API
- `GET/POST /docs` (POST в action_index), `GET /docs/<code>`, `POST /docs/<code>/regen`
- `GET /docs/<code>/file/<id>`
- `GET /docs/api/status/<code>` (капабилити-код)
- `POST /docs/api/doc`, `POST /docs/api/upload`, `GET /docs/api/storage` (X-Worker-Token)

## Контент документа (JSON в nj_580_documents.content)
`{title, subtitle, org_name, needs_title_page, approve_line, city_year,
sections:[{heading, blocks:[{type: p|ul|table|note, …}]}]}` — эту структуру
пишет LLM (воркер), билдеры превращают в файлы. Ключи типов — 1:1 между
`config/nj580_orgdocs.php` и `DOC_TYPES` в хендлере.

## Env
- сайт: `WORKER_API_TOKEN`
- воркер: `NJ580_API_BASE` (напр. https://n2j.ru/docs/api), `NJ580_SITE_TOKEN`,
  `NJ580_DOC_MODEL/PLAN_MODEL/QA_MODEL` (спеки `провайдер::модель`),
  `NJ580_MAX_DOCS`, `NJ580_SEARCH_MAX/SEARCH_DELAY` (бережный ddgs)
- PDF требует TTF (fonts-liberation / dejavu): `python3 workers/nj580_doctor.py`

## Нюансы (не ломать)
- `ensure_tables()` — самовосстановление DDL (миграции nj111 на проде ручные).
- langgraph опционален: без него хендлер идёт по шагам последовательно
  (фолбэк только на ImportError пакета, ошибки графа наружу).
- Таймер баннера — формат `М:СС` постоянной ширины (tabular-nums, min-width).
- Страницы nj580 — `Cache-Control: no-cache`; вёрстка `md:grid-cols-2`.
- Дисклеймер в UI: документы — шаблоны, не заменяют юриста.
- Замочек «Сервис для зарегистрированных — войдите…» на /docs, /docs/custom и
  /docs/wizard — ссылка `/auth/login?return_url=<страница>`: после входа
  пользователь возвращается на ту же страницу (Controller_Auth, стенд
  .tools/auth_return_stand.php).
