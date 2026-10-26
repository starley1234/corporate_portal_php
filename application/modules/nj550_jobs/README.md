# Работа 2 Оффер (nj550_jobs)

**Job 2 Offer** — город + направление → вакансии с сайтов работы и карьерных
страниц компаний. Воркер бережно ищет через DuckDuckGo (`ddgs`), LangChain-цепь
отсеивает статьи/форумы, сортирует по релевантности и пишет краткое резюме
рынка. Без регистрации.

## Как устроено

| Слой | Что |
|---|---|
| `Controller_Nj550_Jobs` | `/jobs` — форма, `/jobs/<code>` — статус/результат |
| `Controller_Nj550_Api` | JSON: `GET /nj550/api/status/<code>` |
| `Service_Nj550_Jobs` | валидация, лимиты, очередь, куки предпочтений, reconcile |
| воркер `nj550.jobs` | ddgs (паузы!) → fetch (выкл по умолч.) → LangChain-ранжирование |
| `nj_550_searches` | запросы: visitor_id, город, направление, статус, summary |
| `nj_550_results` | вакансии: UNIQUE(search_id, url_hash) — идемпотентно |

## Идентификация и предпочтения

- Пользователь не обязателен: общий класс `Visitor` (application/classes/Visitor.php)
  даёт `uid()` = `u:<user_id>` или `g:<ubid>` (кука `ubid`, год).
- Город и направление запоминаются в куки `nj550_city` / `nj550_direction` —
  форма предзаполняется; недавние поиски — из БД по visitor_id (блок «Вы искали»).

## Настройки

`config/nj550_jobs.php` (PHP): лимиты 8/час, 30/день; коды, подсказки городов.
Воркер (env, `workers/.env.example`): `NJ550_MAX_QUERIES`, `NJ550_SEARCH_DELAY`
(пауза между запросами к поисковику — не ужесточать), `NJ550_MAX_RESULTS`,
`NJ550_FETCH_PAGES` (0 = не качать страницы), `NJ550_REGION`, `NJ550_MODEL`.

## API

    GET /nj550/api/status/<code>
      → {"ok":true,"status":"new|done|error","summary":"...","error":null}
