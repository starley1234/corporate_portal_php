# NJ-605 · Радар заказов (nj605_radar)

## Состояние
- **Сервис** «Радар заказов» (`/radar`): пользователь описывает, что ищет
  (продукт/работа/услуга + регион), платформа раз в ~30 минут обходит открытые
  источники и приносит находки спроса (закупки, запросы поставщиков, объявления)
  с оценкой релевантности `score 0–100` и пояснением `why`.
- **Beta** (`badge: beta`), публичный страничный вход, но создавать/получать
  находки могут только зарегистрированные (нужна привязка `user_id`).
- Горячие находки (`score >= 85`) уведомляются колокольчиком nj597 (не чаще
  раза в час на пользователя).
- Пауза/включение наблюдения («селектор enabled»), лимит радаров на пользователя.
- **08.10.2026 — гостевой экран переведён на общую плашку входа** (решение владельца): вместо
  строки «Сервис для зарегистрированных — войдите» гость видит `Gate::wall('radar')` — общий
  partial `application/views/partials/auth_wall.php` (`application/classes/Gate.php`): что даёт
  сервис, зачем аккаунт и ОДНА кнопка входа с `return_url=radar`. Состав полей и поведение для
  вошедших не менялись.

## Конвейер (не в HTTP)
- Пиналка nj603 → `POST /radar/api/collect` (авторизация воркера — заголовок
  `X-Worker-Token` = `WORKER_API_TOKEN`) → задачи `nj120` канала **`nj605.radar`** →
  python-воркер (`workers/n2j_workers/handlers/nj605_radar.py`) → находки →
  `POST /radar/api/items`.
- Дубль-задачи не ставятся: у радара задача в очереди ИЛИ сбор младше 25 минут
  (`api_collect` выбирает `enabled='t'` и давно не запускавшиеся).
- Первый сбор ставится сразу при создании радара (не ждём пиналку).
- Воркер: ddgs-поиск по нескольким формулировкам (см. ниже), таргетированный
  `site:`-поиск по площадкам закупок, чтение топ-страниц, LLM-скоринг. Дедуп
  находок — по URL (не по хосту), чтобы агрегаторы тендеров (zakupki.gov.ru,
  rostender.info…) давали несколько найденных заказов. Каждая находка несёт
  `source` — host площадки, с которой пришла.

## Классы и файлы
- `Service_Nj605_Radar` — CRUD радаров/находок, пауза (`set_enabled`), лимит
  (`count_of`, `max_watches`), feed, очередь, admin, API воркера.
- `Controller_Nj605_Radar` — страница `/radar` (мои радары + лента находок).
- `Controller_Nj605_Api`  — API воркера (`collect`, `items`).
- `Controller_Nj605_Admin` — `/radar/admin` (все радары/находки, пауза, удаление).
- `views/605/index.php`, `views/605/form.php`, `views/605/card.php`, `views/605/admin.php`.
- `migrations_pg/pgMigration_Nj_605_001_Radar_Tables.php` — таблицы watches/items;
- `migrations_pg/pgMigration_Nj_605_002_Item_Source.php` — колонка `items.source`;
- `config/nj_module.php` — манифест каталога.
- `config/nj605_radar.php` — серверные настройки (`max_watches`).

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_605_watches` | наблюдения (code, user_id, title, query, region, enabled, last_run_at) |
| `nj_605_items`   | находки (watch_id, user_id, ext_id=sha1(url), title, url, customer, price, published_at, score, why, source=host площадки, status: new/taken/dismissed); уникальность (watch_id, ext_id) |

## API
```
POST /radar/api/collect            пиналка nj603 (токен)  → {ok, pushed}
POST /radar/api/items              приём находок воркера  → {ok, saved}
```
Внутренние эндпоинты — только с заголовком `X-Worker-Token` (= `WORKER_API_TOKEN`
на сайте); `NJ605_RADAR_SITE_TOKEN` — в окружении ВОРКЕРА (чем тот ходит на сайт),
та же строка кладётся в голову как `X-Worker-Token`. Для людей UI нет.

## Безопасность и конвенции
- ACL: админ-страница и админ-действия через `$this->can('nj605.admin')`.
- Чтение чужих данных — только `Service_Nj605_Radar::*` (владелец данных: модуль).
- CSRF на всех изменяющих POST-руках (create / delete / toggle / run_now).
- Дубли находок гаснутся `ON CONFLICT (watch_id, ext_id) DO NOTHING`.
- `run_now()`/`delete_watch()`/`set_enabled()` сверяют владение (`user_id`) или роль admin.
- `enabled` — BOOLEAN: PHP видит `'t'`/`'f'` (строку); в SQL — `enabled='t'`.

## env (в хвост .env.example, для воркера)
```
NJ605_RADAR_API_BASE=http://127.0.0.1/radar/api
NJ605_RADAR_SITE_TOKEN=CHANGE_ME
NJ605_RADAR_MAX_SITES=6
NJ605_RADAR_SEARCH_DELAY=2.0
NJ605_RADAR_QUERIES=            # опц., «|»-список доп. формулировок
NJ605_RADAR_SITE_DOMAINS=       # опц., «|»-список площадок для site:-поиска
```

## Ограничения
- Без воркера (`nj605.radar`) и без LLM-шлюза находки не появляются; UI это
  отражает статусом «сбор ещё не начинался / нет данных».
- Источники — только открытые (площадки закупок, поиск); сбора «слитых» баз нет.
