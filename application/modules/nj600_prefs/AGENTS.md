# AGENTS.md — памятка по модулю nj600_prefs

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/nj600_prefs/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Состояние | 15 | 3,5 КБ |
|   Ключевые классы | 52 | 0,7 КБ |
|   Точки вреза (НЕ удалять при рефакторинге) | 63 | 0,3 КБ |
|   Правила | 69 | 1,2 КБ |
|   Проверки модуля | 81 | 0,3 КБ |
|   Долг / идеи | 87 | 0,9 КБ |
|   2026-09-22 — миграция 002: LLM-колонки профиля | 97 | 0,3 КБ |
<!-- context-index:end -->
## Состояние

Собирает контекст пассивно (серверно): разделы страниц, фразы поиска, referrer/utm.
Сырьё `nj_600_events` — TTL-очистка; суммаризация `nj_600_profile` — компактная,
инкрементальный upsert (таблица не растёт). Воркер один — `nj600.compact` (ИИ-резюме).

**24.10.2026 — рекомендательный слой под надзором администратора** (запрос владельца:
«иконка у пользователя → его предпочтения и действия; чего не хватает для хорошей
рекомендательной системы»):

- `Service_Nj600_Reco` — ранкер `rank()` (чистая функция, без БД) + данные:
  источники `rule` (правила админа) → `covisit` (совместные посещения по
  `nj_600_profile`, SQL `?|` по JSONB) → `group` (та же группа каталога nj110).
  Исключаются посещённые разделы (кроме правил), `block`-правила, отклонённое
  владельцем за 60 дней, скрытое из каталога для аудитории (гость/пользователь).
- Таблицы: `nj_600_reco_rules` (kind boost/block, when_section, when_owner, href,
  weight, enabled), `nj_600_reco_log` (показ/клик/отказ, source, reason) — миграция 003.
- 25.10.2026 — `/nj600/admin/owner/<key>` стал **центром контроля**: блок
  «Что происходило» — объекты человека по всем модулям (контракт `recent`
  манифестов, `user_id` + необязательный `where`), токены по каналам и по дням
  (`nj_120_usage`), сбои задач (`nj_120_jobs` failed/cancelled), ссылки на
  модульные админки. Читает `Service_Nj600_Control` (только чтение, своих
  таблиц нет). Новый модуль с объектами пользователя попадает сюда, объявив
  `recent` в `config/nj_module.php`.
- Страницы: `/nj600/admin/owner/<key>` — карточка владельца (профиль, лента событий,
  задачи через `Service_Nj120_JobQueue::visitor_jobs`, входы через nj599,
  рекомендации с причинами, pin/block/notify); `/nj600/admin/reco` — правила,
  CTR по источникам/сервисам, журнал. Админ-просмотр показы НЕ логирует.
- API: `GET /prefs/api/recommend`, `GET /prefs/api/go?id=` (клик → редирект),
  `POST /prefs/api/dismiss` (CSRF). Виджет `Service_Nj600_Reco::widget()` стоит в
  кабинете `/dashboard` вместо статичного «Быстрого старта» (фолбэк — статика).
- Входы: `/nj595/admin` (иконка-мозг у каждого пользователя и в карточке),
  `/nj595/admin/activity` (ключи u:/g: кликабельны), таблица профилей `/nj600/admin`.
- MCP: `nj600_recommend` (admin).
- Следующий шаг: «Детектор намерений» — LLM-слой поверх `profile()` + `rank()`
  (объяснение причины человеческим языком); A/B весов источников по CTR.

## Ключевые классы

* `Service_Nj600_Prefs` — ensure_tables, track_page, track_search, profile,
  profile_full, owner_events, owner_days, prune, admin_stats, module_stats, mcp_*.
* `Service_Nj600_Control` — objects (Manifest::recent), tokens, failures, links — центр контроля.
* `Service_Nj600_Reco` — catalog, rank, covisit, rules_for, dismissed_for, for_owner,
  log_shown, click, dismiss, rules/save_rule/toggle_rule/delete_rule, stats,
  owner_log, widget, mcp_recommend.
* `Controller_Nj600_Admin` (/nj600/admin, owner, owner_action, reco, rule*),
  `Controller_Nj600_Api` (/prefs/api/profile|recommend|go|dismiss|profiles|save|status|cancel).

## Точки вреза (НЕ удалять при рефакторинге)

* `Erp100_Base::before()` — хук track_page (GET, главный запрос, try/catch);
* `Nj100_Api::action_search` — track_search;
* `Controller_Nj100_Dashboard::_reco()` — виджет рекомендаций (class_exists + try/catch).

## Правила

* Любое ДЕЙСТВИЕ по БД из хука — только try/catch: сбор не может ломать страницы.
* Капы в `config/nj600_prefs.php` не увеличивать без нужды владельца («БД не должна разбухать»).
* Таблицы/DDL: миграция 001 == ensure_tables; применённые миграции не редактировать.
* Персональные профили наружу — только по явному owner_key (MCP) или своему владельцу (API).
* Рекомендации: href — только внутренний путь (`clean_href`), внешние отбрасываются;
  правило вне каталога проходит только с собственным `title`. Чужой `log_id` в
  `/prefs/api/go` не срабатывает (строка должна принадлежать владельцу).
* Журнал показов — не продуктовая аналитика: один показ на пару владелец+href за 24 ч;
  чистка — реестр `cleanup` манифеста (90 дн.).

## Проверки модуля

* `bash .tools/php_check_all.sh application/modules/nj600_prefs`
* Ранкер: `N2J_ROOT=… node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj600_reco_stand.php nj600_prefs`
* Вьюхи: `… .tools/nj600_views_stand.php nj600_prefs system/classes/Kohana/View.php system/classes/Kohana/Arr.php system/classes/Arr.php`

## Долг / идеи

* «Детектор намерений»: LLM-слой поверх `Service_Nj600_Prefs::profile()`; события
  шины `Service_Erp208_EventBus` можно публиковать как `nj600.intent_*` для других модулей.
* Внешние поисковые фразы — только у тех, кто пришёл с поисковика (referrer сохраняется
  браузером); в chrome private может быть пусто — это норма.
* Просмотры публичных сервисов считает отдельный `nj516_metrics` (дневные агрегаты, без
  visitor ID). `nj600` оставляет за собой контекст, интересы и поисковые фразы;
  `track_page` не использовать как продуктовую аналитику.

## 2026-09-22 — миграция 002: LLM-колонки профиля

- Сервис читает/пишет `nj_600_profile.llm_summary / llm_at / llm_model`, миграция 001
  их не создавала — добавлена миграция 002 (ADD COLUMN IF NOT EXISTS ×3, идемпотентно).
