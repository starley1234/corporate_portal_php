# nj598_dossier — AI Досье на работодателя (Company Intelligence)

Логическое развитие ветки /jobs: соискатель указывает компанию (или ссылку на
вакансию) → агент собирает открытое досье: отзывы сотрудников, техстатьи
инженеров (реальный стек и «боли»), новости (суды, сокращения, инвестиции).
Результат строго по находкам: **Red Flags**, **Pros**, **Шпаргалка к
собеседованию** (о чём спросить тимлида) и вероятные вопросы скрининга.

## Страницы и API

| URL | Что |
|---|---|
| `/dossier` | создание + «мои досье» (анонам — по куке ubid) |
| `/dossier/<code>` | результат разведки (поллинг статуса) |
| `/dossier/api/status/<code>` | JSON-статус (публично) |
| `/dossier/api/progress` | прогресс воркера (X-Worker-Token) |
| `/dossier/api/result` | приём результата (X-Worker-Token) |
| `/dossier/api/fail` | ошибка воркера (X-Worker-Token) |

Кнопки «Досье» на карточках вакансий (/jobs) ведут сюда с префиллом компании.

## Таблица

`nj_598_dossiers` (см. migrations_pg/pgMigration_Nj_598_001_Dossier_Table.php,
идентично ensure_tables()): code UNIQUE, user_id/visitor_id (владелец),
company, status new|running|done|error, progress, result JSONB, job_id,
model_used, usage JSONB. Зависший running (>25 мин без отчёта) переводится в
error при следующем просмотре (reconcile).

## Воркер

`workers/n2j_workers/handlers/nj598_dossier.py`, канал `nj598.dossier`.
Конвейер: бережный DDG (3 группы запросов с паузами, фильтр мусорных доменов)
→ webread (curl_cffi → trafilatura → Markdown ≤ NJ598_DOSSIER_MD_CAP) →
ОДИН LLM-вызов со строгой JSON-схемой → POST на сайт. Анти-галлюцинации:
только находки из источников; данных мало → честное «данных мало».

## Env

Сайт: ничего сверх общего. Воркер (workers/.env):
`NJ598_DOSSIER_API_BASE` (https://n2j.ru/dossier/api),
`NJ598_DOSSIER_SITE_TOKEN` (пусто = общий WORKER_SITE_TOKEN),
`NJ598_DOSSIER_MODEL`, `NJ598_DOSSIER_MD_CAP=1800`,
`NJ598_DOSSIER_MAX_SITES=8`, `NJ598_DOSSIER_SEARCH_DELAY=2.5`.

## Особенности

- вход — анонимам можно (кука ubid); удаление — только своего;
- ошибки API — json 400 с причиной @ файл:строка;
- прогресс пушится поэтапно: поиск → чтение → LLM;
- уведомлений nj597 для анонов нет — результат смотрят по ссылке /dossier/<code>.
