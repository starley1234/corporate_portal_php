# NJ-606 · Поток заказчиков (nj606_leads)

## Состояние
- **Сервис** «Поток заказчиков» (`/leads`): пользователь описывает, какие компании
  ему нужны (отрасль/что производят + регион) → платформа находит производителей
  по открытым источникам → лента компаний с сайтами и зацепками.
- **Beta** (`badge: beta`). Сбор потока **платный**: списание кредита через
  `Service_Nj601_Billing::charge('nj606_leads', ...)`.
- Из лида в один клик: профиль `Service_Nj591_Company::create` (nj591) и
  холодное письмо (nj592).
- **08.10.2026 — гостевой экран переведён на общую плашку входа** (решение владельца): вместо
  строки «Сервис для зарегистрированных — войдите» гость видит `Gate::wall('leads')` — общий
  partial `application/views/partials/auth_wall.php` (`application/classes/Gate.php`): что даёт
  сервис, зачем аккаунт и ОДНА кнопка входа с `return_url=leads`. Состав полей и поведение для
  вошедших не менялись.

## Конвейер (не в HTTP)
- Пиналка nj603 → `POST /leads/api/collect` (авторизация воркера — заголовок
  `X-Worker-Token` = `WORKER_API_TOKEN`) → задачи nj120 канала **`nj606.leads`** →
  воркер (`workers/n2j_workers/handlers/nj606_leads.py`) → компании →
  `POST /leads/api/companies`.
- Первый сбор ставится сразу при создании потока (кредит уже списан);
  дальше периодичность держит nj603.

## Классы и файлы
- `Service_Nj606_Leads` — create_flow (биллинг), feed, очередь, admin, API воркера.
- `Controller_Nj606_Leads` — страница `/leads`.
- `Controller_Nj606_Api`   — API воркера (`collect`, `companies`).
- `Controller_Nj606_Admin` — `/leads/admin`.
- `views/606/index.php`, `views/606/card.php`, `views/606/admin.php`.
- `migrations_pg/pgMigration_Nj_606_001_Leads_Tables.php`.
- `config/nj_module.php` — манифест каталога.

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_606_flows` | потоки сбора (code, user_id, title, industry, region, last_run_at) |
| `nj_606_leads` | компании-находки (flow_id, user_id, ext_id, title, url, hook, status: new/taken/dismissed); уникальность (flow_id, ext_id) |

## API
```
POST /leads/api/collect      пиналка nj603 (токен) → {ok, pushed}
POST /leads/api/companies    приём компаний от воркера → {ok, saved}
```
Внутренние эндпоинты — только по токену `NJ606_LEADS_SITE_TOKEN`.

## Безопасность и конвенции
- Биллинг только через nj601 (`create_flow` списывает кредит, при ошибке — не создаёт поток).
- ACL: админ-действия через `$this->can('nj606.admin')`; владение потоком — по `user_id`.
- Дубли компаний гаснутся `ON CONFLICT (flow_id, ext_id) DO NOTHING`.

## env (в хвост .env.example, для воркера)
```
NJ606_LEADS_API_BASE=http://127.0.0.1/leads/api
NJ606_LEADS_SITE_TOKEN=CHANGE_ME
NJ606_LEADS_MAX_SITES=6
NJ606_LEADS_SEARCH_DELAY=2.0
```

## Ограничения
- Без воркера `nj606.leads` лента не наполняется.
- Источники — только открытые; никаких «слитых» баз контактов.
