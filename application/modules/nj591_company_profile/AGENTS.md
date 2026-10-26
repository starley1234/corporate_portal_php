# AGENTS.md — nj591_company_profile

> Памятка агенту. Правила платформы — в корневом AGENTS.md.

## Состояние работ

- **08.10.2026 — гостевой экран переведён на общую плашку входа** (решение владельца): вместо
  строки «Сервис для зарегистрированных — войдите» гость видит `Gate::wall('company')` — общий
  partial `application/views/partials/auth_wall.php` (`application/classes/Gate.php`): что даёт
  сервис, зачем аккаунт и ОДНА кнопка входа с `return_url=company`. Состав полей и поведение для
  вошедших не менялись.

## Назначение
Глубокий профиль компании (домен/название → JSON: сфера, размер, контакты,
стек, ЛПР) для B2B-продаж. Пилот общего инструмента **webread**
(workers/n2j_workers/webread.py): страница → Markdown → малая LLM → JSON.

## Ключевые классы
| Класс | Роль |
|---|---|
| `Controller_Nj591_Company` | /company, /company/<code>, /company/<code>/json |
| `Controller_Nj591_Api` | статус; прогресс/результат от воркера (токен) |
| `Controller_Nj591_Admin` | админка (роль admin) |
| `Service_Nj591_Company` | профили, set_result, reconcile, `ensure_tables()` |
| handlers/nj591_profile.py | канал nj591.profile: сайт → страницы → JSON |
| workers/n2j_workers/webread.py | ОБЩИЙ инструмент (fetch/trafilatura/MD/JSON/DDG) |

## Таблицы (полное владение)
`nj_591_profiles`.

## Маршруты
`/company` (POST в index), `/company/<code>`, `/company/<code>/json` (ВЫШЕ view),
`/company/api/...`, `/nj591/admin`. MCP: nj591_profile_status.

## Env (слаги, не голые номера!)
`NJ591_PROFILE_API_BASE`, `NJ591_PROFILE_SITE_TOKEN|WORKER_API_TOKEN`,
`NJ591_PROFILE_MODEL`, `NJ591_PROFILE_MAX_PAGES`, `NJ591_PROFILE_MD_CAP`, `WEBREAD_*`.

## Нюансы
- результат воркер выгружает POST /company/api/result (двухступенчато с
  ретраем); job.result несёт только usage → reconcile дозаписывает;
- сайт по названию — DDG с блок-листом агрегаторов (_BLOCKED_HOSTS);
- SSRF-щит webread.assert_public_host; приватность: профиль по коду/владельцу.
