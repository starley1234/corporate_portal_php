# NJ-609 · Сторож контрагентов (nj609_watchdog)

## Состояние
- **Сервис** «Сторож контрагентов» (`/watchdog`): пользователь добавляет
  контрагентов (компания / ИНН / заметка), платформа периодически проверяет
  риски: суды, банкротство, смены в ЕГРЮЛ, плохие новости — и приносит только
  реальные сигналы (фильтр шума), а не «всё подряд».
- **Beta**, бесплатно (cost 0 в nj601). Сбор — раз в 7 дней на субъекта.
- Поддерживается несколькими контрагентами на пользователя.
- **08.10.2026 — гостевой экран переведён на общую плашку входа** (решение владельца): вместо
  строки «Сервис для зарегистрированных — войдите» гость видит `Gate::wall('watchdog')` — общий
  partial `application/views/partials/auth_wall.php` (`application/classes/Gate.php`): что даёт
  сервис, зачем аккаунт и ОДНА кнопка входа с `return_url=watchdog`. Состав полей и поведение
  для вошедших не менялись.

## Конвейер (не в HTTP)
- Пиналка nj603 → `POST /watchdog/api/collect` (авторизация воркера — заголовок
  `X-Worker-Token` = `WORKER_API_TOKEN`) → задачи nj120 канала **`nj609.watchdog`** →
  воркер (`workers/n2j_workers/handlers/nj609_watchdog.py`) → события →
  `POST /watchdog/api/events`.

## Классы и файлы
- `Service_Nj609_Watchdog` — CRUD субъектов/событий, feed, queue, admin, API воркера.
- `Controller_Nj609_Watchdog` — страница `/watchdog` (мои контрагенты + лента событий).
- `Controller_Nj609_Api`      — API воркера (`collect`, `events`).
- `Controller_Nj609_Admin`    — `/watchdog/admin`.
- `views/609/{index,card,admin}.php`.
- `migrations_pg/pgMigration_Nj_609_001_Watchdog_Tables.php`.
- `config/nj_module.php` — манифест каталога.

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_609_subjects` | наблюдаемые контрагенты (code, user_id, company, inn, note, last_run_at) |
| `nj_609_events`   | сигналы риска (subject_id, user_id, ext_id, kind, title, url, seen); уникальность (subject_id, ext_id) |

## API
```
POST /watchdog/api/collect   пиналка nj603 (токен) → {ok, pushed}
POST /watchdog/api/events    приём событий от воркера → {ok, saved}
```
Только по токену `NJ609_WATCHDOG_SITE_TOKEN`.

## Безопасность и конвенции
- ACL: админ-страница/действия через `$this->can('nj609.admin')`; владение — по `user_id`.
- Дубли событий гаснутся `ON CONFLICT (subject_id, ext_id) DO NOTHING`.
- `run_now()` проверяет владельца или роль admin.

## env (в хвост .env.example, для воркера)
```
NJ609_WATCHDOG_API_BASE=http://127.0.0.1/watchdog/api
NJ609_WATCHDOG_SITE_TOKEN=CHANGE_ME
```

## Ограничения
- Без воркера `nj609.watchdog` события не появляются.
- Beta: бесплатно; лимиты/mонетизация — по мере запуска платных тарифов.
