# nj592_coldmail — «Холодное письмо»

Компания + «что предлагаем» (+ профиль nj591, аудитория, тон) → воркер
собирает факты (готовый профиль nj591 из payload — ноль обращений к сайту,
или свежий обход webread: главная + новости + DDG-новости) → один LLM-вызов →
N вариантов первого абзаца {subject, paragraph, why}. Анти-галлюцинации:
крючок — только из материалов; нет фактов — нейтральный заход без выдумок;
запрещены спам-штампы.

## Адреса
`/coldmail`, `/coldmail/<code>`, `/coldmail/api/{status,progress,result}`,
`/nj592/admin` (роль admin). MCP: `nj592_letter_status`.

## Данные
`nj_592_letters` (result/sources/usage JSONB). DDL: миграция 001 == ensure_tables().

## Env воркера
`NJ592_EMAIL_API_BASE` (https://n2j.ru/coldmail/api), `NJ592_EMAIL_SITE_TOKEN`
(или `WORKER_API_TOKEN`), `NJ592_EMAIL_MODEL`, `NJ592_EMAIL_MAX_VARIANTS` (5).

## Канал
`nj592.email` → `handlers/nj592_email.py`. Профиль nj591 приходит В PAYLOAD
(читается PHP из nj_591_profiles) — межмодульная связка без HTTP.
