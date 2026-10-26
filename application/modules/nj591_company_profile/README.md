# nj591_company_profile — «Профиль компании»

URL или название → воркер через общий конвейер **webread** (curl_cffi →
trafilatura → Markdown ≤2.2 КБ/страница, малые LLM) обходит Главную, «О нас»,
«Контакты», «Команда» (до 4 страниц) и один LLM-вызов собирает строгий JSON:
сфера, примерный размер, контакты, стек технологий, ЛПР. Анти-галлюцинации:
имена/цифры/контакты — только из текста страниц; стек определяется ещё и
детерминированно (webread.tech_hints).

## Адреса
`/company`, `/company/<code>`, `/company/<code>/json` (файл результата),
`/company/api/{status,progress,result}` (progress/result — X-Worker-Token),
`/nj591/admin` (роль admin). MCP: `nj591_profile_status`.

## Данные
`nj_591_profiles` (result JSONB, sources JSONB, usage JSONB). Файлов нет —
только JSON в БД. DDL: миграция 001 == `ensure_tables()`.

## Env воркера
`NJ591_PROFILE_API_BASE` (https://n2j.ru/company/api), `NJ591_PROFILE_SITE_TOKEN`
(или `WORKER_API_TOKEN`), `NJ591_PROFILE_MODEL` (спека, для малых LLM —
напр. Gemma), `NJ591_PROFILE_MAX_PAGES` (4), `NJ591_PROFILE_MD_CAP` (2200).
Общие: `WEBREAD_*` (см. workers/n2j_workers/webread.py).

## Канал
`nj591.profile` → `handlers/nj591_profile.py`. Зависимости: trafilatura,
lxml_html_clean, markdownify, curl_cffi (опциональны в рантайме), ddgs.
