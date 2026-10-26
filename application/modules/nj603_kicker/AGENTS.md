# nj603_kicker — «Пиналка» — памятка для агентов

Один cron на все модули вместо десяти хронов.

## Как работает
1. Крон (каждые 10 минут) зовёт `--task=Nj603:Kick` (CLI) или `GET /nj603/kick/<NJ603_KICK_TOKEN>`.
2. Сервис сверяет `config/nj603_kicker.php` с таблицей состояния `nj_603_kicks`.
3. Созревшее расписание **выполняется сразу** (`Service_Nj603_Kicker::_fire()`) —
   прямым вызовом сервиса модуля. В очередь nj120 кладётся только реальная
   работа: `nj602.article`, `nj606.leads`, `nj605.radar`, `nj609.watchdog`,
   `nj610.inbox`, `nj613.digest` и т.д.
4. Python-воркер забирает эти задачи сам — посреднического канала `nj603.kick`
   больше нет (один хоп вместо двух).

## Защита от бесконечного выполнения
- Интервал считается от `last_run_at` (`nj_603_kicks`) — недозревшее молчит.
- Каждое расписание выполняется в собственном try/catch — ошибка одного не
  останавливает прочие.
- `_guard_channel()`: пока в очереди висит та же работа (канал в
  `pending`/`processing`), новое срабатывание тяжелого канала пропускается.

## Cron
```
*/10 * * * * php /path/to/index.php --task=Nj603:Kick
*/10 * * * * curl -fsS "https://n2j.ru/nj603/kick/<NJ603_KICK_TOKEN>"
```

Расписания (nj593 раз в 12 ч, nj602 раз в 2 ч и т.д.) — в `config/nj603_kicker.php`.

## Правила для агента

## Состояние
- **Служебный планировщик**: один cron на все модули вместо десяти хронов.
  `hidden: TRUE`, живёт в админке `/nj603/admin` и в cron.
- Крон каждые 10 минут пинает `--task=Nj603:Kick` (CLI) или
  `GET /nj603/kick/<NJ603_KICK_TOKEN>`; сервис сверяет расписания с таблицей
  состояния `nj_603_kicks` и **сразу выполняет** созревшее расписание
  (`_fire()` — прямой вызов сервиса модуля). Реальную работу в очередь
  ставит сам триггер (`nj602.article`, `nj606.leads`, …). Канала `nj603.kick`
  больше нет.

## Расписания — ТОЛЬКО в конфиге
`config/nj603_kicker.php` → `schedules[]`: `every` («30 minutes», «1 hour»…),
`url` (справочный путь результата), `payload` (аргументы триггера из `_fire()`),
`enabled`.
Текущие: nj597 notifications · nj593 trends · nj602 content · nj120 maintenance
· nj605 radar · nj606 leads · nj609 watchdog · nj610 inbox · nj612 touches ·
nj613 digest · nj639 qc · nj640 cameras.

## Защита от бесконечного выполнения
- Интервал считается от `last_run_at` — недозревшее расписание молчит.
- Реальное наслаивание исключает `_guard_channel($name)`: пока в очереди висит
  unfinished-задача канала (`pending`/`processing`), тяжёлый триггер пропускается.
- Каждый триггер в try/catch — ошибка одного не останавливает прочие; каналы
  ставятся с их собственными `max_attempts`/`timeout_sec`.

## Классы и файлы
- `Service_Nj603_Kicker` — schedules/interval_sec/run/poke_now/_fire/state_map/
  mcp_schedules/job_status + ensure_tables.
- `Controller_Nj603_Kick`  — HTTP-пинок `/nj603/kick/<token>`.
- `Controller_Nj603_Admin` — админка (`/nj603/admin`).
- `Task_Nj603_Kick`        — CLI-задача Minion.
- `migrations_pg/pgMigration_Nj_603_001_Kicker_State.php`.
- `config/nj603_kicker.php` (расписания), `config/nj_module.php`, `config/mcp.php`.

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_603_kicks` | состояние расписаний (name UNIQUE, last_run_at, last_status, last_error) |

## API / точки
```
GET /nj603/kick/<token>   HTTP-пинок для cron (токен NJ603_KICK_TOKEN)
/nj603/admin              админка (ACL nj603.admin)
CLI  --task=Nj603:Kick
MCP  инструмент schedules (config/mcp.php)
```

## Конвенции
- Новое расписание = +1 ключ в `config/nj603_kicker.php` + ветка в `_fire()`
  (если триггера ещё нет).
- `url` в расписании — справочный путь результата (куда смотреть), устаревший
  worker-хоп не маршрутизирует.
- Токен HTTP-пинка — env `NJ603_KICK_TOKEN`; см. .env.example.

## Ограничения
- Без cron/пинка расписания не исполняются (планировщик — pull-модель).
