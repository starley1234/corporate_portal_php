# AGENTS.md — nj590_design

> Памятка агенту. Правила платформы — в корневом AGENTS.md; здесь — только
> специфика модуля. Обновлять при каждом существенном изменении.

## Назначение
Выбор дизайна страницы: URL + пожелания → воркер скачивает страницу, LLM
делает N вариантов улучшенного дизайна (самодостаточные HTML-файлы),
пользователь выбирает вариант в iframe-превью.

## Состояние
- Готовность: рабочий (нужен воркер с env NJ590_*).
- Владелец: владелец проекта.
- **08.10.2026 — гостевой экран переведён на общую плашку входа** (решение владельца): вместо
  строки «Сервис для зарегистрированных — войдите» гость видит `Gate::wall('design')` — общий
  partial `application/views/partials/auth_wall.php` (`application/classes/Gate.php`): что даёт
  сервис, зачем аккаунт и ОДНА кнопка входа с `return_url=design`. Состав полей и поведение для
  вошедших не менялись.

## Ключевые классы
| Класс | Роль |
|---|---|
| `Controller_Nj590_Design` | страницы /design, /design/<code>, pick, file |
| `Controller_Nj590_Api` | статус; прогресс и приём вариантов от воркера (токен) |
| `Controller_Nj590_Admin` | админка: все прогоны, удаление/возврат |
| `Service_Nj590_Design` | логика: прогоны, варианты, файлы, reconcile, `ensure_tables()` |
| handlers/nj590_design.py | канал nj590.design: скачать → анализ → N вариантов → выгрузка |

## Таблицы (полное владение)
`nj_590_runs`, `nj_590_variants` (DDL: миграция 001 == `ensure_tables()`).

## Маршруты и API
- `GET/POST /design` (POST в action_index), `GET /design/<code>`, `POST /design/<code>/pick`
- `GET /design/<code>/file/<id>` (inline text/html — для iframe-превью)
- `GET /design/api/status/<code>`
- `POST /design/api/progress`, `POST /design/api/upload` (X-Worker-Token)
- порядок маршрутов: api и `<code>/file/<id>` ВЫШЕ `<code>(/<action>)`

## Контракт варианта
Самодостаточный HTML: инлайн-CSS, БЕЗ `<script>`, без внешних ресурсов;
страховка на сервере — `add_variant()` вырезает `<script>` и `on*`.
В UI — `<iframe sandbox>`.

## Env
- сайт: `WORKER_API_TOKEN`
- воркер: `NJ590_API_BASE`, `NJ590_SITE_TOKEN`|`WORKER_API_TOKEN`,
  `NJ590_DESIGN_MODEL` (спека `провайдер::модель`), `NJ590_MAX_PAGE_BYTES`,
  `NJ590_FETCH_TIMEOUT`

## Нюансы
- скачивание страницы — SSRF-щит (`_assert_public_host`): private/loopback/
  link-local/reserved запрещены; лимит 3 МБ / 25 с; не-HTML → понятная ошибка;
- HTML для LLM — ≤48k символов (`_clean_html`), палитра из CSS отдельно;
- по варианту — отдельный LLM-вызов со своим углом подачи (ANGLES), строгий
  JSON {title, concept, html}; upload с 2 попытками, иначе задача fail;
- usage — в `job.result.usage`, reconcile пишет в `nj_590_runs.usage`.
