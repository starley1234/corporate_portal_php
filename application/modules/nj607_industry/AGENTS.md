# NJ-607 · Карта отрасли (nj607_industry)

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/nj607_industry/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Состояние | 17 | 1,2 КБ |
|   Конвейер (не в HTTP) | 31 | 0,7 КБ |
|   Классы и файлы | 40 | 0,6 КБ |
|   Таблицы | 49 | 0,3 КБ |
|   API | 54 | 0,3 КБ |
|   Безопасность и конвенции | 62 | 0,5 КБ |
|   env (в хвост .env.example, для воркера) | 68 | 0,2 КБ |
|   Ограничения | 75 | 0,2 КБ |
|   Онтология (26.09.2026) — обязательные правила | 79 | 1,3 КБ |
<!-- context-index:end -->
## Состояние
- **07.10.2026:** статусы отчётов в списке, карточке, сущностях и админке
  показываются по i18n (`nj607.industry.report.status.*`); значения БД/API
  (`new`, `running`, `done`, `error`, `closed`) остаются машинными.
  Стенд `.tools/nj607_industry_stand.php` проверяет точные подписи и fallback.
- **Сервис** «Карта отрасли» (`/industry`): два режима в одном модуле:
  - **авто-карта** (`kind=self`) — пользователь задаёт отрасль+регион, воркер
    автоматически собирает отчёт: игроки с сайтами, что и как производят,
    сегменты, цены (где видны), каналы, возможности входа. Платно
    (cost nj607_industry через nj601).
  - **разведка под запрос** (`kind=order`) — ручная услуга владельца: заказ →
    уведомление админу → закрытие с готовым отчётом.
- Отчёт показывается на `/industry/<code>` (Markdown → HTML).

## Конвейер (не в HTTP)
- Задача nj120 канала **`nj607.industry`** → воркер
  (`workers/n2j_workers/handlers/nj607_industry.py`) → прогресс/результат/фейл
  через `/industry/api/{progress,result,fail}` (авторизация воркера — заголовок
  `X-Worker-Token` = `WORKER_API_TOKEN`).
- ⚠️ Канал `nj607.industry` **отсутствует в `channels_ui`** (config/nj120_jobs.php):
  в `/tasks` задача индустрии показывается без подписи. Добавить пункт
  `'nj607.industry' => ['label' => 'Карта отрасли (nj607)', 'url' => '/industry']`.

## Классы и файлы
- `Service_Nj607_Industry` — create_self/create_order, очередь, status-машина, admin, API воркера.
- `Controller_Nj607_Industry` — `/industry` (формы + мои отчёты) и `/industry/<code>`.
- `Controller_Nj607_Api`      — API воркера (`progress`, `result`, `fail`).
- `Controller_Nj607_Admin`    — `/nj607/admin` (заказы и авто-сборы).
- `views/nj607/{index,view,card,admin}.php`.
- `migrations_pg/pgMigration_Nj_607_001_Reports_Tables.php`.
- `config/nj_module.php` — манифест каталога.

## Таблицы
| Таблица | Назначение |
|---|---|
| `nj_607_reports` | отчёты (code, user_id, visitor_id, kind=self/order, industry, region, contact, status new/running/done/error/closed, progress, report_md, job_id, model_used) |

## API
```
POST /industry/api/progress/<code>   воркер: смена фазы (токен)
POST /industry/api/result/<code>     воркер: готовый Markdown + модель
POST /industry/api/fail/<code>       воркер: ошибка
```
Только с заголовком `X-Worker-Token` (= `WORKER_API_TOKEN` на сайте), без UI.

## Безопасность и конвенции
- Биллинг авто-карты — через nj601 `charge()` до постановки задачи.
- ACL: `/nj607/admin` и админ-действия через `$this->can('nj607.admin')`.
- Статус-машина: `progress` только из `running`-допустимых состояний;
  `result` не перетирает done/closed (защита от регресса статуса).

## env (в хвост .env.example, для воркера)
```
NJ607_INDUSTRY_API_BASE=http://127.0.0.1/industry/api
NJ607_INDUSTRY_SITE_TOKEN=CHANGE_ME
NJ607_INDUSTRY_MAX_SITES=10
```

## Ограничения
- Без воркера `nj607.industry` авто-отчёты не появляются; ручной заказ ждёт владельца.
- Сбор данных — только открытые источники.

## Онтология (26.09.2026) — обязательные правила

- Сущности отчёта разбирает `Service_Nj607_Ontology` (детерминированно, без ИИ):
  `kind` = company|region|segment, ключ — `kind + slug` (одна сущность на все
  отчёты). Разбор идемпотентен: повторный проход не дублирует записи.
- Связи в `nj_607_links` — только то, что РЕАЛЬНО нашлось в нашей базе
  (домен/название/поиск). Выдуманных ссылок быть не должно: пусто — так и пишем.
- Разбор не должен ломать отчёт: любые ошибки — в журнал (`Log::WARNING`), отчёт
  и задача nj120 живут своей жизнью. Вызов — из `api_result()` и лениво при
  просмотре отчёта (`action_view`).
- Поля отрасли/региона — **без автозаполнения браузера** (`autocomplete="off"`),
  подсказки только из нашей базы (`Service_Nj607_Ontology::popular()`).
- Миграция 002 обязательна: без неё онтология молча пропускает разбор.
